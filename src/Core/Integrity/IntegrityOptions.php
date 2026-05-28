<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity;

use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Resolves the effective integrity-monitoring configuration for the current request.
 *
 * Defaults come from `config/plugin.php` (`integrity.*`); the admin Settings page
 * persists overrides into the `wp_option` named by {@see self::OPTION_NAME}. Behaviour
 * mirrors {@see \NiyiGuard\Core\Auth\AuthHardeningOptions} and
 * {@see \NiyiGuard\Core\Headers\SecurityHeadersOptions} so the admin UI glue stays
 * identical across features.
 *
 * Schema (single autoloaded option):
 *
 *     [
 *         'enabled' => bool,
 *         'scan_core' => bool,
 *         'scan_plugins' => bool,
 *         'scan_themes' => bool,
 *         'scan_uploads' => bool,
 *         'cron' => [
 *             'enabled' => bool,
 *             'recurrence' => string,    // 'hourly' | 'twicedaily' | 'daily'
 *         ],
 *         'notifications' => [
 *             'enabled' => bool,
 *             'min_severity' => string,  // see FindingSeverity::*
 *             'recipients' => list<string>,
 *         ],
 *         'retention_days' => int,        // for the findings table
 *     ]
 */
final class IntegrityOptions
{
    public const OPTION_NAME = 'niyiguard_integrity';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $defaults = $this->normalize(
            is_array($this->config->get('integrity')) ? $this->config->get('integrity') : []
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
     * Flips just the master `enabled` flag, preserving every other integrity
     * setting (cron, scope toggles, notifications). Used by the centralized
     * NiyiGuard dashboard.
     */
    public function setEnabled(bool $enabled): void
    {
        $current = $this->all();
        $current['enabled'] = $enabled;
        WpHelper::updateOption(self::OPTION_NAME, $current);
    }

    /**
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
        $cron = is_array($raw['cron'] ?? null) ? $raw['cron'] : [];
        $notifications = is_array($raw['notifications'] ?? null) ? $raw['notifications'] : [];

        $recurrence = (string) ($cron['recurrence'] ?? 'daily');
        if (!in_array($recurrence, ['hourly', 'twicedaily', 'daily'], true)) {
            $recurrence = 'daily';
        }

        $minSeverity = (string) ($notifications['min_severity'] ?? FindingSeverity::HIGH);
        if (!FindingSeverity::isValid($minSeverity)) {
            $minSeverity = FindingSeverity::HIGH;
        }

        $recipients = $this->normalizeRecipients($notifications['recipients'] ?? []);

        return [
            'enabled' => $this->toBool($raw['enabled'] ?? true),
            'scan_core' => $this->toBool($raw['scan_core'] ?? true),
            'scan_plugins' => $this->toBool($raw['scan_plugins'] ?? true),
            'scan_themes' => $this->toBool($raw['scan_themes'] ?? false),
            'scan_uploads' => $this->toBool($raw['scan_uploads'] ?? true),
            'cron' => [
                'enabled' => $this->toBool($cron['enabled'] ?? true),
                'recurrence' => $recurrence,
            ],
            'notifications' => [
                'enabled' => $this->toBool($notifications['enabled'] ?? true),
                'min_severity' => $minSeverity,
                'recipients' => $recipients,
            ],
            'retention_days' => $this->clampInt($raw['retention_days'] ?? 60, 1, 3650),
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

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeRecipients(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $entry = trim($entry);
            if ($entry === '' || in_array($entry, $out, true)) {
                continue;
            }
            // Cheap email shape check — we don't want to ship a half-rendered alert.
            if (!str_contains($entry, '@')) {
                continue;
            }
            $out[] = $entry;
        }

        return $out;
    }
}
