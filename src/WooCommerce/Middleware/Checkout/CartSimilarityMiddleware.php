<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Middleware\Checkout;

use NiyiGuard\WooCommerce\Detection\Decision;
use NiyiGuard\WooCommerce\Detection\DetectionContext;
use NiyiGuard\WooCommerce\Detection\Signal;
use NiyiGuard\WooCommerce\Middleware\WcMiddlewareInterface;
use NiyiGuard\WooCommerce\Services\CartFingerprinter;
use NiyiGuard\WooCommerce\Storage\AbuseCounterStoreInterface;

/**
 * Detects the "same cart submitted 5 times" abuse pattern.
 *
 * Bot operators commonly enumerate stolen cards by submitting the *same* checkout
 * shape with a different payment method each time. The cart contents stay identical
 * because the operator hasn't bothered to vary them.
 *
 * The middleware computes a fingerprint of the cart contents via
 * {@see CartFingerprinter}, then increments a per-IP `seen()` counter against that
 * fingerprint over a rolling window. Beyond {@see $threshold} hits, we add a
 * high-weight signal (and short-circuit if past the deny threshold).
 *
 * The fingerprint is content-only — quantity / product / variation — so customers
 * who legitimately retry checkout because their card was declined the first time
 * (same cart, different card) will trip the soft signal but not the hard one. Real
 * abuse cycles dozens of attempts.
 */
final class CartSimilarityMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly CartFingerprinter $fingerprinter,
        private readonly AbuseCounterStoreInterface $store,
        private readonly int $softThreshold = 3,
        private readonly int $hardThreshold = 6,
        private readonly int $windowSeconds = 300,
        private readonly int $weightSoft = 25,
        private readonly int $weightHard = 60,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        $cartItems = $context->get('cart_items');
        if (!is_iterable($cartItems)) {
            return $next($context);
        }

        $fingerprint = $this->fingerprinter->fingerprint($cartItems);
        if ($fingerprint === '') {
            return $next($context);
        }

        $key = 'ckcart:ip:' . $context->ip;
        $repeats = $this->store->seen($key, $fingerprint, $this->windowSeconds);

        if ($repeats >= $this->hardThreshold) {
            return Decision::deny(
                'Identical cart submitted too many times.',
                [...$context->signals, new Signal(
                    rule: 'cart_similarity',
                    weight: $this->weightHard,
                    reason: 'Identical cart fingerprint submitted repeatedly.',
                    meta: ['fingerprint' => $fingerprint, 'repeats' => $repeats, 'window' => $this->windowSeconds],
                )],
                $context->score + $this->weightHard,
            );
        }

        if ($repeats >= $this->softThreshold) {
            $context = $context->withSignal(new Signal(
                rule: 'cart_similarity',
                weight: $this->weightSoft,
                reason: 'Identical cart fingerprint seen multiple times.',
                meta: ['fingerprint' => $fingerprint, 'repeats' => $repeats, 'window' => $this->windowSeconds],
            ));
        }

        return $next($context);
    }
}
