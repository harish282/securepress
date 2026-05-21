<?php

declare(strict_types=1);

namespace PressSentinel\Core\Recovery;

/**
 * Emergency recovery when operators lock themselves out of wp-login or wp-admin.
 *
 * Enable in `wp-config.php` **before** WordPress loads plugins (above
 * `require_once ABSPATH . 'wp-settings.php';`):
 *
 *     define('PRESS_SENTINEL_SAFE_MODE', true);
 *
 * Or set `recovery.safe_mode` to `true` in config/plugin.php (wp-config wins if both are set).
 *
 * While active, configured bypasses disable the highest-risk lockout paths without
 * changing stored options — remove or disable once recovery is done.
 *
 * Tests may enable safe mode via the {@see 'presssentinel_safe_mode'} filter.
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
        if (defined('PRESS_SENTINEL_SAFE_MODE') && PRESS_SENTINEL_SAFE_MODE) {
            return true;
        }

        if (!\function_exists('apply_filters')) {
            return false;
        }

        return (bool) \apply_filters('presssentinel_safe_mode', false);
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
            $filtered = \apply_filters('presssentinel_safe_mode_bypasses', $bypasses);
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
            $filtered = \apply_filters('presssentinel_safe_mode_bypasses', $bypasses);
            if (is_array($filtered)) {
                $bypasses = $filtered;
            }
        }

        return array_values($bypasses);
    }
}
