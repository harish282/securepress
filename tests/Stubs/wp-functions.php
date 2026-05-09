<?php

declare(strict_types=1);

use SecurePress\Tests\Stubs\WpStubState;

/**
 * Lightweight stand-ins for the WordPress functions consumed via {@see SecurePress\Core\Support\WpHelper}.
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
