<?php

declare(strict_types=1);

namespace SecurePress\Core\RateLimit;

use Closure;
use SecurePress\Core\Support\WpHelper;

/**
 * Persists rate-limit counters in WordPress transients.
 *
 * Each key stores `['hits' => int, 'expires' => int]`. Because transients are not atomic,
 * concurrent requests can over-count by a small amount under heavy contention. This is an
 * accepted trade-off for security middleware where over-counting is preferable to under-counting.
 *
 * The clock can be injected for deterministic tests; in production it falls back to {@see time()}.
 */
final class TransientStore implements RateLimitStoreInterface
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock
     */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function hit(string $key, int $ttl): int
    {
        $ttl = max(1, $ttl);
        $now = ($this->clock)();
        $existing = WpHelper::getTransient($key);

        if (is_array($existing) && isset($existing['hits'], $existing['expires']) && (int) $existing['expires'] > $now) {
            $hits = (int) $existing['hits'] + 1;
            $expires = (int) $existing['expires'];
        } else {
            $hits = 1;
            $expires = $now + $ttl;
        }

        $remaining = max(1, $expires - $now);
        WpHelper::setTransient($key, ['hits' => $hits, 'expires' => $expires], $remaining);

        return $hits;
    }

    public function ttl(string $key): int
    {
        $existing = WpHelper::getTransient($key);
        if (!is_array($existing) || !isset($existing['expires'])) {
            return 0;
        }

        return max(0, (int) $existing['expires'] - ($this->clock)());
    }

    public function reset(string $key): void
    {
        WpHelper::deleteTransient($key);
    }
}
