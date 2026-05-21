<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use PressSentinel\Core\RateLimit\ArrayStore;
use PressSentinel\Core\RateLimit\RateLimiter;
use PressSentinel\Core\RateLimit\TransientStore;
use PressSentinel\Tests\Stubs\WpStubState;

final class RateLimiterTest extends TestCase
{
    private int $now = 1_700_000_000;

    /** @var Closure(): int */
    private Closure $clock;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->now = 1_700_000_000;
        $this->clock = fn (): int => $this->now;
    }

    public function test_first_attempt_is_allowed_with_full_remaining_minus_one(): void
    {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        $result = $limiter->attempt('alice', limit: 5, window: 60);

        self::assertTrue($result->allowed);
        self::assertSame('alice', $result->key);
        self::assertSame(5, $result->limit);
        self::assertSame(1, $result->hits);
        self::assertSame(4, $result->remaining);
        self::assertSame(0, $result->retryAfter);
    }

    public function test_subsequent_attempts_within_window_increment_counter(): void
    {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        $first = $limiter->attempt('bob', 3, 60);
        $second = $limiter->attempt('bob', 3, 60);
        $third = $limiter->attempt('bob', 3, 60);

        self::assertTrue($first->allowed);
        self::assertTrue($second->allowed);
        self::assertTrue($third->allowed);
        self::assertSame([1, 2, 3], [$first->hits, $second->hits, $third->hits]);
        self::assertSame([2, 1, 0], [$first->remaining, $second->remaining, $third->remaining]);
    }

    public function test_attempt_beyond_limit_is_rejected_with_retry_after(): void
    {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        $limiter->attempt('cara', 2, 30);
        $limiter->attempt('cara', 2, 30);
        $rejected = $limiter->attempt('cara', 2, 30);

        self::assertFalse($rejected->allowed);
        self::assertSame(3, $rejected->hits);
        self::assertSame(0, $rejected->remaining);
        self::assertSame(30, $rejected->retryAfter);
    }

    public function test_window_resets_after_ttl_elapses(): void
    {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        $limiter->attempt('dan', 1, 10);
        $rejected = $limiter->attempt('dan', 1, 10);
        self::assertFalse($rejected->allowed);

        $this->now += 11;

        $afterWindow = $limiter->attempt('dan', 1, 10);
        self::assertTrue($afterWindow->allowed);
        self::assertSame(1, $afterWindow->hits);
    }

    public function test_keys_are_independent(): void
    {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        $limiter->attempt('user-a', 1, 60);
        $second = $limiter->attempt('user-b', 1, 60);

        self::assertTrue($second->allowed);
        self::assertSame(1, $second->hits);
    }

    public function test_reset_clears_counter(): void
    {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        $limiter->attempt('eve', 2, 60);
        $limiter->attempt('eve', 2, 60);
        $limiter->reset('eve');

        $afterReset = $limiter->attempt('eve', 2, 60);
        self::assertTrue($afterReset->allowed);
        self::assertSame(1, $afterReset->hits);
    }

    public function test_zero_or_negative_limit_is_normalized_to_one(): void
    {
        $limiter = new RateLimiter(new ArrayStore($this->clock));

        $first = $limiter->attempt('frank', 0, 60);
        $second = $limiter->attempt('frank', 0, 60);

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->limit);
        self::assertFalse($second->allowed);
    }

    public function test_namespacing_prefix_isolates_keys_across_limiters(): void
    {
        $store = new ArrayStore($this->clock);
        $limiterA = new RateLimiter($store, prefix: 'a_');
        $limiterB = new RateLimiter($store, prefix: 'b_');

        $limiterA->attempt('shared', 1, 60);
        $limiterA->attempt('shared', 1, 60);
        $bResult = $limiterB->attempt('shared', 1, 60);

        self::assertTrue($bResult->allowed);
        self::assertSame(1, $bResult->hits);
    }

    public function test_transient_store_counts_via_wp_transient_stubs(): void
    {
        $limiter = new RateLimiter(new TransientStore($this->clock));

        $first = $limiter->attempt('grace', 2, 60);
        $second = $limiter->attempt('grace', 2, 60);
        $third = $limiter->attempt('grace', 2, 60);

        self::assertTrue($first->allowed);
        self::assertTrue($second->allowed);
        self::assertFalse($third->allowed);
        self::assertNotEmpty(WpStubState::$transients);
    }

    public function test_transient_store_window_expiry_is_honored(): void
    {
        $store = new TransientStore($this->clock);
        $limiter = new RateLimiter($store);

        $limiter->attempt('heidi', 1, 5);
        $rejected = $limiter->attempt('heidi', 1, 5);
        self::assertFalse($rejected->allowed);

        $this->now += 6;
        WpStubState::$now = $this->now;

        $afterWindow = $limiter->attempt('heidi', 1, 5);
        self::assertTrue($afterWindow->allowed);
        self::assertSame(1, $afterWindow->hits);
    }
}
