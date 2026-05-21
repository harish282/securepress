<?php

declare(strict_types=1);

namespace PressSentinel\Core\Licensing;

use PressSentinel\Core\Config\Config;
use PressSentinel\Core\Support\WpHelper;

/**
 * Time-boxed Pro access for public beta installs that do not yet have a paid
 * license key.
 *
 * The trial window is stored in the WordPress database (`presssentinel_beta_trial_started_at`
 * option + duration from config), not in a short-lived transient, so object-cache
 * flushes cannot silently reset the clock. The clock starts the first time
 * {@see self::trialEndsAt()} runs with an empty license key and trial mode enabled
 * in config — typically the first front-end or admin request after activation.
 * The end timestamp is `started_at + duration_days` (from {@see Config}, clamped to 1–730 days).
 *
 * Operators can disable the programme entirely via
 * `pro_license.beta_trial.enabled` in `config/plugin.php`, or override at
 * deploy time with the `PRESS_SENTINEL_BETA_TRIAL_ENABLED` environment variable
 * (the test suite sets this to `false` so unit tests never accidentally flip
 * into Pro mode).
 */
final class BetaTrial
{
    public const STARTED_AT_OPTION = 'presssentinel_beta_trial_started_at';

    public static function isProgrammeEnabled(Config $config): bool
    {
        return (bool) $config->get('pro_license.beta_trial.enabled', false);
    }

    /**
     * Unix timestamp when the trial window closes, or null when the programme
     * is disabled or the clock has not been initialised yet.
     */
    public static function trialEndsAt(Config $config): ?int
    {
        if (!self::isProgrammeEnabled($config)) {
            return null;
        }

        self::ensureStarted();

        $started = self::readStartedAt();
        if ($started <= 0) {
            return null;
        }

        return $started + self::durationSeconds($config);
    }

    public static function isWithinWindow(Config $config, ?int $now = null): bool
    {
        $end = self::trialEndsAt($config);
        if ($end === null) {
            return false;
        }
        $now ??= time();

        return $now < $end;
    }

    private static function ensureStarted(): void
    {
        if (self::readStartedAt() > 0) {
            return;
        }
        WpHelper::updateOption(self::STARTED_AT_OPTION, time());
    }

    private static function readStartedAt(): int
    {
        $raw = WpHelper::getOption(self::STARTED_AT_OPTION, 0);

        return is_numeric($raw) ? (int) $raw : 0;
    }

    private static function durationSeconds(Config $config): int
    {
        $days = (int) $config->get('pro_license.beta_trial.duration_days', 182);
        $days = max(1, min(730, $days));

        return $days * 86400;
    }
}
