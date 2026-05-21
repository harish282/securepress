<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\Lockout;

/**
 * Persistence contract for failed-login counters.
 *
 * Two operations: increment a counter (returning the new value) and ask whether the
 * counter is currently locked out. We deliberately *don't* expose a "decrement" or
 * "reset" call from the lockout flow — successful logins reset via {@see clear()}, and
 * windows expire naturally because the underlying transient TTL.
 */
interface LockoutStoreInterface
{
    /**
     * Increments the counter for `$key`, returns the new count.
     *
     * The first increment in a window establishes the TTL; subsequent increments inside
     * the window do *not* extend the TTL. That keeps the policy fixed: "N failures in
     * the last `windowSeconds`, fixed window".
     */
    public function hit(string $key, int $windowSeconds): int;

    public function count(string $key): int;

    public function lock(string $key, int $forSeconds): void;

    public function isLocked(string $key): bool;

    public function lockExpiresAt(string $key): ?int;

    public function clear(string $key): void;
}
