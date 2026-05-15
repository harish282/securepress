<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Api;

use SecurePress\Core\Recovery\SafeMode;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Storage\AbuseCounterStoreInterface;

/**
 * Route-aware rate limiter for WooCommerce REST endpoints.
 *
 * Behaviour:
 *  - Reads the route from {@see DetectionContext::$route} and matches it against
 *    {@see $perRouteLimits}: an associative array of `prefix => [limit, window]`.
 *  - When a route isn't matched, the {@see $defaultLimit} / {@see $defaultWindow}
 *    pair applies.
 *  - Counter key is `<prefix>:<ip>` so each route has its own bucket and a single
 *    misbehaving consumer can't exhaust the budget of unrelated endpoints.
 *
 * Per-route limits let operators tune individual surfaces independently — e.g.,
 * `/wc/store/cart` can tolerate higher RPS (legit traffic does many cart updates),
 * while `/wc/v3/customers` should be strict (writes only).
 *
 * Returns DENY at the hard cap, with a structured signal explaining which route
 * tripped. Logging the route makes the audit log immediately actionable.
 */
final class ApiRateLimitMiddleware implements WcMiddlewareInterface
{
    /**
     * @param array<string, array{limit:int, window:int}> $perRouteLimits
     */
    public function __construct(
        private readonly AbuseCounterStoreInterface $store,
        private readonly array $perRouteLimits = [],
        private readonly int $defaultLimit = 60,
        private readonly int $defaultWindow = 60,
        private readonly int $weight = 200,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        if (SafeMode::bypasses(SafeMode::BYPASS_RATE_LIMIT)) {
            return $next($context);
        }
        if ($context->ip === '') {
            return $next($context);
        }

        [$limit, $window, $prefix] = $this->limitsFor($context->route);
        $key = 'api:' . $prefix . ':ip:' . $context->ip;
        $count = $this->store->hit($key, $window);

        if ($count > $limit) {
            return Decision::deny(
                'API rate limit exceeded.',
                [...$context->signals, new Signal(
                    rule: 'api_rate_limit',
                    weight: $this->weight,
                    reason: 'Per-route API rate limit exceeded.',
                    meta: ['route' => $context->route, 'limit' => $limit, 'window' => $window, 'hits' => $count],
                )],
                $context->score + $this->weight,
            );
        }

        return $next($context);
    }

    /**
     * @return array{0:int, 1:int, 2:string}
     */
    private function limitsFor(string $route): array
    {
        foreach ($this->perRouteLimits as $prefix => $config) {
            if ($prefix !== '' && str_starts_with($route, $prefix)) {
                return [
                    (int) ($config['limit'] ?? $this->defaultLimit),
                    (int) ($config['window'] ?? $this->defaultWindow),
                    $prefix,
                ];
            }
        }

        return [$this->defaultLimit, $this->defaultWindow, '*'];
    }
}
