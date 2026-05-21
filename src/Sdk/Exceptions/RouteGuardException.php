<?php

declare(strict_types=1);

namespace PressSentinel\Sdk\Exceptions;

use RuntimeException;

/**
 * Thrown by the fluent {@see \PressSentinel\Sdk\Routing\RouteBuilder} when one of the
 * registered guards (CSRF, rate-limit, capability check, signed-URL verification, …)
 * rejects the current request.
 *
 * Carries the HTTP-style status code and the canonical guard name that fired, so
 * application code can render a tailored response without inspecting middleware
 * internals.
 */
final class RouteGuardException extends RuntimeException
{
    public function __construct(
        public readonly string $guard,
        public readonly int $statusCode,
        string $message,
        /**
         * Optional extra response headers the guard would like emitted alongside the
         * error response (e.g., `Retry-After` from the rate-limit guard).
         *
         * @var array<string, string>
         */
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
