<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Cart;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Storage\AbuseCounterStoreInterface;

/**
 * Throttles add-to-cart / cart-update operations per IP.
 *
 * Why this matters:
 *  - **Inventory locking** — bots add limited-stock items to carts en masse to
 *    block legitimate customers (concert tickets, drops, console launches).
 *  - **Catalog probing** — scrapers add and remove products to map out stock and
 *    pricing changes.
 *
 * The middleware counts add-to-cart operations per (IP) and denies above the hard
 * threshold. Like other rate-limit middleware, the soft threshold emits a signal
 * for fraud scoring without blocking — a legitimate customer adding 6 items in
 * succession is normal, 60 in 60 seconds is not.
 *
 * Cart counters intentionally live in a separate keyspace from the checkout
 * counters so a bot stuck in "add → remove → add" loops doesn't burn the checkout
 * budget too.
 */
final class CartVelocityMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly AbuseCounterStoreInterface $store,
        private readonly int $softThreshold = 20,
        private readonly int $hardThreshold = 60,
        private readonly int $windowSeconds = 60,
        private readonly int $weightSoft = 10,
        private readonly int $weightHard = 80,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        if ($context->ip === '') {
            return $next($context);
        }
        $key = 'cart:ip:' . $context->ip;
        $count = $this->store->hit($key, $this->windowSeconds);

        if ($count >= $this->hardThreshold) {
            return Decision::deny(
                'Cart activity rate exceeded.',
                [...$context->signals, new Signal(
                    rule: 'cart_velocity',
                    weight: $this->weightHard,
                    reason: 'Cart add/update rate exceeded hard threshold.',
                    meta: ['hits' => $count, 'limit' => $this->hardThreshold, 'window' => $this->windowSeconds],
                )],
                $context->score + $this->weightHard,
            );
        }

        if ($count >= $this->softThreshold) {
            $context = $context->withSignal(new Signal(
                rule: 'cart_velocity',
                weight: $this->weightSoft,
                reason: 'Elevated cart activity rate.',
                meta: ['hits' => $count, 'limit' => $this->softThreshold, 'window' => $this->windowSeconds],
            ));
        }

        return $next($context);
    }
}
