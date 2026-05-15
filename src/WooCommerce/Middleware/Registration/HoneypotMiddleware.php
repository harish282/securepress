<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Registration;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;

/**
 * Honeypot field check — short-circuits with `DENY` when a hidden form field has
 * been touched.
 *
 * Two ways the kernel signals "honeypot tripped":
 *
 *  - `honeypot_filled` (bool): true when the hidden field was non-empty. Real users
 *    can't see the field (it's CSS-hidden and `aria-hidden`) so any value is bot.
 *  - `min_age_seconds` violated: rendered-too-recently. A separate timing token is
 *    inspected by the {@see \SecurePress\WooCommerce\Middleware\Checkout\CheckoutBehaviorMiddleware}
 *    on the checkout side; on the registration side we keep the rule here so the
 *    registration pipeline stays self-contained.
 *
 * Honeypot is the gold-standard for **low-false-positive** bot defense: any form
 * submission with the hidden field filled is unambiguously a bot, so we feel safe
 * issuing a hard deny rather than a "challenge". Weighting it would be wasted CPU.
 */
final class HoneypotMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly string $fieldName = 'securepress_hp',
        private readonly int $minSecondsToSubmit = 0,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        $value = (string) $context->get($this->fieldName, '');
        if ($value !== '') {
            return Decision::deny(
                'Honeypot field was filled.',
                [...$context->signals, new Signal(
                    rule: 'honeypot_filled',
                    weight: 200,
                    reason: 'Hidden honeypot field was submitted with a value.',
                    meta: ['field' => $this->fieldName],
                )],
                $context->score + 200,
            );
        }

        $elapsed = $context->get('form_elapsed_seconds');
        if (
            $this->minSecondsToSubmit > 0
            && is_int($elapsed)
            && $elapsed >= 0
            && $elapsed < $this->minSecondsToSubmit
        ) {
            return Decision::deny(
                'Form submitted too quickly to be human.',
                [...$context->signals, new Signal(
                    rule: 'honeypot_timing',
                    weight: 150,
                    reason: sprintf('Form submitted in %ds (under %ds floor).', $elapsed, $this->minSecondsToSubmit),
                    meta: ['elapsed_seconds' => $elapsed, 'floor_seconds' => $this->minSecondsToSubmit],
                )],
                $context->score + 150,
            );
        }

        return $next($context);
    }
}
