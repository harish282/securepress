<?php

declare(strict_types=1);

namespace NiyiGuard\Core\RateLimit;

/**
 * Persistence contract for rate-limit counters.
 *
 * Implementations must implement a fixed-window counter: the first hit on a key starts a window
 * of `$ttl` seconds; subsequent hits within that window MUST increment without resetting the
 * window expiry. After the window elapses the counter MUST start over from 1 on the next hit.
 *
 * Implementations are not required to be atomic across processes; a small amount of
 * over-counting under heavy contention is acceptable for security middleware.
 */
interface RateLimitStoreInterface
{
    /**
     * Records a hit for the given key and returns the new counter value.
     *
     * @param string $key Already-namespaced storage key.
     * @param int    $ttl Window length in seconds; only used when starting a new window.
     */
    public function hit(string $key, int $ttl): int;

    /**
     * Returns the seconds remaining in the current window for the key, or `0` when no
     * window is active.
     */
    public function ttl(string $key): int;

    public function reset(string $key): void;
}
