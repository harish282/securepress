<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Checkout;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Services\BehaviorClock;

/**
 * Bot-vs-human discrimination for checkout submissions.
 *
 * Complementary to {@see CheckoutBehaviorMiddleware} (which focuses on behavioural
 * heuristics like billing/shipping country mismatch) and to
 * {@see VelocityDetectionMiddleware} (which focuses on volume). This middleware
 * concentrates the **shape-of-the-request** bot signals — the ones that an
 * automated client betrays before it has even submitted anything semantically
 * meaningful.
 *
 * Signals it checks, in order of cheapness:
 *
 *  1. **Honeypot field filled.** WooCommerce checkout has a hidden honeypot
 *     field (rendered by `WooCommerceModule::onRenderCheckoutToken()`). Real
 *     browsers can't see it; any value means automation. Hard deny.
 *
 *  2. **Scanner User-Agent.** UA contains a known scanner fingerprint
 *     (`sqlmap`, `nikto`, `wpscan`, etc.). Hard deny.
 *
 *  3. **Empty User-Agent.** No UA header at all → soft signal. Some legitimate
 *     clients (intra-network apps, privacy tooling) strip the header, so we
 *     contribute weight rather than block outright.
 *
 *  4. **Fast submission (timing).** Optional (`min_seconds_to_submit` &gt; 0).
 *     Default action is **report only**: audit log + fraud-score signal, no
 *     instant block — safe for mobile, autofill, password managers, and Shop Pay.
 *     Admins may switch to **block** to reject the checkout immediately.
 *
 *  5. **Missing Referer.** Real browsers send one for same-origin POSTs (unless
 *     the site sets a strict Referrer-Policy that strips it — increasingly
 *     common, so we treat this as a *soft* signal that contributes weight, not
 *     a hard fail).
 *
 * Why this isn't merged into `CheckoutBehaviorMiddleware`:
 *  - The two have **different decision profiles**. The behaviour middleware
 *    almost always emits *signals* and lets the fraud-score tail decide. The
 *    bot middleware **short-circuits with `DENY`** for unambiguous bot tells
 *    (honeypot filled, scanner UA, submit-in-zero-seconds). Mixing both
 *    semantics in one file would make the rules harder to tune independently.
 *  - The honeypot path is a textbook **zero-false-positive** rule. Carving it
 *    into its own middleware makes it impossible to accidentally tune down
 *    when adjusting timing weights.
 *
 * Configuration: all weights are constructor-injectable; the DI factory in
 * `Plugin.php` reads them from `woocommerce_protection.checkout.bot.*`.
 */
final class BotCheckoutMiddleware implements WcMiddlewareInterface
{
    /** @deprecated Use {@see self::TIMING_REPORT}. Legacy option value `signal`. */
    public const TIMING_SIGNAL = 'report';

    /** @deprecated Use {@see self::TIMING_BLOCK}. Legacy option value `deny`. */
    public const TIMING_DENY = 'block';

    /** Log suspicious timing and add fraud score; do not block checkout by itself. */
    public const TIMING_REPORT = 'report';

    /** Immediately reject checkout when timing is suspicious. */
    public const TIMING_BLOCK = 'block';

    /**
     * Default substrings flagged as scanner-pattern User-Agents. Kept inline so
     * the middleware works standalone in tests; production wires in additional
     * entries via the `$extraScannerUas` constructor argument.
     *
     * @var list<string>
     */
    private const SCANNER_UA_PATTERNS = [
        'sqlmap', 'nikto', 'acunetix', 'nessus', 'masscan', 'nmap',
        'wpscan', 'burpsuite', 'zaproxy', 'whatweb', 'arachni',
        'curl/', 'wget/', 'python-requests', 'go-http-client',
    ];

