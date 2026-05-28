<?php

declare(strict_types=1);

namespace NiyiGuard\Core\RateLimit;

/**
 * Outcome of a single rate-limit check.
 *
 * Immutable value object surfaced from {@see RateLimiter::attempt()} and consumed by callers
 * (typically middleware) to decide whether to pass the request through and which response
 * headers to emit.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $key,
        public readonly int $limit,
        public readonly int $hits,
        public readonly int $remaining,
        public readonly int $retryAfter,
    ) {
    }
}
