<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\RateLimit\ArrayStore;
use NiyiGuard\Core\RateLimit\RateLimiter;
use NiyiGuard\Middleware\RateLimitMiddleware;
use NiyiGuard\Tests\Stubs\WpStubState;

final class RateLimitMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    private int $now = 1_700_000_000;

    /** @var Closure(): int */
    private Closure $clock;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->serverBackup = $_SERVER;
        $_SERVER = [];
        $this->now = 1_700_000_000;
        $this->clock = fn (): int => $this->now;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WpStubState::reset();
    }

    public function test_first_request_is_allowed_and_annotates_context(): void
    {
        $middleware = $this->makeMiddleware(limit: 3, window: 60);

        $reached = false;
        $next = static function (array $context) use (&$reached): array {
            $reached = true;

            return $context + ['next' => true];
        };

        $result = $middleware->handle(['request' => ['ip' => '203.0.113.10']], $next);

        self::assertTrue($reached);
        self::assertTrue($result['next']);
        self::assertSame(
            [
                'allowed' => true,
                'key' => 'ip:203.0.113.10',
                'limit' => 3,
                'hits' => 1,
                'remaining' => 2,
                'retry_after' => 0,
            ],
            $result['rate_limit']
        );
        self::assertArrayNotHasKey('halted', $result);
    }

    public function test_request_beyond_limit_short_circuits_with_429_payload(): void
    {
        $middleware = $this->makeMiddleware(limit: 2, window: 30);
        $context = ['request' => ['ip' => '203.0.113.20']];

        $passThrough = static fn (array $context): array => $context + ['next' => true];

        $middleware->handle($context, $passThrough);
        $middleware->handle($context, $passThrough);

        $blocked = $middleware->handle($context, static function (): array {
            self::fail('Pipeline must short-circuit when rate limit is exceeded.');
        });

        self::assertTrue($blocked['halted']);
        self::assertFalse($blocked['rate_limit']['allowed']);
        self::assertSame(0, $blocked['rate_limit']['remaining']);
        self::assertSame(30, $blocked['rate_limit']['retry_after']);

        self::assertSame(429, $blocked['response']['status']);
        self::assertSame('Too many requests.', $blocked['response']['message']);
        self::assertSame('30', $blocked['response']['headers']['Retry-After']);
        self::assertSame('2', $blocked['response']['headers']['X-RateLimit-Limit']);
        self::assertSame('0', $blocked['response']['headers']['X-RateLimit-Remaining']);
    }

    public function test_authenticated_user_id_takes_precedence_over_ip(): void
    {
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle(
            [
                'request' => ['ip' => '203.0.113.30'],
                'user' => ['id' => 42],
            ],
            static fn (array $context): array => $context
        );

        self::assertSame('user:42', $result['rate_limit']['key']);
    }

    public function test_falls_back_to_remote_addr_when_context_lacks_ip(): void
    {
        $middleware = $this->makeMiddleware();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';

        $result = $middleware->handle([], static fn (array $context): array => $context);

        self::assertSame('ip:198.51.100.7', $result['rate_limit']['key']);
    }

    public function test_falls_back_to_unknown_when_no_ip_is_available(): void
    {
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle([], static fn (array $context): array => $context);

        self::assertSame('ip:unknown', $result['rate_limit']['key']);
    }

    public function test_invalid_remote_addr_is_rejected_and_falls_back_to_unknown(): void
    {
        $middleware = $this->makeMiddleware();
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';

        $result = $middleware->handle([], static fn (array $context): array => $context);

        self::assertSame('ip:unknown', $result['rate_limit']['key']);
    }

    public function test_custom_key_resolver_is_honored(): void
    {
        $resolver = static fn (array $context): string => 'route:' . ($context['request']['path'] ?? '/');
        $middleware = $this->makeMiddleware(keyResolver: $resolver);

        $result = $middleware->handle(
            ['request' => ['path' => '/api/v1/login', 'ip' => '203.0.113.40']],
            static fn (array $context): array => $context
        );

        self::assertSame('route:/api/v1/login', $result['rate_limit']['key']);
    }

    public function test_window_resets_after_ttl_elapses(): void
    {
        $middleware = $this->makeMiddleware(limit: 1, window: 5);
        $context = ['request' => ['ip' => '203.0.113.50']];
        $next = static fn (array $context): array => $context + ['next' => true];

        $first = $middleware->handle($context, $next);
        self::assertTrue($first['rate_limit']['allowed']);

        $blocked = $middleware->handle($context, static fn (array $c): array => $c);
        self::assertFalse($blocked['rate_limit']['allowed']);

        $this->now += 6;

        $afterWindow = $middleware->handle($context, $next);
        self::assertTrue($afterWindow['rate_limit']['allowed']);
        self::assertSame(1, $afterWindow['rate_limit']['hits']);
    }

    public function test_disabled_middleware_bypasses_limiter_and_passes_through_to_next(): void
    {
        // limit=1 would normally short-circuit on the second request; with
        // the master switch off both calls must reach `$next` unchanged.
        $middleware = $this->makeMiddleware(limit: 1, window: 60, enabled: false);

        $next = static fn (array $ctx): array => $ctx + ['next_was_called' => true];

        $first = $middleware->handle(['request' => ['ip' => '203.0.113.99']], $next);
        $second = $middleware->handle(['request' => ['ip' => '203.0.113.99']], $next);

        self::assertTrue($first['next_was_called']);
        self::assertTrue($second['next_was_called']);

        // Both requests are annotated so downstream readers can still see
        // the limit + bucket without having to know about the bypass path.
        self::assertTrue($first['rate_limit']['allowed']);
        self::assertTrue($first['rate_limit']['bypassed']);
        self::assertSame(1, $first['rate_limit']['limit']);
        self::assertSame(0, $first['rate_limit']['hits']);
        self::assertSame(1, $first['rate_limit']['remaining']);
        self::assertSame('ip:203.0.113.99', $first['rate_limit']['key']);

        // Same on the second call — no hit counter incremented in the
        // limiter store.
        self::assertSame(0, $second['rate_limit']['hits']);
        self::assertArrayNotHasKey('halted', $second);
    }

    public function test_zero_or_negative_constructor_values_are_normalized(): void
    {
        $middleware = $this->makeMiddleware(limit: 0, window: 0);

        $result = $middleware->handle(
            ['request' => ['ip' => '203.0.113.60']],
            static fn (array $context): array => $context
        );

        self::assertSame(1, $result['rate_limit']['limit']);
    }

    /**
     * @param (Closure(array<string, mixed>): string)|null $keyResolver
     */
    private function makeMiddleware(
        int $limit = RateLimitMiddleware::DEFAULT_LIMIT,
        int $window = RateLimitMiddleware::DEFAULT_WINDOW,
        ?Closure $keyResolver = null,
        bool $enabled = true,
    ): RateLimitMiddleware {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        return new RateLimitMiddleware(
            limiter: $limiter,
            logger: null,
            limit: $limit,
            window: $window,
            keyResolver: $keyResolver,
            enabled: $enabled,
        );
    }
}
