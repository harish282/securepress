<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Middleware\Checkout;

use NiyiGuard\WooCommerce\Detection\Decision;
use NiyiGuard\WooCommerce\Detection\DetectionContext;
use NiyiGuard\WooCommerce\Detection\Signal;
use NiyiGuard\WooCommerce\Middleware\WcMiddlewareInterface;

/**
 * Catches **behavioural** anomalies at checkout — *not* bot-shape signals.
 *
 * Bot-shape signals (honeypot, scanner UA, impossible timing) live in
 * {@see BotCheckoutMiddleware} so the two concerns can be tuned independently.
 * This middleware focuses on what the *order itself* looks like:
 *
 *  - **Billing / shipping country mismatch with `differ_shipping = false`.** A
 *    common fraud pattern ships a brand-new billing address to a mule's
 *    shipping country and "forgets" to tick the differ-shipping box. When the
 *    two countries don't match but the form claims they should, that's worth
 *    flagging.
 *
 * Additional behavioural rules (high-value order, address pattern mismatch,
 * card-number-in-name) can be added here without polluting the bot middleware.
 *
 * This middleware emits *signals* only — it never short-circuits. Final
 * accept/challenge/deny is decided by {@see FraudScoreMiddleware} at the tail
 * of the pipeline.
 */
final class CheckoutBehaviorMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly int $countryMismatchWeight = 20,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
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
