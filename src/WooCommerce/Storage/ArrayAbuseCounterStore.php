<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Storage;

use Closure;

/**
 * In-memory abuse counter store for unit tests.
 *
 * Two pieces of state are kept:
 *  - **counters**:   `[ key => [count, expiresAt] ]`
 *  - **occurrences**: `[ key => [ value => [count, expiresAt] ] ]` for the
 *                    "how many times have we seen this exact value?" path.
 *
 * The clock is injectable so tests can advance time without `sleep()`.
 */
final class ArrayAbuseCounterStore implements AbuseCounterStoreInterface
{
    /** @var array<string, array{count:int, expires:int}> */
    private array $counters = [];

    /** @var array<string, array<string, array{count:int, expires:int}>> */
    private array $occurrences = [];

    /** @var Closure(): int */
    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function hit(string $key, int $ttlSeconds): int
    {
        $now = ($this->clock)();
        $entry = $this->counters[$key] ?? null;
        if ($entry === null || $entry['expires'] <= $now) {
            $entry = ['count' => 0, 'expires' => $now + max(1, $ttlSeconds)];
        }
        $entry['count']++;
        $this->counters[$key] = $entry;

        return $entry['count'];
    }

    public function get(string $key): int
    {
        $now = ($this->clock)();
        $entry = $this->counters[$key] ?? null;
        if ($entry === null || $entry['expires'] <= $now) {
            return 0;
        }

        return $entry['count'];
    }

    public function reset(string $key): void
    {
        unset($this->counters[$key], $this->occurrences[$key]);
    }

    public function seen(string $key, string $value, int $ttlSeconds): int
    {
        $now = ($this->clock)();
        $byValue = $this->occurrences[$key] ?? [];
        // Drop expired entries to keep memory bounded.
        foreach ($byValue as $v => $entry) {
            if ($entry['expires'] <= $now) {
                unset($byValue[$v]);
            }
        }
        $entry = $byValue[$value] ?? ['count' => 0, 'expires' => $now + max(1, $ttlSeconds)];
        $entry['count']++;
        $byValue[$value] = $entry;
        $this->occurrences[$key] = $byValue;

        return $entry['count'];
    }
}
