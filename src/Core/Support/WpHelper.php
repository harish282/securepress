<?php

declare(strict_types=1);

namespace PressSentinel\Core\Support;

final class WpHelper
{
    public static function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        if (\function_exists('add_action')) {
            \call_user_func('add_action', $hook, $callback, $priority, $acceptedArgs);
        }
    }

    public static function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
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

    public static function sanitizeTextField(string $value): string
    {
        if (\function_exists('sanitize_text_field')) {
            return (string) \call_user_func('sanitize_text_field', $value);
        }

        return trim($value);
    }

    /**
     * Normalized {@see $_SERVER} value (unslashed); empty strings become null.
     */
    public static function getServerString(string $key): ?string
    {
        if (!isset($_SERVER[$key]) || !is_scalar($_SERVER[$key])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed and validated by caller.
        $raw = $_SERVER[$key];
        $value = is_string($raw) ? self::unslash($raw) : (string) $raw;

        return $value === '' ? null : $value;
    }

    public static function getRequestMethod(): string
    {
        return strtoupper(self::getServerString('REQUEST_METHOD') ?? 'GET');
    }

    /**
     * Read-only admin/list {@see $_GET} parameter (sanitized).
     */
    public static function getQueryString(string $key, string $default = ''): string
    {
        if (!isset($_GET[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended -- Sanitized below; used for admin filters and pagination.
        $raw = $_GET[$key];

        return self::sanitizeTextField(self::unslash(is_string($raw) ? $raw : (string) $raw));
    }

    public static function getQueryInt(string $key, int $default, int $min, int $max): int
    {
        if (!isset($_GET[$key]) || !is_numeric($_GET[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin list pagination/filter query arg.
        $value = (int) $_GET[$key];

        return max($min, min($max, $value));
    }

    public static function getPostString(string $key, string $default = ''): string
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed and sanitized below; callers verify nonces on mutating requests.
        $raw = $_POST[$key];

        return self::sanitizeTextField(self::unslash(is_string($raw) ? $raw : (string) $raw));
    }

    public static function getRequestString(string $key, string $default = ''): string
    {
        if (!isset($_REQUEST[$key])) {
            return $default;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed and sanitized below.
        $raw = $_REQUEST[$key];

        return self::sanitizeTextField(self::unslash(is_string($raw) ? $raw : (string) $raw));
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

    public static function hasAction(string $hook, mixed $callback = false): bool
    {
        if (!\function_exists('has_action')) {
            return false;
        }

        return (bool) \call_user_func('has_action', $hook, $callback);
    }

    public static function hasFilter(string $hook, mixed $callback = false): bool
    {
        if (!\function_exists('has_filter')) {
            return false;
        }

        return (bool) \call_user_func('has_filter', $hook, $callback);
    }

    /**
     * Performs a GET request against `$url` via `wp_remote_get()` and returns the response
     * body, or `null` when the request errored / returned a non-200 status.
     *
     * Used by the integrity-monitoring checksum provider. Routed through the helper
     * (rather than calling `wp_remote_get` directly) so tests can stub the network call
     * via {@see \PressSentinel\Tests\Stubs\WpStubState}.
     */
    public static function remoteGet(string $url, int $timeoutSeconds = 10): ?string
    {
        if (!\function_exists('wp_remote_get')) {
            return null;
        }

        $response = \call_user_func('wp_remote_get', $url, ['timeout' => $timeoutSeconds]);
        if (!is_array($response)) {
            return null;
        }
        if (\function_exists('is_wp_error') && \call_user_func('is_wp_error', $response)) {
            return null;
        }

        $code = 0;
        if (\function_exists('wp_remote_retrieve_response_code')) {
            $code = (int) \call_user_func('wp_remote_retrieve_response_code', $response);
        } elseif (is_array($response['response'] ?? null) && isset($response['response']['code'])) {
            $code = (int) $response['response']['code'];
        }

        if ($code !== 0 && ($code < 200 || $code >= 300)) {
            return null;
        }

        if (\function_exists('wp_remote_retrieve_body')) {
            $body = \call_user_func('wp_remote_retrieve_body', $response);

            return is_string($body) ? $body : null;
        }

        $body = $response['body'] ?? null;

        return is_string($body) ? $body : null;
    }

    /**
     * Returns the running WordPress version (the `$wp_version` global). Falls back to
     * an empty string when called outside a WP request — callers should treat the empty
     * string as "unknown, skip version-aware behaviour".
     */
    public static function wpVersion(): string
    {
        $version = $GLOBALS['wp_version'] ?? null;

        return is_string($version) ? $version : '';
    }

    /**
     * Returns the absolute filesystem path of `wp-content/uploads`. Empty string when
     * the helper function is unavailable.
     */
    public static function uploadsDir(): string
    {
        if (!\function_exists('wp_upload_dir')) {
            return '';
        }
        $info = \call_user_func('wp_upload_dir');
        if (!is_array($info)) {
            return '';
        }
        $base = $info['basedir'] ?? '';

        return is_string($base) ? $base : '';
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
        $remote = self::getServerString('REMOTE_ADDR');
        if ($remote === null) {
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

    /**
     * @return array<string, mixed>|int|string|null
     */
    public static function parseUrl(string $url, int $component = -1): array|int|string|null
    {
        if (\function_exists('wp_parse_url')) {
            return \call_user_func('wp_parse_url', $url, $component);
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url is unavailable (tests/CLI).
        return parse_url($url, $component);
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
        $ua = self::getServerString('HTTP_USER_AGENT');
        if ($ua === null) {
            return null;
        }

        return mb_substr($ua, 0, 255);
    }

    public static function requestUri(): ?string
    {
        $uri = self::getServerString('REQUEST_URI');
        if ($uri === null) {
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

    /**
     * Registers a WordPress top-level admin menu page.
     *
     * Mirrors {@see add_menu_page()} so callers can stay decoupled from the global WP
     * function (which is undefined during unit tests). The signature intentionally
     * matches WP's argument order — passing through everything but the rarely-used
     * `$function` parameter, which we always derive from `$callback`.
     */
    public static function addMenuPage(
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        callable $callback,
        string $iconUrl = '',
        ?int $position = null,
    ): void {
        if (\function_exists('add_menu_page')) {
            \call_user_func('add_menu_page', $pageTitle, $menuTitle, $capability, $menuSlug, $callback, $iconUrl, $position);
        }
    }

    /**
     * Registers a child admin menu page beneath an existing top-level menu.
     *
     * `$parentSlug` must already have been registered via {@see addMenuPage()} (or be
     * a core WP slug like `tools.php`). Passing the same value for `$parentSlug` and
     * `$menuSlug` is the standard way to override the auto-created first submenu's
     * label — used by {@see \PressSentinel\Admin\PressSentinelMenuPage} to rename the
     * landing item from "Secure Press" to "Dashboard".
     */
    public static function addSubmenuPage(
        string $parentSlug,
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        callable $callback,
        ?int $position = null,
    ): void {
        if (\function_exists('add_submenu_page')) {
            \call_user_func('add_submenu_page', $parentSlug, $pageTitle, $menuTitle, $capability, $menuSlug, $callback, $position);
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
        $nonce = self::getRequestString($field, '');
        if ($nonce === '') {
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

    /**
     * Reads a single user_meta entry, returning the empty string on absence.
     *
     * Always passes `single = true` because the auth-hardening callers store one logical
     * value per key (JSON blobs); the multi-value variant of user_meta is not used here.
     */
    public static function getUserMeta(int $userId, string $key, mixed $default = ''): mixed
    {
        if ($userId <= 0 || !\function_exists('get_user_meta')) {
            return $default;
        }

        $value = \call_user_func('get_user_meta', $userId, $key, true);
        if ($value === '' || $value === false || $value === null) {
            return $default;
        }

        return $value;
    }

    public static function updateUserMeta(int $userId, string $key, mixed $value): bool
    {
        if ($userId <= 0 || !\function_exists('update_user_meta')) {
            return false;
        }

        return (bool) \call_user_func('update_user_meta', $userId, $key, $value);
    }

    public static function deleteUserMeta(int $userId, string $key): bool
    {
        if ($userId <= 0 || !\function_exists('delete_user_meta')) {
            return false;
        }

        return (bool) \call_user_func('delete_user_meta', $userId, $key);
    }

    /**
     * Sends mail through `wp_mail` with optional headers. Returns false if `wp_mail`
     * is not defined (i.e., not in a WordPress runtime).
     *
     * @param array<int, string>|string $to
     * @param array<int, string> $headers
     */
    public static function sendMail(array|string $to, string $subject, string $message, array $headers = []): bool
    {
        if (!\function_exists('wp_mail')) {
            return false;
        }

        return (bool) \call_user_func('wp_mail', $to, $subject, $message, $headers);
    }

    public static function generatePassword(int $length = 12, bool $specialChars = true): string
    {
        if (\function_exists('wp_generate_password')) {
            $value = \call_user_func('wp_generate_password', $length, $specialChars);

            return is_string($value) ? $value : '';
        }

        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ($specialChars) {
            $alphabet .= '!@#$%^&*()_+-=';
        }

        $result = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    public static function setAuthCookie(int $userId, bool $remember = false): void
    {
        if (\function_exists('wp_set_auth_cookie')) {
            \call_user_func('wp_set_auth_cookie', $userId, $remember);
        }
    }

    /**
     * Returns the WP_User-shaped object for the given identifier, or null.
     *
     * `field` matches the second argument to {@see get_user_by()}: `id`, `email`,
     * `login`, or `slug`.
     */
    public static function getUserBy(string $field, mixed $value): ?object
    {
        if (!\function_exists('get_user_by')) {
            return null;
        }

        $user = \call_user_func('get_user_by', $field, $value);

        return is_object($user) ? $user : null;
    }

    public static function loginUrl(string $redirect = ''): string
    {
        if (\function_exists('wp_login_url')) {
            return (string) \call_user_func('wp_login_url', $redirect);
        }

        return '/wp-login.php' . ($redirect !== '' ? '?redirect_to=' . rawurlencode($redirect) : '');
    }

    public static function siteUrl(string $path = ''): string
    {
        if (\function_exists('site_url')) {
            return (string) \call_user_func('site_url', $path);
        }

        return '/' . ltrim($path, '/');
    }

    public static function homeUrl(string $path = ''): string
    {
        if (\function_exists('home_url')) {
            return (string) \call_user_func('home_url', $path);
        }

        return '/' . ltrim($path, '/');
    }

    public static function getQueryVar(string $key, mixed $default = ''): mixed
    {
        if (!\function_exists('get_query_var')) {
            return $default;
        }

        $value = \call_user_func('get_query_var', $key, $default);

        return $value === false ? $default : $value;
    }

    public static function addRewriteRule(string $pattern, string $query, string $after = 'bottom'): void
    {
        if (\function_exists('add_rewrite_rule')) {
            \call_user_func('add_rewrite_rule', $pattern, $query, $after);
        }
    }

    public static function flushRewriteRules(bool $hard = true): void
    {
        if (\function_exists('flush_rewrite_rules')) {
            \call_user_func('flush_rewrite_rules', $hard);
        }
    }

    public static function statusHeader(int $code): void
    {
        if (\function_exists('status_header')) {
            \call_user_func('status_header', $code);
        }
    }

    public static function blogName(): string
    {
        if (\function_exists('get_bloginfo')) {
            $value = \call_user_func('get_bloginfo', 'name');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'WordPress';
    }

    /**
     * Fires a WordPress action with the given arguments. Used by PressSentinel to
     * synthesise `wp_login` after a 2FA-verified login so other listeners (the audit
     * logger, third-party plugins) see the same hook they would on a vanilla flow.
     */
    public static function doAction(string $hook, mixed ...$args): void
    {
        if (\function_exists('do_action')) {
            \call_user_func_array('do_action', [$hook, ...$args]);
        }
    }
}
