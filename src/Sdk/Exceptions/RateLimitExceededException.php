<?php

declare(strict_types=1);

namespace PressSentinel\Sdk\Exceptions;

use RuntimeException;
use PressSentinel\Core\RateLimit\RateLimitResult;

/**
 * Thrown by {@see \PressSentinel\Facades\Security::throttle()} when the limit has been
 * exceeded for the supplied key.
 *
 * The full {@see RateLimitResult} is preserved on the exception so error handlers can
 * mint correct `429 Too Many Requests` responses (including the `Retry-After` header)
 * without having to re-run the rate-limit check.
 */
final class RateLimitExceededException extends RuntimeException
{
    public function __construct(public readonly RateLimitResult $result)
    {
        parent::__construct(sprintf(
            'Rate limit exceeded for key "%s" (%d/%d hits, retry after %ds).',
            $result->key,
            $result->hits,
            $result->limit,
            $result->retryAfter,
        ));
    }

    public function retryAfter(): int
    {
        return $this->result->retryAfter;
    }
}
