<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Container;
use SecurePress\Core\Http\RouteGuardRegistry;
use SecurePress\Core\Middleware\MiddlewareRegistry;
use SecurePress\Core\Middleware\MiddlewareStack;
use SecurePress\Core\RateLimit\ArrayStore;
use SecurePress\Core\RateLimit\RateLimitStoreInterface;
use SecurePress\Core\RateLimit\RateLimiter;
use SecurePress\Facades\Security;
use SecurePress\Sdk\Exceptions\RateLimitExceededException;

/**
 * @see \SecurePress\Facades\Security::rateLimit
 * @see \SecurePress\Facades\Security::throttle
 * @see \SecurePress\Facades\Security::resetRateLimit
 */
final class SecurityRateLimitSdkTest extends TestCase
{
    public function test_rateLimit_returns_allowed_result_under_budget(): void
    {
        Security::bootstrap($this->container());

        $result = Security::rateLimit('user:42', limit: 3, window: 60);

        self::assertTrue($result->allowed);
        self::assertSame('user:42', $result->key);
        self::assertSame(3, $result->limit);
        self::assertSame(1, $result->hits);
        self::assertSame(2, $result->remaining);
        self::assertSame(0, $result->retryAfter);
    }

    public function test_rateLimit_denies_after_budget_is_spent(): void
    {
        Security::bootstrap($this->container());

        Security::rateLimit('user:42', 2, 60);
        Security::rateLimit('user:42', 2, 60);
        $third = Security::rateLimit('user:42', 2, 60);

        self::assertFalse($third->allowed);
        self::assertSame(0, $third->remaining);
        self::assertGreaterThan(0, $third->retryAfter);
    }

    public function test_resetRateLimit_clears_the_counter(): void
    {
        Security::bootstrap($this->container());

        Security::rateLimit('reset-me', 1, 60);
        Security::resetRateLimit('reset-me');
        $afterReset = Security::rateLimit('reset-me', 1, 60);

        self::assertTrue($afterReset->allowed);
    }

    public function test_throttle_invokes_callback_when_allowed_and_returns_its_value(): void
    {
        Security::bootstrap($this->container());

        $result = Security::throttle('expensive', 2, 60, static fn (): int => 12345);

        self::assertSame(12345, $result);
    }

    public function test_throttle_throws_when_limit_exceeded(): void
    {
        Security::bootstrap($this->container());

        Security::throttle('cap-1', 1, 60, static fn (): bool => true);

        $this->expectException(RateLimitExceededException::class);

        try {
            Security::throttle('cap-1', 1, 60, static fn (): bool => true);
        } catch (RateLimitExceededException $exception) {
            self::assertGreaterThan(0, $exception->retryAfter());
            self::assertSame('cap-1', $exception->result->key);
            throw $exception;
        }
    }

    private function container(): Container
    {
        $container = new Container();
        $container->singleton(MiddlewareRegistry::class, static fn (): MiddlewareRegistry => new MiddlewareRegistry());
        $container->singleton(MiddlewareStack::class, static fn (): MiddlewareStack => new MiddlewareStack());
        $container->singleton(RouteGuardRegistry::class, static fn (): RouteGuardRegistry => new RouteGuardRegistry());
        $container->singleton(RateLimitStoreInterface::class, static fn (): RateLimitStoreInterface => new ArrayStore());
        $container->singleton(
            RateLimiter::class,
            static fn (Container $c): RateLimiter => new RateLimiter($c->get(RateLimitStoreInterface::class))
        );

        return $container;
    }
}
