<?php

declare(strict_types=1);

namespace SecurePress\Core\Support;

final class WpHelper
{
    public static function addAction(string $hook, array $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\function_exists('add_action')) {
            \call_user_func('add_action', $hook, $callback, $priority, $acceptedArgs);
        }
    }

    public static function addFilter(string $hook, array $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\function_exists('add_filter')) {
            \call_user_func('add_filter', $hook, $callback, $priority, $acceptedArgs);
        }
    }

    public static function currentUserCan(string $capability): bool
    {
        if (!\function_exists('current_user_can')) {
            return false;
        }

        return (bool) \call_user_func('current_user_can', $capability);
    }

    public static function getCurrentScreenId(): ?string
    {
        if (!\function_exists('get_current_screen')) {
            return null;
        }

        $screen = \call_user_func('get_current_screen');
        if (!is_object($screen) || !isset($screen->id) || !is_string($screen->id)) {
            return null;
        }

        return $screen->id;
    }

    public static function escapeHtml(string $value): string
    {
        if (\function_exists('esc_html')) {
            return (string) \call_user_func('esc_html', $value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function pluginBasename(string $file): string
    {
        if (\function_exists('plugin_basename')) {
            return (string) \call_user_func('plugin_basename', $file);
        }

        return basename($file);
    }

    public static function isAdmin(): bool
    {
        if (!\function_exists('is_admin')) {
            return false;
        }

        return (bool) \call_user_func('is_admin');
    }

    /**
     * Verifies a WordPress nonce.
     *
     * Returns the lifecycle tick from {@see wp_verify_nonce()}: `1` when the nonce is within
     * its first half-life (fresh), `2` when within its second (stale-but-accepted), or `0`
     * when missing/invalid. Callers that only need a boolean can compare against `> 0`.
     */
    public static function verifyNonce(string $nonce, string $action): int
    {
        if ($nonce === '' || !\function_exists('wp_verify_nonce')) {
            return 0;
        }

        $result = \call_user_func('wp_verify_nonce', $nonce, $action);

        return is_int($result) && $result > 0 ? $result : 0;
    }

    public static function createNonce(string $action): string
    {
        if ($action === '' || !\function_exists('wp_create_nonce')) {
            return '';
        }

        $nonce = \call_user_func('wp_create_nonce', $action);

        return is_string($nonce) ? $nonce : '';
    }

    public static function isDoingAjax(): bool
    {
        if (\function_exists('wp_doing_ajax')) {
            return (bool) \call_user_func('wp_doing_ajax');
        }

        return \defined('DOING_AJAX') && \constant('DOING_AJAX') === true;
    }

    public static function isRestRequest(): bool
    {
        if (\function_exists('wp_is_serving_rest_request')) {
            return (bool) \call_user_func('wp_is_serving_rest_request');
        }

        return \defined('REST_REQUEST') && \constant('REST_REQUEST') === true;
    }

    public static function unslash(string $value): string
    {
        if (\function_exists('wp_unslash')) {
            $unslashed = \call_user_func('wp_unslash', $value);

            return is_string($unslashed) ? $unslashed : $value;
        }

        return stripslashes($value);
    }

    public static function getTransient(string $name): mixed
    {
        if (!\function_exists('get_transient')) {
            return false;
        }

        return \call_user_func('get_transient', $name);
    }

    /**
     * @param mixed $value
     */
    public static function setTransient(string $name, mixed $value, int $expiration): bool
    {
        if (!\function_exists('set_transient')) {
            return false;
        }

        return (bool) \call_user_func('set_transient', $name, $value, max(0, $expiration));
    }

    public static function deleteTransient(string $name): bool
    {
        if (!\function_exists('delete_transient')) {
            return false;
        }

        return (bool) \call_user_func('delete_transient', $name);
    }

    /**
     * Returns the client IP from `REMOTE_ADDR` only.
     *
     * Forwarded headers (`X-Forwarded-For`, `CF-Connecting-IP`, etc.) are intentionally NOT
     * consulted because they are trivially spoofable when the application is not behind a
     * known proxy. Callers that terminate behind a trusted proxy should normalize the IP
     * upstream and pass it through middleware context (`request.ip`) instead of relying on
     * this helper.
     */
    public static function getClientIp(): ?string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($remote) || $remote === '') {
            return null;
        }

        $ip = filter_var($remote, FILTER_VALIDATE_IP);

        return is_string($ip) ? $ip : null;
    }

    /**
     * Returns the WordPress salt for the given scheme, or an empty string if unavailable.
     *
     * Schemes: `auth`, `secure_auth`, `logged_in`, `nonce`. Defaults to `auth` which is
     * appropriate for general-purpose HMAC signing.
     */
    public static function salt(string $scheme = 'auth'): string
    {
        if (!\function_exists('wp_salt')) {
            return '';
        }

        $salt = \call_user_func('wp_salt', $scheme);

        return is_string($salt) ? $salt : '';
    }
}
