<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit;

use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Resolves the effective audit-log configuration for the current request.
 *
 * Storage shape (single autoloaded option):
 *
 *     [
 *         'enabled'                => bool,
 *         'retention_days'         => int,    // 0 = keep forever; 1–3650 when pruning
 *         'auto_prune_enabled'     => bool,   // daily cron deletes rows past retention
 *         'min_storage_level'      => string, // PSR-3 level; events below are not stored in DB
 *         'mirror_to_file_logger'  => bool,
 *     ]
 *
 * The `listeners.*` and `option_allowlist` sub-sections stay config-only.
 */
final class AuditLogOptions
{
    public const OPTION_NAME = 'niyiguard_audit_log';

    public const RETENTION_MIN = 0;

    public const RETENTION_MAX = 3650;

    /** Default minimum severity persisted to the database (reduces row volume on busy sites). */
    public const DEFAULT_MIN_STORAGE_LEVEL = AuditEventLevel::NOTICE;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{
     *     enabled: bool,
     *     retention_days: int,
     *     auto_prune_enabled: bool,
     *     min_storage_level: string,
     *     mirror_to_file_logger: bool
     * }
     */
    public function all(): array
    {
        $defaults = $this->normalize(
            is_array($this->config->get('audit_log')) ? $this->config->get('audit_log') : []
        );

        $stored = WpHelper::getOption(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return $this->normalize(array_replace($defaults, $stored));
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->all()['enabled'] ?? true);
    }

    public function retentionDays(): int
    {
        return (int) ($this->all()['retention_days'] ?? 90);
    }

    public function isAutoPruneEnabled(): bool
    {
        return (bool) ($this->all()['auto_prune_enabled'] ?? true);
    }

    public function minStorageLevel(): string
    {
        return (string) ($this->all()['min_storage_level'] ?? self::DEFAULT_MIN_STORAGE_LEVEL);
    }

    public function shouldPersistLevel(string $level): bool
    {
        return AuditEventLevel::isAtLeast($level, $this->minStorageLevel());
    }

    public function mirrorToFileLogger(): bool
    {
        return (bool) ($this->all()['mirror_to_file_logger'] ?? false);
    }

    public function setEnabled(bool $enabled): void
    {
        $current = $this->all();
        $current['enabled'] = $enabled;
        WpHelper::updateOption(self::OPTION_NAME, $current);
    }

    /**
     * @param mixed $input
     * @return array{
     *     enabled: bool,
     *     retention_days: int,
     *     auto_prune_enabled: bool,
     *     min_storage_level: string,
     *     mirror_to_file_logger: bool
     * }
     */
    public function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        return $this->normalize(array_replace($this->all(), $input));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{
     *     enabled: bool,
     *     retention_days: int,
     *     auto_prune_enabled: bool,
     *     min_storage_level: string,
     *     mirror_to_file_logger: bool
     * }
     */
    private function normalize(array $raw): array
    {
        $retention = is_numeric($raw['retention_days'] ?? 90)
            ? (int) $raw['retention_days']
            : 90;

        return [
            'enabled' => $this->toBool($raw['enabled'] ?? true),
            'retention_days' => max(self::RETENTION_MIN, min(self::RETENTION_MAX, $retention)),
            'auto_prune_enabled' => $this->toBool($raw['auto_prune_enabled'] ?? true),
            'min_storage_level' => AuditEventLevel::normalize(
                is_string($raw['min_storage_level'] ?? '') ? $raw['min_storage_level'] : self::DEFAULT_MIN_STORAGE_LEVEL,
                self::DEFAULT_MIN_STORAGE_LEVEL
            ),
            'mirror_to_file_logger' => $this->toBool($raw['mirror_to_file_logger'] ?? false),
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
}
