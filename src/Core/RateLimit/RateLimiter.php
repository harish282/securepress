<?php

declare(strict_types=1);

namespace PressSentinel\Core\RateLimit;

/**
 * Fixed-window rate limiter.
 *
 * Counts hits per key inside a window of `$window` seconds. The counter starts at 1 on the
 * first hit and is incremented on each subsequent hit until it expires; the window only resets
 * after expiry, so a burst that exhausts the budget cannot be cleared by waiting a fraction of
 * the window.
 *
 * Storage is delegated to a {@see RateLimitStoreInterface} implementation; swap stores to
 * change durability (transients, object cache, in-memory, etc.).
 */
final class RateLimiter
{
    public function __construct(
        private readonly RateLimitStoreInterface $store,
        private readonly string $prefix = 'sp_rl_',
    ) {
    }

    public function attempt(string $key, int $limit, int $window): RateLimitResult
    {
        $limit = max(1, $limit);
        $window = max(1, $window);

        $namespacedKey = $this->namespacedKey($key);
        $hits = $this->store->hit($namespacedKey, $window);
        $allowed = $hits <= $limit;
        $remaining = $allowed ? max(0, $limit - $hits) : 0;
        $retryAfter = $allowed ? 0 : max(1, $this->store->ttl($namespacedKey));

        return new RateLimitResult(
            allowed: $allowed,
            key: $key,
            limit: $limit,
            hits: $hits,
            remaining: $remaining,
            retryAfter: $retryAfter,
        );
    }

    public function reset(string $key): void
    {
        $this->store->reset($this->namespacedKey($key));
    }

    private function namespacedKey(string $key): string
    {
        return $this->prefix . md5($key);
    }
}
