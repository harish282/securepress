<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;
use SecurePress\Core\RateLimit\ArrayStore;
use SecurePress\Core\RateLimit\GlobalRateLimitSubscriber;
use SecurePress\Core\RateLimit\RateLimiter;
use SecurePress\Core\RateLimit\RateLimitOptions;
use SecurePress\Core\Support\RequestContext;
use SecurePress\Middleware\RateLimitMiddleware;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * @see \SecurePress\Core\RateLimit\GlobalRateLimitSubscriber
 */
final class GlobalRateLimitSubscriberTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
        RequestContext::reset();
        WpStubState::$isAdmin = false;
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
        RequestContext::reset();
        unset($_SERVER['REQUEST_URI'], $_SERVER['REMOTE_ADDR']);
    }

    public function test_on_wp_loaded_skips_when_wp_admin_context(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => true,
            'limit' => 1,
            'window' => 60,
        ];
        $subscriber = $this->makeSubscriber(limit: 1);

        WpStubState::$isAdmin = true;
        $subscriber->onWpLoaded();

        WpStubState::$isAdmin = false;
        $subscriber->onWpLoaded();
        $subscriber->onWpLoaded();

        self::assertTrue(true);
    }

    public function test_on_rest_pre_dispatch_returns_wp_error_when_limit_exceeded(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => true,
            'limit' => 1,
            'window' => 60,
        ];
        $subscriber = $this->makeSubscriber(limit: 1);

        self::assertNull($subscriber->onRestPreDispatch(null, null, null));
        $err = $subscriber->onRestPreDispatch(null, null, null);
        self::assertInstanceOf(\WP_Error::class, $err);
        self::assertSame('securepress_rate_limit', $err->code);
        self::assertSame(429, $err->data['status'] ?? null);
    }

    private function makeSubscriber(int $limit): GlobalRateLimitSubscriber
    {
        $clock = static fn (): int => 2_000_000_000;
        $limiter = new RateLimiter(new ArrayStore($clock));
        $middleware = new RateLimitMiddleware(
            limiter: $limiter,
            logger: null,
            limit: $limit,
            window: 60,
            keyResolver: null,
            enabled: true,
        );

        return new GlobalRateLimitSubscriber(
            $middleware,
            new RateLimitOptions(new Config())
        );
    }
}
