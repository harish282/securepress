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

    public static function getOption(string $name, mixed $default = false): mixed
    {
        if (!\function_exists('get_option')) {
            return $default;
        }

        return \call_user_func('get_option', $name, $default);
    }

    public static function updateOption(string $name, mixed $value, bool $autoload = true): bool
    {
        if (!\function_exists('update_option')) {
            return false;
        }

        return (bool) \call_user_func('update_option', $name, $value, $autoload);
    }

    public static function deleteOption(string $name): bool
    {
        if (!\function_exists('delete_option')) {
            return false;
        }

        return (bool) \call_user_func('delete_option', $name);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function registerSetting(string $optionGroup, string $optionName, array $args = []): void
    {
        if (\function_exists('register_setting')) {
            \call_user_func('register_setting', $optionGroup, $optionName, $args);
        }
    }

    public static function addSettingsSection(string $id, string $title, callable $callback, string $page): void
    {
        if (\function_exists('add_settings_section')) {
            \call_user_func('add_settings_section', $id, $title, $callback, $page);
        }
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function addSettingsField(string $id, string $title, callable $callback, string $page, string $section = 'default', array $args = []): void
    {
        if (\function_exists('add_settings_field')) {
            \call_user_func('add_settings_field', $id, $title, $callback, $page, $section, $args);
        }
    }

    public static function addOptionsPage(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback): void
    {
        if (\function_exists('add_options_page')) {
            \call_user_func('add_options_page', $pageTitle, $menuTitle, $capability, $menuSlug, $callback);
        }
    }

    public static function escapeAttribute(string $value): string
    {
        if (\function_exists('esc_attr')) {
            return (string) \call_user_func('esc_attr', $value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function escapeTextarea(string $value): string
    {
        if (\function_exists('esc_textarea')) {
            return (string) \call_user_func('esc_textarea', $value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function escapeUrl(string $value): string
    {
        if (\function_exists('esc_url')) {
            return (string) \call_user_func('esc_url', $value);
        }

        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    public static function adminUrl(string $path = ''): string
    {
        if (\function_exists('admin_url')) {
            return (string) \call_user_func('admin_url', $path);
        }

        return '/wp-admin/' . ltrim($path, '/');
    }

    public static function currentUserId(): int
    {
        if (!\function_exists('get_current_user_id')) {
            return 0;
        }

        $id = \call_user_func('get_current_user_id');

        return is_int($id) ? $id : (int) $id;
    }

    public static function currentUserDisplayName(): ?string
    {
        if (!\function_exists('wp_get_current_user')) {
            return null;
        }

        $user = \call_user_func('wp_get_current_user');
        if (!is_object($user)) {
            return null;
        }

        $login = isset($user->user_login) && is_string($user->user_login) ? $user->user_login : '';
        $display = isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';

        $name = $display !== '' ? $display : $login;

        return $name === '' ? null : $name;
    }

    public static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (!is_string($ua) || $ua === '') {
            return null;
        }

        return mb_substr($ua, 0, 255);
    }

    public static function requestUri(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($uri) || $uri === '') {
            return null;
        }

        return mb_substr($uri, 0, 255);
    }

    public static function addManagementPage(string $pageTitle, string $menuTitle, string $capability, string $menuSlug, callable $callback): void
    {
        if (\function_exists('add_management_page')) {
            \call_user_func('add_management_page', $pageTitle, $menuTitle, $capability, $menuSlug, $callback);
        }
    }

    public static function scheduleEvent(int $timestamp, string $recurrence, string $hook): bool
    {
        if (!\function_exists('wp_next_scheduled') || !\function_exists('wp_schedule_event')) {
            return false;
        }

        $next = \call_user_func('wp_next_scheduled', $hook);
        if ($next !== false) {
            return false;
        }

        $result = \call_user_func('wp_schedule_event', $timestamp, $recurrence, $hook);

        return $result !== false;
    }

    public static function clearScheduledHook(string $hook): void
    {
        if (\function_exists('wp_clear_scheduled_hook')) {
            \call_user_func('wp_clear_scheduled_hook', $hook);
        }
    }

    public static function verifyAdminNonce(string $action, string $field = '_wpnonce'): bool
    {
        $nonce = $_REQUEST[$field] ?? '';
        if (!is_string($nonce) || $nonce === '') {
            return false;
        }

        return self::verifyNonce($nonce, $action) > 0;
    }

    public static function safeRedirect(string $url): void
    {
        if (\function_exists('wp_safe_redirect')) {
            \call_user_func('wp_safe_redirect', $url);

            return;
        }

        header('Location: ' . $url);
    }
}
