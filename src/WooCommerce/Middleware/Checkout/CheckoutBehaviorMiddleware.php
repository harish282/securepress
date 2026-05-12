<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Checkout;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Services\BehaviorClock;

/**
 * Catches "impossibly fast" checkout submissions, plus billing/shipping anomalies.
 *
 * Two checks:
 *
 *  1. **Impossible timing** — if the kernel marked a clock token when the checkout
 *     page rendered and the submission arrives within {@see $minSecondsToSubmit}
 *     seconds, that's almost certainly automation. We emit a high-weight signal
 *     (not an outright deny — a real customer with autofill *can* be quick).
 *
 *  2. **Billing/shipping country mismatch with `differ_shipping = false`** — A
 *     surprising number of fraud patterns ship a brand-new billing address to a
 *     mule's shipping country and "forget" to tick the differ-shipping box. When the
 *     two countries don't match but the form claims they should, that's worth
 *     flagging.
 *
 * Configurable bits: timing threshold, weights for each sub-rule. Operators who
 * sell to a global audience can lower the country-mismatch weight if it produces
 * false positives.
 */
final class CheckoutBehaviorMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly BehaviorClock $clock,
        private readonly int $minSecondsToSubmit = 3,
        private readonly int $timingWeight = 50,
        private readonly int $countryMismatchWeight = 20,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        $token = (string) $context->get('clock_token', '');
        if ($token !== '') {
            $elapsed = $this->clock->elapsedSeconds($token);
            if ($elapsed !== null && $elapsed < $this->minSecondsToSubmit) {
                $context = $context->withSignal(new Signal(
                    rule: 'impossible_timing',
                    weight: $this->timingWeight,
                    reason: sprintf('Checkout submitted in %ds (under %ds floor).', $elapsed, $this->minSecondsToSubmit),
                    meta: ['elapsed_seconds' => $elapsed, 'floor_seconds' => $this->minSecondsToSubmit],
                ));
            }
        }

        $billingCountry = strtoupper((string) $context->get('billing_country', ''));
        $shippingCountry = strtoupper((string) $context->get('shipping_country', ''));
        $useShipping = (bool) $context->get('use_shipping', false);

        if (
            !$useShipping
            && $billingCountry !== ''
            && $shippingCountry !== ''
            && $billingCountry !== $shippingCountry
        ) {
            $context = $context->withSignal(new Signal(
                rule: 'country_mismatch',
                weight: $this->countryMismatchWeight,
                reason: 'Billing and shipping countries differ without explicit "ship to different address".',
                meta: ['billing' => $billingCountry, 'shipping' => $shippingCountry],
            ));
        }

        return $next($context);
    }
}
