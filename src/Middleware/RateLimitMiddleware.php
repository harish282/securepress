<?php

declare(strict_types=1);

namespace SecurePress\Middleware;

use Closure;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Core\Middleware\MiddlewareInterface;
use SecurePress\Core\RateLimit\RateLimiter;
use SecurePress\Core\Support\WpHelper;

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

    /** @var (Closure(array<string, mixed>): string)|null */
    private readonly ?Closure $keyResolver;

    /**
     * @param (Closure(array<string, mixed>): string)|null $keyResolver
     */
    public function __construct(
        RateLimiter $limiter,
        ?LoggerInterface $logger = null,
        int $limit = self::DEFAULT_LIMIT,
        int $window = self::DEFAULT_WINDOW,
        ?Closure $keyResolver = null,
    ) {
        $this->limiter = $limiter;
        $this->logger = $logger ?? new NullLogger();
        $this->limit = max(1, $limit);
        $this->window = max(1, $window);
        $this->keyResolver = $keyResolver;
    }

    public function handle(array $context, callable $next): array
    {
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
