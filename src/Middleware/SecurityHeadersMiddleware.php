<?php

declare(strict_types=1);

namespace SecurePress\Middleware;

use SecurePress\Core\Headers\HeaderRegistryFactory;
use SecurePress\Core\Middleware\MiddlewareInterface;

/**
 * Adds configured security headers to the pipeline's response payload.
 *
 * Use this middleware when an HTTP/kernel layer is going to emit headers from
 * `$context['response']['headers']`. For unconditionally applying headers to *every*
 * WordPress response — including those that don't go through the pipeline — use
 * {@see \SecurePress\Core\Headers\SecurityHeadersDispatcher} instead. Most installs want
 * the dispatcher; the middleware is here for advanced flows that need different headers
 * per route.
 *
 * Existing entries in `$context['response']['headers']` win over the registry's output, so
 * a route can override a globally-configured header (e.g. tightening CSP for an admin route)
 * without affecting other routes.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly HeaderRegistryFactory $factory,
    ) {
    }

    public function handle(array $context, callable $next): array
    {
        $context = $next($context);

        $produced = $this->factory->make()->emit();
        if ($produced === []) {
            return $context;
        }

        $response = is_array($context['response'] ?? null) ? $context['response'] : [];
        $existing = is_array($response['headers'] ?? null) ? $response['headers'] : [];

        $response['headers'] = $existing + $produced;
        $context['response'] = $response;

        return $context;
    }
}
