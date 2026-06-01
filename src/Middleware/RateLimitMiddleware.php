<?php

declare(strict_types=1);

namespace NiyiGuard\Middleware;

use Closure;
use NiyiGuard\Core\Logging\LoggerInterface;
use NiyiGuard\Core\Logging\NullLogger;
use NiyiGuard\Core\Middleware\MiddlewareInterface;
use NiyiGuard\Core\RateLimit\RateLimiter;
use NiyiGuard\Core\Recovery\SafeMode;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Enforces request-rate limits via {@see RateLimiter}.
 *
 * The default key resolver buckets requests by client IP for anonymous traffic and by user
 * identifier for authenticated traffic supplied via `$context['user']['id']`. Pass a custom
 * Closure to bucket by route, API key, tenant, etc.
 *
 * On each request the middleware writes a `rate_limit` payload to the context with the limit,
 * remaining budget, retry-after seconds, and the resolved key. Allowed requests pass through;
 * rejected requests short-circuit the pipeline with a 429 response payload that downstream
 * HTTP layers can render directly (including standard `Retry-After` and `X-RateLimit-*`
 * headers).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public const DEFAULT_LIMIT = 60;

    public const DEFAULT_WINDOW = 60;

    private readonly RateLimiter $limiter;
    private readonly LoggerInterface $logger;
    private readonly int $limit;
    private readonly int $window;
    private readonly bool $enabled;

    /** @var (Closure(array<string, mixed>): string)|null */
    private readonly ?Closure $keyResolver;

    /**
     * @param (Closure(array<string, mixed>): string)|null $keyResolver
     * @param bool $enabled When false, the middleware passes the request
     *                      straight through to `$next` without touching the
     *                      limiter store. The context still gets a
     *                      `rate_limit` annotation so downstream code that
     *                      reads it doesn't have to special-case the
     *                      disabled state. Defaults to `true` so existing
     *                      callers (RouteBuilder, tests) keep their old
     *                      behaviour.
     */
    public function __construct(
        RateLimiter $limiter,
        ?LoggerInterface $logger = null,
        int $limit = self::DEFAULT_LIMIT,
        int $window = self::DEFAULT_WINDOW,
        ?Closure $keyResolver = null,
        bool $enabled = true,
    ) {
        $this->limiter = $limiter;
        $this->logger = $logger ?? new NullLogger();
        $this->limit = max(1, $limit);
        $this->window = max(1, $window);
        $this->keyResolver = $keyResolver;
        $this->enabled = $enabled;
    }

    public function handle(array $context, callable $next): array
    {
        if (SafeMode::bypasses(SafeMode::BYPASS_RATE_LIMIT)) {
            $context['rate_limit'] = [
                'allowed' => true,
                'bypassed' => true,
                'safe_mode' => true,
                'key' => $this->resolveKey($context),
                'limit' => $this->limit,
                'hits' => 0,
                'remaining' => $this->limit,
                'retry_after' => 0,
            ];

            return $next($context);
        }
        if (!$this->enabled) {
            // Annotate the context so anything reading `$context['rate_limit']`
            // can still reason about whether the limiter ran (e.g. response
            // headers / SDK introspection) without having to know about the
            // bypass mechanism. `bypassed = true` makes the off state
            // explicit instead of relying on the key being absent.
            $context['rate_limit'] = [
                'allowed' => true,
                'bypassed' => true,
                'key' => $this->resolveKey($context),
                'limit' => $this->limit,
                'hits' => 0,
                'remaining' => $this->limit,
                'retry_after' => 0,
            ];

            return $next($context);
        }

        $key = $this->resolveKey($context);
        $result = $this->limiter->attempt($key, $this->limit, $this->window);

        $context['rate_limit'] = [
            'allowed' => $result->allowed,
            'key' => $result->key,
            'limit' => $result->limit,
            'hits' => $result->hits,
            'remaining' => $result->remaining,
            'retry_after' => $result->retryAfter,
        ];

        if (!$result->allowed) {
            $this->logger->warning('Rate limit exceeded.', [
                'key' => $result->key,
                'limit' => $result->limit,
                'hits' => $result->hits,
                'retry_after' => $result->retryAfter,
            ]);

            $context['response'] = [
                'status' => 429,
                'message' => 'Too many requests.',
                'headers' => [
                    'Retry-After' => (string) $result->retryAfter,
                    'X-RateLimit-Limit' => (string) $result->limit,
                    'X-RateLimit-Remaining' => '0',
                ],
            ];
            $context['halted'] = true;

            return $context;
        }

        return $next($context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveKey(array $context): string
    {
        if ($this->keyResolver !== null) {
            $resolved = ($this->keyResolver)($context);
            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        $user = $context['user'] ?? null;
        if (is_array($user)) {
            $userId = $user['id'] ?? null;
            if (is_int($userId) && $userId > 0) {
                return 'user:' . $userId;
            }
            if (is_string($userId) && $userId !== '') {
                return 'user:' . $userId;
            }
        }

        $request = is_array($context['request'] ?? null) ? $context['request'] : [];
        $ip = $request['ip'] ?? null;
        if (is_string($ip) && $ip !== '') {
            return 'ip:' . $ip;
        }

        $clientIp = WpHelper::getClientIp();

        return 'ip:' . ($clientIp ?? 'unknown');
    }
}
