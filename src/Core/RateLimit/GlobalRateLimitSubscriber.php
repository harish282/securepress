<?php

declare(strict_types=1);

namespace SecurePress\Core\RateLimit;

use SecurePress\Core\Support\RequestContext;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Middleware\RateLimitMiddleware;

/**
 * Wires {@see RateLimitMiddleware} into WordPress so the dashboard / settings
 * "global rate limit" toggle actually affects HTTP traffic.
 *
 * Scope (by design):
 *
 *  - **REST API** — `rest_pre_dispatch` so JSON clients receive a proper
 *    {@see \WP_Error} with HTTP 429.
 *  - **Front-end, AJAX, wp-login** — `wp_loaded` at priority 0. We deliberately
 *    **skip** {@see RequestContext::ADMIN} (`wp-admin/*` dashboard loads) so
 *    editors are not locked out by asset-heavy admin screens sharing the same
 *    per-user bucket as the rest of the site.
 *  - **Skipped** — WP-CLI, wp-cron, safe-mode rate bypass.
 *
 * When the master toggle is off, {@see RateLimitMiddleware} is already built
 * with `enabled: false`; this subscriber still short-circuits before building
 * context to avoid unnecessary work.
 */
final class GlobalRateLimitSubscriber
{
    public function __construct(
        private readonly RateLimitMiddleware $middleware,
        private readonly RateLimitOptions $options,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('wp_loaded', [$this, 'onWpLoaded'], 0);
        WpHelper::addFilter('rest_pre_dispatch', [$this, 'onRestPreDispatch'], 10, 3);
    }

    /**
     * @param mixed $result
     * @param mixed $server
     * @param mixed $request
     * @return mixed
     */
    public function onRestPreDispatch(mixed $result, mixed $server, mixed $request): mixed
    {
        unset($server, $request);

        if ($result !== null) {
            return $result;
        }
        if (!$this->options->isEnabled()) {
            return $result;
        }

        $out = $this->runLimiter();
        if (empty($out['halted'])) {
            return $result;
        }

        if (!\class_exists(\WP_Error::class, false)) {
            return $result;
        }

        $retry = (int) ($out['rate_limit']['retry_after'] ?? 60);

        return new \WP_Error(
            'securepress_rate_limit',
            (string) (($out['response'] ?? [])['message'] ?? 'Too many requests.'),
            ['status' => 429, 'retry_after' => $retry]
        );
    }

    public function onWpLoaded(): void
    {
        if (!$this->options->isEnabled()) {
            return;
        }
        if (RequestContext::isRest()) {
            return;
        }
        if (RequestContext::isCron() || RequestContext::isCli()) {
            return;
        }
        if (RequestContext::isAdmin()) {
            return;
        }

        $out = $this->runLimiter();
        if (empty($out['halted'])) {
            return;
        }

        $this->respondHtml429($out);
    }

    /**
     * @return array<string, mixed>
     */
    private function runLimiter(): array
    {
        $context = $this->buildHttpContext();

        return $this->middleware->handle($context, static fn (array $c): array => $c);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHttpContext(): array
    {
        $uid = 0;
        if (\function_exists('wp_get_current_user')) {
            $user = \call_user_func('wp_get_current_user');
            if (is_object($user) && isset($user->ID)) {
                $uid = (int) $user->ID;
            }
        }

        $userPayload = $uid > 0 ? ['id' => $uid] : [];
        $ip = WpHelper::getClientIp() ?? '';

        return [
            'user' => $userPayload,
            'request' => ['ip' => $ip],
        ];
    }

    /**
     * @param array<string, mixed> $out
     */
    private function respondHtml429(array $out): void
    {
        $response = is_array($out['response'] ?? null) ? $out['response'] : [];
        $status = (int) ($response['status'] ?? 429);
        WpHelper::statusHeader($status);

        if (!\headers_sent()) {
            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            foreach ($headers as $name => $value) {
                if (is_string($name) && $name !== '' && is_scalar($value)) {
                    \header($name . ': ' . (string) $value);
                }
            }
        }

        if (\defined('SECUREPRESS_TESTING')) {
            return;
        }

        if (\function_exists('wp_die')) {
            \call_user_func(
                'wp_die',
                (string) ($response['message'] ?? 'Too many requests.'),
                'Too Many Requests',
                ['response' => $status]
            );
        }

        exit;
    }
}
