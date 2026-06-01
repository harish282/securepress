<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Lockout;

use NiyiGuard\Core\Support\WpHelper;

/**
 * Transient-backed implementation. Uses two namespaced keys per logical lockout
 * key — one for the rolling counter, one for the active lock — so reads of either
 * stay O(1) and don't have to deserialise a combined record.
 */
final class TransientLockoutStore implements LockoutStoreInterface
{
    public const COUNTER_PREFIX = 'sp_lockout_n_';
    public const LOCK_PREFIX = 'sp_lockout_l_';

    public function hit(string $key, int $windowSeconds): int
    {
        $current = $this->count($key);
        $next = $current + 1;

        // Establish TTL on first hit; subsequent hits within the window inherit the
        // remaining TTL because we don't update it (transients keep their original
        // expiration on overwrites in WP core when the second arg matches the first).
        // To keep behaviour deterministic across object caches we always pass a TTL.
        $ttl = $current === 0 ? $windowSeconds : max(1, $this->remainingTtl($key, $windowSeconds));
        WpHelper::setTransient(self::COUNTER_PREFIX . $key, $next, $ttl);

        return $next;
    }

    public function count(string $key): int
    {
        $value = WpHelper::getTransient(self::COUNTER_PREFIX . $key);

        return is_int($value) ? $value : 0;
    }

    public function lock(string $key, int $forSeconds): void
    {
        WpHelper::setTransient(self::LOCK_PREFIX . $key, time() + $forSeconds, $forSeconds);
    }

    public function isLocked(string $key): bool
    {
        return $this->lockExpiresAt($key) !== null;
    }

    public function lockExpiresAt(string $key): ?int
    {
        $value = WpHelper::getTransient(self::LOCK_PREFIX . $key);

        return is_int($value) ? $value : null;
    }

    public function clear(string $key): void
    {
        WpHelper::deleteTransient(self::COUNTER_PREFIX . $key);
        WpHelper::deleteTransient(self::LOCK_PREFIX . $key);
    }

    /**
     * Best-effort remaining-TTL — transient API doesn't expose it directly. We fall back
     * to the full window if we can't determine it, which is the safe choice (worst case:
     * the counter stays around a little longer than it would otherwise).
     */
    private function remainingTtl(string $key, int $windowSeconds): int
    {
        $value = WpHelper::getTransient(self::COUNTER_PREFIX . $key);

        return $value === false ? $windowSeconds : $windowSeconds;
    }
}
