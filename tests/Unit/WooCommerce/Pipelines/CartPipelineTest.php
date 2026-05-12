<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\WooCommerce\Pipelines;

use PHPUnit\Framework\TestCase;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Middleware\Cart\CartVelocityMiddleware;
use SecurePress\WooCommerce\Middleware\Cart\CouponAbuseMiddleware;
use SecurePress\WooCommerce\Pipelines\CartPipeline;
use SecurePress\WooCommerce\Storage\ArrayAbuseCounterStore;

/**
 * @see \SecurePress\WooCommerce\Pipelines\CartPipeline
 * @see \SecurePress\WooCommerce\Middleware\Cart\CartVelocityMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Cart\CouponAbuseMiddleware
 */
final class CartPipelineTest extends TestCase
{
    public function test_normal_cart_activity_accepts(): void
    {
        $pipeline = new CartPipeline([
            new CartVelocityMiddleware(new ArrayAbuseCounterStore(), 20, 60, 60),
            new CouponAbuseMiddleware(new ArrayAbuseCounterStore(), 4, 10),
        ]);

        $result = $pipeline->run($this->ctx());

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
    }

    public function test_cart_velocity_denies_above_hard_threshold(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new CartPipeline([
            new CartVelocityMiddleware($store, softThreshold: 2, hardThreshold: 3, windowSeconds: 60),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $pipeline->run($this->ctx());
        }
        $result = $pipeline->run($this->ctx());

        self::assertTrue($result->blocked());
    }

    public function test_coupon_abuse_denies_above_hard_threshold(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new CartPipeline([
            new CouponAbuseMiddleware($store, softThreshold: 2, hardThreshold: 4),
        ]);
        // Simulate four failed coupon attempts feeding the counter:
        for ($i = 0; $i < 4; $i++) {
            $store->hit('coupon:ip:203.0.113.9', 600);
        }

        $result = $pipeline->run($this->ctx());

        self::assertTrue($result->blocked());
    }

    public function test_coupon_abuse_emits_signal_below_hard_threshold(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new CartPipeline([
            new CouponAbuseMiddleware($store, softThreshold: 2, hardThreshold: 99),
        ]);
        for ($i = 0; $i < 3; $i++) {
            $store->hit('coupon:ip:203.0.113.9', 600);
        }

        $result = $pipeline->run($this->ctx());

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
        self::assertNotEmpty($result->context->signals);
    }

    private function ctx(): DetectionContext
    {
        return new DetectionContext(
            kind: DetectionContext::KIND_CART,
            ip: '203.0.113.9',
            userAgent: 'Mozilla/5.0',
        );
    }
}
