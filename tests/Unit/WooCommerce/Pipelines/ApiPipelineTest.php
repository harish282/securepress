<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\WooCommerce\Pipelines;

use PHPUnit\Framework\TestCase;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Middleware\Api\ApiRateLimitMiddleware;
use SecurePress\WooCommerce\Middleware\Api\SuspiciousRequestMiddleware;
use SecurePress\WooCommerce\Pipelines\ApiPipeline;
use SecurePress\WooCommerce\Storage\ArrayAbuseCounterStore;

/**
 * @see \SecurePress\WooCommerce\Pipelines\ApiPipeline
 * @see \SecurePress\WooCommerce\Middleware\Api\ApiRateLimitMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Api\SuspiciousRequestMiddleware
 */
final class ApiPipelineTest extends TestCase
{
    public function test_normal_request_accepts(): void
    {
        $pipeline = new ApiPipeline([
            new SuspiciousRequestMiddleware(),
            new ApiRateLimitMiddleware(new ArrayAbuseCounterStore(), [], 60, 60),
        ]);

        $result = $pipeline->run($this->ctx('Mozilla/5.0'));

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
    }

    public function test_scanner_user_agent_denies(): void
    {
        $pipeline = new ApiPipeline([
            new SuspiciousRequestMiddleware(),
        ]);

        $result = $pipeline->run($this->ctx('sqlmap/1.6'));

        self::assertSame(Decision::DENY, $result->decision->outcome);
    }

    public function test_rate_limit_denies_after_hard_threshold(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new ApiPipeline([
            new ApiRateLimitMiddleware($store, [], defaultLimit: 3, defaultWindow: 60),
        ]);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(Decision::ACCEPT, $pipeline->run($this->ctx())->decision->outcome);
        }
        $result = $pipeline->run($this->ctx());

        self::assertTrue($result->blocked());
    }

    public function test_per_route_overrides_take_precedence(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new ApiPipeline([
            new ApiRateLimitMiddleware(
                $store,
                ['/wc/store/cart' => ['limit' => 1, 'window' => 60]],
                defaultLimit: 100,
                defaultWindow: 60,
            ),
        ]);

        self::assertSame(Decision::ACCEPT, $pipeline->run($this->ctx(route: '/wc/store/cart'))->decision->outcome);
        self::assertTrue($pipeline->run($this->ctx(route: '/wc/store/cart'))->blocked());
    }

    public function test_authenticated_request_is_skipped_when_configured(): void
    {
        $pipeline = new ApiPipeline([
            new SuspiciousRequestMiddleware(passWhenAuthenticated: true),
        ]);

        $ctx = new DetectionContext(
            kind: DetectionContext::KIND_API,
            ip: '203.0.113.7',
            userAgent: 'sqlmap',
            userId: 42,
            route: '/wc/v3/customers',
        );

        $result = $pipeline->run($ctx);

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
    }

    private function ctx(string $ua = 'Mozilla/5.0', string $route = '/wc/v3/products'): DetectionContext
    {
        return new DetectionContext(
            kind: DetectionContext::KIND_API,
            ip: '203.0.113.7',
            userAgent: $ua,
            route: $route,
        );
    }
}
