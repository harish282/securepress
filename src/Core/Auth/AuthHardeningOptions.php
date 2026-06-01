<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth;

use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Resolves the effective authentication-hardening configuration for the current request.
 *
 * Defaults come from `config/plugin.php` (`auth_hardening.*`); the admin Settings page
 * persists overrides into the `wp_option` named by {@see self::OPTION_NAME}. This class
 * reads both and returns a fully-merged, type-coerced array so every consumer
 * (DI factories, the kernel, test cases) sees the same canonical shape.
 *
 * Why route through one option instead of one-per-toggle:
 *  - Single autoloaded read on boot rather than ~10 separate `get_option` calls.
 *  - Atomic save semantics from the admin page — partial writes can't leave the system
 *    in an inconsistent half-on/half-off state.
 *  - Mirrors the {@see \NiyiGuard\Core\Headers\SecurityHeadersOptions} contract so the
 *    settings-page glue is familiar.
 *
 * Storage shape (single autoloaded option):
 *
 *     [
 *         'enabled' => bool,
 *         'lockout' => [
 *             'enabled'         => bool,
 *             'max_attempts'    => int,    // >= 1
 *             'window_seconds'  => int,    // >= 60
 *             'lock_seconds'    => int,    // >= 60
 *         ],
 *         'sessions' => [
 *             'enabled'        => bool,
 *             'retention_days' => int,    // >= 1
 *         ],
 *         'suspicion' => [
 *             'enabled'         => bool,
 *             'alert_threshold' => int,    // 0..200
 *             'rules' => ['new_device' => bool],
 *         ],
 *         'two_factor' => [
 *             'issuer'                => string,
 *             'challenge_ttl_seconds' => int,    // >= 60
 *         ],
 *         'notifications' => ['enabled' => bool],
 *     ]
 */
final class AuthHardeningOptions
{
    public const OPTION_NAME = 'niyiguard_auth_hardening';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $defaults = $this->normalize(
            is_array($this->config->get('auth_hardening')) ? $this->config->get('auth_hardening') : []
        );

        $stored = WpHelper::getOption(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return $this->normalize(array_replace_recursive($defaults, $stored));
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->all()['enabled'] ?? true);
    }

    /**
     * Flips just the master `enabled` flag, preserving every other auth
     * setting. Used by the centralized NiyiGuard dashboard so admins don't
     * have to dig into the full settings page to disable the module.
     */
    public function setEnabled(bool $enabled): void
    {
        $current = $this->all();
        $current['enabled'] = $enabled;
        WpHelper::updateOption(self::OPTION_NAME, $current);
    }

    /**
     * Sanitises the raw POST payload from the Settings page into the canonical shape.
     * Used as the `register_setting()` sanitize callback.
     *
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        return $this->normalize($input);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $lockout = is_array($raw['lockout'] ?? null) ? $raw['lockout'] : [];
        $sessions = is_array($raw['sessions'] ?? null) ? $raw['sessions'] : [];
        $suspicion = is_array($raw['suspicion'] ?? null) ? $raw['suspicion'] : [];
        $rules = is_array($suspicion['rules'] ?? null) ? $suspicion['rules'] : [];
        $twoFactor = is_array($raw['two_factor'] ?? null) ? $raw['two_factor'] : [];
        $notifications = is_array($raw['notifications'] ?? null) ? $raw['notifications'] : [];

        return [
            'enabled' => $this->toBool($raw['enabled'] ?? true),
            'lockout' => [
                'enabled' => $this->toBool($lockout['enabled'] ?? true),
                'max_attempts' => $this->clampInt($lockout['max_attempts'] ?? 5, 1, 100),
                'window_seconds' => $this->clampInt($lockout['window_seconds'] ?? 900, 60, 86_400),
                'lock_seconds' => $this->clampInt($lockout['lock_seconds'] ?? 900, 60, 86_400),
            ],
            'sessions' => [
                'enabled' => $this->toBool($sessions['enabled'] ?? true),
                'retention_days' => $this->clampInt($sessions['retention_days'] ?? 90, 1, 3650),
            ],
            'suspicion' => [
                'enabled' => $this->toBool($suspicion['enabled'] ?? true),
                'alert_threshold' => $this->clampInt($suspicion['alert_threshold'] ?? 50, 0, 200),
                'rules' => [
                    'new_device' => $this->toBool($rules['new_device'] ?? true),
                ],
            ],
            'two_factor' => [
                'issuer' => $this->normalizeIssuer($twoFactor['issuer'] ?? null),
                'challenge_ttl_seconds' => $this->clampInt($twoFactor['challenge_ttl_seconds'] ?? 600, 60, 3600),
            ],
            'notifications' => [
                'enabled' => $this->toBool($notifications['enabled'] ?? true),
            ],
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $candidate = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $candidate));
    }

    private function normalizeIssuer(mixed $value): string
    {
        $candidate = is_string($value) ? trim($value) : '';
        if ($candidate === '') {
            return 'NiyiGuard';
        }

        // Issuer ends up in the otpauth:// URI label — control characters and `:` would
        // break parsing in some authenticator apps. Strip them here so the admin can't
        // accidentally save a value that breaks every user's enrolment.
        $candidate = preg_replace('/[\x00-\x1F\x7F:]+/', '', $candidate) ?? 'NiyiGuard';

        return mb_substr($candidate, 0, 64);
    }
}
