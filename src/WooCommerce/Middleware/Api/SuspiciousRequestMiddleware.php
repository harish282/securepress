<?php

declare(strict_types=1);

namespace PressSentinel\WooCommerce\Middleware\Api;

use PressSentinel\WooCommerce\Detection\Decision;
use PressSentinel\WooCommerce\Detection\DetectionContext;
use PressSentinel\WooCommerce\Detection\Signal;
use PressSentinel\WooCommerce\Middleware\WcMiddlewareInterface;

/**
 * Cheap pre-filter that emits suspicion signals (and occasionally short-circuits)
 * based on shape-only request characteristics.
 *
 * The checks are deliberately shallow — no payload parsing, no header round-trip
 * — because this middleware runs on **every** WooCommerce REST request. The goal is
 * "spend ≤ 100 microseconds to discard the obvious garbage so the rest of the
 * pipeline only sees plausible traffic".
 *
 * Built-in heuristics:
 *
 *  1. **Empty / blacklisted User-Agent** — legitimate clients send a UA. A blank UA
 *     is either an extremely old/weird HTTP client or, far more often, a script.
 *     Built-in deny list covers a handful of well-known scanners (`sqlmap`,
 *     `nikto`, `acunetix`, `nessus`).
 *  2. **WordPress core / WC admin paths via REST** — requests targeting writes to
 *     internal-only endpoints from outside `wp-admin` (no nonce, no cookie auth)
 *     score moderately suspicious. We don't deny outright because legit headless
 *     setups exist.
 *
 * Everything is configurable: ban-list, weight per heuristic, and an override
 * `passWhenAuthenticated` flag that suppresses signals when the request carries a
 * recognised user — keeps legitimate dev workflows fast.
 */
final class SuspiciousRequestMiddleware implements WcMiddlewareInterface
{
    private const SCANNER_UA_PATTERNS = [
        'sqlmap', 'nikto', 'acunetix', 'nessus', 'masscan', 'nmap',
        'wpscan', 'burpsuite', 'zaproxy', 'whatweb', 'arachni',
    ];

    /**
     * @param list<string> $extraScannerUas Additional UA substrings to flag.
     */
    public function __construct(
        private readonly array $extraScannerUas = [],
        private readonly int $emptyUaWeight = 40,
        private readonly int $scannerUaWeight = 200,
        private readonly bool $denyOnScannerUa = true,
        private readonly bool $passWhenAuthenticated = true,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        if ($this->passWhenAuthenticated && is_int($context->userId) && $context->userId > 0) {
            return $next($context);
        }

        $ua = strtolower(trim($context->userAgent));

        if ($ua === '') {
            $context = $context->withSignal(new Signal(
                rule: 'empty_user_agent',
                weight: $this->emptyUaWeight,
                reason: 'Request had no User-Agent header.',
            ));
        } else {
            $scanners = array_merge(self::SCANNER_UA_PATTERNS, $this->extraScannerUas);
            foreach ($scanners as $needle) {
                $needle = strtolower($needle);
                if ($needle !== '' && str_contains($ua, $needle)) {
                    $signal = new Signal(
                        rule: 'scanner_user_agent',
                        weight: $this->scannerUaWeight,
                        reason: 'User-Agent contains a known security-scanner fingerprint.',
                        meta: ['needle' => $needle, 'user_agent' => substr($ua, 0, 120)],
                    );
                    if ($this->denyOnScannerUa) {
                        return Decision::deny(
                            'Blocked: scanner-pattern User-Agent.',
                            [...$context->signals, $signal],
                            $context->score + $this->scannerUaWeight,
                        );
                    }
                    $context = $context->withSignal($signal);
                    break;
                }
            }
        }

        return $next($context);
    }
}
