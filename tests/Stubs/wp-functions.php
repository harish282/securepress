<?php

declare(strict_types=1);

use PressSentinel\Tests\Stubs\WpStubState;

/**
 * Lightweight stand-ins for the WordPress functions consumed via {@see PressSentinel\Core\Support\WpHelper}.
 *
 * Behavior is driven by {@see WpStubState}; tests reset and configure that state per-case so the
 * helper's `function_exists` checks pass and exercise the real WP-aware code paths.
 */
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action): int|false
    {
        $tick = WpStubState::tickFor($action, $nonce);

        return $tick > 0 ? $tick : false;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return WpStubState::nextNonce($action);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_string($value)) {
            return stripslashes($value);
        }

        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return $value;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return $url;
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string
    {
        return $data;
    }
}

if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax(): bool
    {
        return WpStubState::$isDoingAjax;
    }
}

if (!function_exists('wp_is_serving_rest_request')) {
    function wp_is_serving_rest_request(): bool
    {
        return WpStubState::$isRestRequest;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $name): mixed
    {
        return WpStubState::getTransient($name);
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $name, mixed $value, int $expiration = 0): bool
    {
        return WpStubState::setTransient($name, $value, $expiration);
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $name): bool
    {
        return WpStubState::deleteTransient($name);
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return WpStubState::saltFor($scheme);
    }
}

if (!function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return WpStubState::getOption($name, $default);
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, mixed $value, bool $autoload = true): bool
    {
        unset($autoload);

        return WpStubState::updateOption($name, $value);
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        return WpStubState::deleteOption($name);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return WpStubState::$currentUserId;
    }
}

if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user(): object
    {
        $data = WpStubState::$currentUser ?? ['ID' => 0, 'user_login' => '', 'display_name' => ''];

        return (object) $data;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook): int|false
    {
        return WpStubState::$scheduledEvents[$hook]['timestamp'] ?? false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
    {
        WpStubState::$scheduledEvents[$hook] = [
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
        ];

        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook): void
    {
        unset(WpStubState::$scheduledEvents[$hook]);
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        WpStubState::recordAction($hook, $callback, $priority, $acceptedArgs);

        return true;
    }
}

if (!function_exists('has_action')) {
    function has_action(string $hook, mixed $callback = false): bool
    {
        if ($callback !== false) {
            return WpStubState::hasAction($hook);
        }

        return WpStubState::hasAction($hook);
    }
}

if (!function_exists('has_filter')) {
    function has_filter(string $hook, mixed $callback = false): bool
    {
        if ($callback !== false) {
            return WpStubState::hasFilter($hook);
        }

        return WpStubState::hasFilter($hook);
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return WpStubState::$isAdmin;
    }
}

if (!class_exists('WP_Error', false)) {
    final class WP_Error
    {
        /**
         * @param array<string, mixed> $data
         */
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = [],
        ) {
        }
    }
}

if (!function_exists('wp_die')) {
    function wp_die(string $message = '', string $title = '', array|int $args = []): void
    {
        WpStubState::recordWpDie($message, $title, is_array($args) ? $args : []);
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $url, int $status = 302): bool
    {
        WpStubState::recordRedirect($url);
        unset($status);

        return true;
    }
}

if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action, string $name = '_wpnonce'): string
    {
        echo '<input type="hidden" name="' . $name . '" value="nonce_' . $action . '">';

        return '';
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return isset(WpStubState::$currentUserCapabilities[$capability]);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        WpStubState::recordFilter($hook, $callback, $priority, $acceptedArgs);

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return WpStubState::applyFilters($hook, $value, ...$args);
    }
}

if (!function_exists('remove_all_filters')) {
    function remove_all_filters(string $hook): void
    {
        WpStubState::removeAllFilters($hook);
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        unset($hook, $args);
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta(int $userId, string $key = '', bool $single = false): mixed
    {
        unset($single);

        return WpStubState::getUserMeta($userId, $key, '');
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta(int $userId, string $key, mixed $value): bool
    {
        WpStubState::setUserMeta($userId, $key, $value);

        return true;
    }
}

if (!function_exists('delete_user_meta')) {
    function delete_user_meta(int $userId, string $key): bool
    {
        return WpStubState::deleteUserMeta($userId, $key);
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail(array|string $to, string $subject, string $message, array $headers = []): bool
    {
        WpStubState::recordMail($to, $subject, $message, $headers);

        return true;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $specialChars = true): string
    {
        unset($specialChars);

        return str_repeat('x', max(1, $length));
    }
}

if (!function_exists('wp_set_auth_cookie')) {
    function wp_set_auth_cookie(int $userId, bool $remember = false): void
    {
        WpStubState::$authCookies[] = ['user_id' => $userId, 'remember' => $remember];
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by(string $field, mixed $value): object|false
    {
        if ($field === 'login') {
            return WpStubState::$usersByLogin[(string) $value] ?? false;
        }
        if ($field === 'id' || $field === 'ID') {
            return WpStubState::$usersById[(int) $value] ?? false;
        }

        return false;
    }
}

if (!function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = ''): string
    {
        return WpStubState::$siteUrl . '/wp-login.php' . ($redirect !== '' ? '?redirect_to=' . rawurlencode($redirect) : '');
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        return WpStubState::$siteUrl . '/' . ltrim($path, '/');
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $key = 'name'): string
    {
        return $key === 'name' ? WpStubState::$blogName : '';
    }
}

if (!function_exists('register_setting')) {
    function register_setting(string $optionGroup, string $optionName, array $args = []): void
    {
        WpStubState::$registeredOptions[$optionName] = [
            'group' => $optionGroup,
            'args' => $args,
        ];
    }
}

if (!function_exists('add_settings_section')) {
    function add_settings_section(string $id, string $title, callable $callback, string $page): void
    {
        WpStubState::$settingsSections[$id] = [
            'title' => $title,
            'callback' => $callback,
            'page' => $page,
        ];
    }
}

if (!function_exists('add_settings_field')) {
    function add_settings_field(string $id, string $title, callable $callback, string $page, string $section = 'default'): void
    {
        WpStubState::$settingsFields[$id] = [
            'title' => $title,
            'callback' => $callback,
            'page' => $page,
            'section' => $section,
        ];
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return WpStubState::$siteUrl . '/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        $base = WpStubState::$homeUrl ?? WpStubState::$siteUrl;

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('get_query_var')) {
    function get_query_var(string $key, mixed $default = ''): mixed
    {
        if (array_key_exists($key, WpStubState::$queryVars)) {
            return WpStubState::$queryVars[$key];
        }

        return $default;
    }
}

if (!function_exists('add_rewrite_rule')) {
    function add_rewrite_rule(string $pattern, string $query, string $after = 'bottom'): void
    {
        WpStubState::$rewriteRulesAdded[] = [
            'pattern' => $pattern,
            'query' => $query,
            'after' => $after,
        ];
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void
    {
        unset($hard);
        WpStubState::$flushRewriteRulesCalls++;
    }
}
