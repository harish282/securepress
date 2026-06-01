<?php

declare(strict_types=1);

namespace NiyiGuard\Core\RateLimit;

use Closure;

/**
 * In-memory {@see RateLimitStoreInterface} implementation.
 *
 * Useful for tests and as a non-persistent fallback when WordPress transients are unavailable.
 * State lives only for the current PHP process, so this store is NOT suitable for production
 * rate limiting across requests.
 *
 * The clock can be injected for deterministic time control in tests.
 */
final class ArrayStore implements RateLimitStoreInterface
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @var array<string, array{hits: int, expires: int}> */
    private array $entries = [];

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function hit(string $key, int $ttl): int
    {
        $now = ($this->clock)();

        if (!isset($this->entries[$key]) || $this->entries[$key]['expires'] <= $now) {
            $this->entries[$key] = ['hits' => 1, 'expires' => $now + max(1, $ttl)];

            return 1;
        }

        $this->entries[$key]['hits']++;

        return $this->entries[$key]['hits'];
    }

    public function ttl(string $key): int
    {
        $now = ($this->clock)();

        if (!isset($this->entries[$key]) || $this->entries[$key]['expires'] <= $now) {
            return 0;
        }

        return max(0, $this->entries[$key]['expires'] - $now);
    }

    public function reset(string $key): void
    {
        unset($this->entries[$key]);
    }
}
