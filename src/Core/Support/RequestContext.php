<?php

declare(strict_types=1);

namespace PressSentinel\Core\Support;

/**
 * Cheap, side-effect-free detector for "what kind of WordPress request is this?"
 *
 * Used by module bootstrap code to skip work that can't possibly apply on the
 * current request — e.g., don't register `woocommerce_checkout_process` on a
 * REST API request, don't register `rest_pre_dispatch` on a wp-cron call.
 *
 * The detector is intentionally a tiny static helper, NOT a service in the DI
 * container — the whole point is to be callable before any services are
 * resolved, so it can decide whether they need to be resolved at all.
 *
 * Detection cost: a handful of `defined()`/`function_exists()` calls and a
 * single `$_SERVER` read. Well under one microsecond.
 *
 * Returned kinds (vocabulary kept small on purpose):
 *
 *  - **admin** — `wp-admin/*` (excluding admin-ajax, which is treated as `ajax`).
 *  - **ajax**  — `wp-admin/admin-ajax.php` requests; can ride on either front-end
 *    or admin authority, treated as front-end-ish for protection purposes.
 *  - **rest**  — `/wp-json/*` or `?rest_route=…` requests.
 *  - **cron**  — `wp-cron.php` calls (no user-facing protection needed).
 *  - **cli**   — WP-CLI environment.
 *  - **frontend** — everything else (regular page views).
 */
final class RequestContext
{
    public const ADMIN = 'admin';
    public const AJAX = 'ajax';
    public const REST = 'rest';
    public const CRON = 'cron';
    public const CLI = 'cli';
    public const FRONTEND = 'frontend';

    /** @var string|null Cached result for the current request. */
    private static ?string $cached = null;

    public static function detect(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        if (\defined('WP_CLI') && \constant('WP_CLI')) {
            return self::$cached = self::CLI;
        }
        if (\defined('DOING_CRON') && \constant('DOING_CRON')) {
            return self::$cached = self::CRON;
        }
        if (self::looksLikeRest()) {
            return self::$cached = self::REST;
        }
        if (\defined('DOING_AJAX') && \constant('DOING_AJAX')) {
            return self::$cached = self::AJAX;
        }
        if (\function_exists('wp_doing_ajax') && \call_user_func('wp_doing_ajax')) {
            return self::$cached = self::AJAX;
        }
        if (\function_exists('is_admin') && \call_user_func('is_admin')) {
            return self::$cached = self::ADMIN;
        }

        return self::$cached = self::FRONTEND;
    }

    public static function isAdmin(): bool
    {
        return self::detect() === self::ADMIN;
    }

    public static function isAjax(): bool
    {
        return self::detect() === self::AJAX;
    }

    public static function isRest(): bool
    {
        return self::detect() === self::REST;
    }

    public static function isCron(): bool
    {
        return self::detect() === self::CRON;
    }

    public static function isCli(): bool
    {
        return self::detect() === self::CLI;
    }

    public static function isFrontend(): bool
    {
        return self::detect() === self::FRONTEND;
    }

    /**
     * True when the request is on a path that could plausibly involve user-driven
     * commerce events (checkout submission, registration, cart update). Excludes
     * cron / CLI / admin-only requests.
     */
    public static function isCommerceCapable(): bool
    {
        $kind = self::detect();

        return $kind === self::FRONTEND || $kind === self::AJAX;
    }

    /**
     * Resets the cache. Tests use this to flip the detected context between
     * cases without spinning up a full request.
     */
    public static function reset(): void
    {
        self::$cached = null;
    }

    private static function looksLikeRest(): bool
    {
        if (\defined('REST_REQUEST') && \constant('REST_REQUEST')) {
            return true;
        }
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])
            ? $_SERVER['REQUEST_URI']
            : '';
        if ($uri === '') {
            return false;
        }
        // The /wp-json/ rewrite is the canonical REST entry, while ?rest_route=…
        // is the fallback used when permalinks are off or rewrites are disabled.
        return str_contains($uri, '/wp-json/') || str_contains($uri, '?rest_route=');
    }
}
