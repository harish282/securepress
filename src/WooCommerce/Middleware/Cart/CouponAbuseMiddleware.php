<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Middleware\Cart;

use NiyiGuard\WooCommerce\Detection\Decision;
use NiyiGuard\WooCommerce\Detection\DetectionContext;
use NiyiGuard\WooCommerce\Detection\Signal;
use NiyiGuard\WooCommerce\Middleware\WcMiddlewareInterface;
use NiyiGuard\WooCommerce\Storage\AbuseCounterStoreInterface;

/**
 * Detects coupon-brute-forcing.
 *
 * Pattern: a script enumerates `SAVE10`, `SAVE15`, `SAVE20`, … against the
 * checkout coupon field until something sticks. The same IP / session burns
 * through dozens of failed attempts in seconds.
 *
 * Strategy:
 *  - On each invocation we read the failure count from the store (the kernel feeds
 *    the count by listening to `woocommerce_coupon_error` and calling `hit()` on
 *    the same key).
 *  - When the count is above the soft threshold we emit a signal; above the hard
 *    threshold we deny outright.
 *  - The key namespace is `coupon:ip:<ip>` so the store entry expires on its own
 *    once the bot gives up.
 *
 * The middleware itself doesn't increment the counter — that happens in the
 * kernel's failure listener, which has access to the failed-coupon event from
 * WooCommerce. The middleware reads. This split keeps each piece single-purpose
 * and easy to test.
 */
final class CouponAbuseMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly AbuseCounterStoreInterface $store,
        private readonly int $softThreshold = 4,
        private readonly int $hardThreshold = 10,
        private readonly int $weightSoft = 15,
        private readonly int $weightHard = 80,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        if ($context->ip === '') {
            return $next($context);
        }
        $count = $this->store->get('coupon:ip:' . $context->ip);

        if ($count >= $this->hardThreshold) {
            return Decision::deny(
                'Too many failed coupon attempts.',
                [...$context->signals, new Signal(
                    rule: 'coupon_brute_force',
                    weight: $this->weightHard,
                    reason: 'Coupon failures exceeded hard threshold.',
                    meta: ['failures' => $count, 'limit' => $this->hardThreshold],
                )],
                $context->score + $this->weightHard,
            );
        }

        if ($count >= $this->softThreshold) {
            $context = $context->withSignal(new Signal(
                rule: 'coupon_brute_force',
                weight: $this->weightSoft,
                reason: 'Several failed coupon attempts.',
                meta: ['failures' => $count, 'limit' => $this->softThreshold],
            ));
        }

        return $next($context);
    }
}
