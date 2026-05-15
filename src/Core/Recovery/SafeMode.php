<?php

declare(strict_types=1);

namespace SecurePress\Core\Recovery;

/**
 * Emergency recovery when operators lock themselves out of wp-login or wp-admin.
 *
 * Enable via the plugin `.env` file (copy from `.env.example`):
 *
 *     SECUREPRESS_SAFE_MODE=true
 *
 * Or in `wp-config.php` **before** WordPress loads plugins (above
 * `require_once ABSPATH . 'wp-settings.php';`):
 *
 *     define('SECUREPRESS_SAFE_MODE', true);
 *
 * A wp-config `define()` takes precedence when it is loaded before the plugin.
 *
 * While active, configured bypasses disable the highest-risk lockout paths without
 * changing stored options — remove or set the constant to `false` once recovery is done.
 *
 * Tests may enable safe mode via the {@see 'securepress_safe_mode'} filter.
 */
final class SafeMode
{
    public const BYPASS_LOGIN_DISGUISE = 'login_disguise';

    public const BYPASS_LOCKOUT = 'lockout';

    public const BYPASS_RATE_LIMIT = 'rate_limit';

    /** @var list<string> */
    private const DEFAULT_BYPASSES = [
        self::BYPASS_LOGIN_DISGUISE,
        self::BYPASS_LOCKOUT,
        self::BYPASS_RATE_LIMIT,
    ];

    public static function isActive(): bool
    {
        if (defined('SECUREPRESS_SAFE_MODE') && SECUREPRESS_SAFE_MODE) {
            return true;
        }

        if (!\function_exists('apply_filters')) {
            return false;
        }

        return (bool) \apply_filters('securepress_safe_mode', false);
    }

    /**
     * Whether safe mode is on and the given recovery bypass applies.
     */
    public static function bypasses(string $feature): bool
    {
        if (!self::isActive()) {
            return false;
        }

        $bypasses = self::DEFAULT_BYPASSES;
        if (\function_exists('apply_filters')) {
            $filtered = \apply_filters('securepress_safe_mode_bypasses', $bypasses);
            if (is_array($filtered)) {
                $bypasses = $filtered;
            }
        }

        return in_array($feature, $bypasses, true);
    }

    /**
     * @return list<string>
     */
    public static function activeBypasses(): array
    {
        if (!self::isActive()) {
            return [];
        }

        $bypasses = self::DEFAULT_BYPASSES;
        if (\function_exists('apply_filters')) {
            $filtered = \apply_filters('securepress_safe_mode_bypasses', $bypasses);
            if (is_array($filtered)) {
                $bypasses = $filtered;
            }
        }

        return array_values($bypasses);
    }
}
