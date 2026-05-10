<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Lockout;

/**
 * In-memory {@see LockoutStoreInterface} for tests.
 *
 * Time-aware via the injected `now` closure so cases can advance the clock and observe
 * window expiry without sleeping. Faithfully reproduces the production behaviour where
 * counters reset when their window has elapsed.
 */
final class ArrayLockoutStore implements LockoutStoreInterface
{
    /** @var array<string, array{count:int, expires_at:int}> */
    private array $counters = [];

    /** @var array<string, int> */
    private array $locks = [];

    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    public function hit(string $key, int $windowSeconds): int
    {
        $now = ($this->now)();
        $entry = $this->counters[$key] ?? null;

        if ($entry === null || $entry['expires_at'] <= $now) {
            $this->counters[$key] = ['count' => 1, 'expires_at' => $now + $windowSeconds];

            return 1;
        }

        $entry['count']++;
        $this->counters[$key] = $entry;

        return $entry['count'];
    }

    public function count(string $key): int
    {
        $entry = $this->counters[$key] ?? null;
        if ($entry === null || $entry['expires_at'] <= ($this->now)()) {
            return 0;
        }

        return $entry['count'];
    }

    public function lock(string $key, int $forSeconds): void
    {
        $this->locks[$key] = ($this->now)() + $forSeconds;
    }

    public function isLocked(string $key): bool
    {
        return $this->lockExpiresAt($key) !== null;
    }

    public function lockExpiresAt(string $key): ?int
    {
        $expiry = $this->locks[$key] ?? null;
        if ($expiry === null) {
            return null;
        }
        if ($expiry <= ($this->now)()) {
            unset($this->locks[$key]);

            return null;
        }

        return $expiry;
    }

    public function clear(string $key): void
    {
        unset($this->counters[$key], $this->locks[$key]);
    }
}
