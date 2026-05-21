<?php

declare(strict_types=1);

namespace PressSentinel\Middleware;

use PressSentinel\Core\Logging\LoggerInterface;
use PressSentinel\Core\Logging\NullLogger;
use PressSentinel\Core\Middleware\MiddlewareInterface;
use PressSentinel\Core\Support\WpHelper;

/**
 * Verifies WordPress nonces on state-changing requests.
 *
 * Safe HTTP methods (GET, HEAD, OPTIONS) bypass verification. Every other request must
 * present a nonce that validates against at least one of the configured actions via
 * {@see wp_verify_nonce()}.
 *
 * The middleware is REST-aware: the standard `wp_rest` action (used by the WordPress REST
 * API and signed against the `X-WP-Nonce` header) is always accepted in addition to any
 * application-specific actions, so custom forms, REST clients, and admin-AJAX handlers can
 * coexist without bespoke wiring.
 *
 * Tokens are looked up, in order, from:
 *  - `$context['request']['headers']` (lowercased keys: `x-csrf-token`, `x-wp-nonce`)
 *  - `$_SERVER` (`HTTP_X_CSRF_TOKEN`, `HTTP_X_WP_NONCE`)
 *  - `$context['request']['body']` (`_wpnonce`, `csrf_token`)
 *  - `$_POST` (`_wpnonce`, `csrf_token`)
 *  - `$context['request']['query']` (`_wpnonce`)
 *  - `$_GET` (`_wpnonce`)
 *
 * On success the matching action and lifecycle tick (1 = fresh, 2 = within grace window)
 * are written to `$context['csrf']`. On failure the pipeline is short-circuited and the
 * context is enriched with a 403 response payload for downstream HTTP/kernel layers.
 */
final class CsrfProtectionMiddleware implements MiddlewareInterface
{
    public const DEFAULT_ACTION = 'presssentinel_csrf';

    public const REST_ACTION = 'wp_rest';

    /** @var list<string> */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<string> */
    private const HEADER_CONTEXT_KEYS = ['x-csrf-token', 'x-wp-nonce'];

    /** @var list<string> */
    private const HEADER_SERVER_KEYS = ['HTTP_X_CSRF_TOKEN', 'HTTP_X_WP_NONCE'];

    /** @var list<string> */
    private const BODY_KEYS = ['_wpnonce', 'csrf_token'];

    /** @var list<string> */
    private const QUERY_KEYS = ['_wpnonce'];

    private readonly LoggerInterface $logger;

    /** @var list<string> */
    private readonly array $actions;

    /**
     * @param list<string>|string $actions  Action name(s) accepted in addition to the REST action.
     */
    public function __construct(?LoggerInterface $logger = null, array|string $actions = self::DEFAULT_ACTION)
    {
        $this->logger = $logger ?? new NullLogger();
        $this->actions = $this->normalizeActions($actions);
    }

    public function handle(array $context, callable $next): array
    {
        $method = $this->resolveMethod($context);

        if (in_array($method, self::SAFE_METHODS, true)) {
            return $next($context);
        }

        $token = $this->resolveToken($context);

        if ($token === null) {
            return $this->reject($context, $method, 'missing-token');
        }

        foreach ($this->actions as $action) {
            $tick = WpHelper::verifyNonce($token, $action);
            if ($tick > 0) {
                $context['csrf'] = [
                    'verified' => true,
                    'action' => $action,
                    'tick' => $tick,
                    'fresh' => $tick === 1,
                ];

                return $next($context);
            }
        }

        return $this->reject($context, $method, 'invalid-token');
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function reject(array $context, string $method, string $reason): array
    {
        $this->logger->warning('CSRF verification failed.', [
            'method' => $method,
            'reason' => $reason,
            'rest' => WpHelper::isRestRequest(),
            'ajax' => WpHelper::isDoingAjax(),
        ]);

        $context['csrf'] = [
            'verified' => false,
            'reason' => $reason,
        ];
        $context['response'] = [
            'status' => 403,
            'message' => 'Invalid CSRF token.',
        ];
        $context['halted'] = true;

        return $context;
    }

    /**
     * @param list<string>|string $actions
     * @return list<string>
     */
    private function normalizeActions(array|string $actions): array
    {
        $candidates = is_array($actions) ? $actions : [$actions];

        $userActions = [];
        foreach ($candidates as $action) {
            if (!is_string($action)) {
                continue;
            }
            $action = trim($action);
            if ($action === '' || in_array($action, $userActions, true)) {
                continue;
            }
            $userActions[] = $action;
        }

        if ($userActions === []) {
            $userActions[] = self::DEFAULT_ACTION;
        }

        if (!in_array(self::REST_ACTION, $userActions, true)) {
            $userActions[] = self::REST_ACTION;
        }

        return $userActions;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveMethod(array $context): string
    {
        $request = $context['request'] ?? null;
        if (is_array($request) && isset($request['method']) && is_string($request['method'])) {
            return strtoupper($request['method']);
        }

        $serverMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        if (is_string($serverMethod) && $serverMethod !== '') {
            return strtoupper($serverMethod);
        }

        return 'GET';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveToken(array $context): ?string
    {
        $request = is_array($context['request'] ?? null) ? $context['request'] : [];

        $headers = $request['headers'] ?? null;
        if (is_array($headers)) {
            foreach (self::HEADER_CONTEXT_KEYS as $name) {
                $value = $headers[$name] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        foreach (self::HEADER_SERVER_KEYS as $key) {
            $value = $_SERVER[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return WpHelper::unslash($value);
            }
        }

        $body = $request['body'] ?? null;
        if (is_array($body)) {
            foreach (self::BODY_KEYS as $field) {
                $value = $body[$field] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        foreach (self::BODY_KEYS as $field) {
            $value = $_POST[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return WpHelper::unslash($value);
            }
        }

        $query = $request['query'] ?? null;
        if (is_array($query)) {
            foreach (self::QUERY_KEYS as $field) {
                $value = $query[$field] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        foreach (self::QUERY_KEYS as $field) {
            $value = $_GET[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return WpHelper::unslash($value);
            }
        }

        return null;
    }
}