    /**
     * @param list<string> $extraScannerUas Additional UA substrings to flag.
     */
    public function __construct(
        private readonly BehaviorClock $clock,
        private readonly string $honeypotField = 'securepress_hp',
        private readonly int $minSecondsToSubmit = 0,
        private readonly string $timingAction = self::TIMING_REPORT,
        private readonly array $extraScannerUas = [],
        private readonly int $weightHoneypot = 200,
        private readonly int $weightScannerUa = 200,
        private readonly int $weightImpossibleTiming = 25,
        private readonly int $weightEmptyUa = 35,
        private readonly int $weightMissingReferer = 15,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        // 1) Honeypot — unambiguous bot tell. Short-circuit immediately so we
        // don't waste cycles on the other checks.
        $honeypotValue = (string) $context->get($this->honeypotField, '');
        if ($honeypotValue !== '') {
            return Decision::deny(
                'Honeypot field was filled.',
                [...$context->signals, new Signal(
                    rule: 'bot_honeypot',
                    weight: $this->weightHoneypot,
                    reason: 'Hidden honeypot field was submitted with a value.',
                    meta: ['field' => $this->honeypotField],
                )],
                $context->score + $this->weightHoneypot,
            );
        }

        // 2) Scanner UA — strong, high-confidence deny.
        $ua = strtolower(trim($context->userAgent));
        if ($ua !== '') {
            $needle = $this->matchScannerNeedle($ua);
            if ($needle !== null) {
                return Decision::deny(
                    'User-Agent matches a known scanner fingerprint.',
                    [...$context->signals, new Signal(
                        rule: 'bot_scanner_ua',
                        weight: $this->weightScannerUa,
                        reason: 'User-Agent contains a known scanner / automation fingerprint.',
                        meta: ['needle' => $needle, 'user_agent' => substr($ua, 0, 120)],
                    )],
                    $context->score + $this->weightScannerUa,
                );
            }
        } else {
            // 3) Empty UA — softer signal, contributes weight only.
            $context = $context->withSignal(new Signal(
                rule: 'bot_empty_user_agent',
                weight: $this->weightEmptyUa,
                reason: 'Checkout submitted without a User-Agent header.',
            ));
        }

        // 4) Checkout timing — enabled when floor > 0. Default: report (log + score).
        $token = (string) $context->get('clock_token', '');
        if ($token !== '' && $this->minSecondsToSubmit > 0) {
            $elapsed = $this->clock->elapsedSeconds($token);
            if ($elapsed !== null && $elapsed < $this->minSecondsToSubmit) {
                $signal = new Signal(
                    rule: 'bot_fast_checkout_timing',
                    weight: $this->weightImpossibleTiming,
                    reason: sprintf(
                        'Suspicious checkout timing: submitted in %ds (under %ds floor).',
                        $elapsed,
                        $this->minSecondsToSubmit
                    ),
                    meta: [
                        'elapsed_seconds' => $elapsed,
                        'floor_seconds' => $this->minSecondsToSubmit,
                        'timing_action' => $this->normalizedTimingAction(),
                    ],
                );

                if ($this->normalizedTimingAction() === self::TIMING_BLOCK) {
                    return Decision::deny(
                        $signal->reason,
                        [...$context->signals, $signal],
                        $context->score + $this->weightImpossibleTiming,
                    );
                }

                $context = $context->withSignal($signal);
            }
        }

        // 5) Missing Referer — soft. Permissive because of `Referrer-Policy:
        // no-referrer` setups (we ourselves ship a fairly strict policy by
        // default in the Security Headers module).
        if (trim($context->referer) === '') {
            $context = $context->withSignal(new Signal(
                rule: 'bot_missing_referer',
                weight: $this->weightMissingReferer,
                reason: 'Checkout POST arrived without a Referer header.',
            ));
        }

        return $next($context);
    }

    private function normalizedTimingAction(): string
    {
        $action = strtolower(trim($this->timingAction));

        return $action === self::TIMING_BLOCK ? self::TIMING_BLOCK : self::TIMING_REPORT;
    }

    private function matchScannerNeedle(string $ua): ?string
    {
        foreach (array_merge(self::SCANNER_UA_PATTERNS, $this->extraScannerUas) as $needle) {
            $needle = strtolower($needle);
            if ($needle !== '' && str_contains($ua, $needle)) {
                return $needle;
            }
        }

        return null;
    }
}
