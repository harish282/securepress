<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit;

use SecurePress\Core\Config\Config;
use SecurePress\Core\Support\WpHelper;

/**
 * Resolves the effective audit-log configuration for the current request.
 *
 * Audit logging historically read straight from `config/plugin.php`. That made the
 * `enabled` flag a developer-only concern — admins had no way to flip it from the
 * UI. To bring it in line with the other modules (auth hardening, integrity, WC
 * protection, security headers) we now wrap the same config under an Options
 * facade that overlays a `wp_option`. The dashboard toggle simply writes
 * `enabled => false` here and the audit pipeline picks it up on the next request.
 *
 * Storage shape (single autoloaded option):
 *
 *     [
 *         'enabled'                => bool,
 *         'retention_days'         => int,    // >= 1
 *         'mirror_to_file_logger'  => bool,
 *     ]
 *
 * The `listeners.*` and `option_allowlist` sub-sections stay config-only — they
 * change so rarely (and require code-level review when they do) that surfacing
 * them in the dashboard would just clutter it without giving real value.
 */
final class AuditLogOptions
{
    public const OPTION_NAME = 'securepress_audit_log';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array<string, mixed>
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

        return $this->normalize(array_replace_recursive($defaults, $stored));
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->all()['enabled'] ?? true);
    }

    public function retentionDays(): int
    {
        return (int) ($this->all()['retention_days'] ?? 90);
    }

    public function mirrorToFileLogger(): bool
    {
        return (bool) ($this->all()['mirror_to_file_logger'] ?? false);
    }

    /**
     * Flips just the master `enabled` flag, leaving every other audit-log
     * setting (retention, file-logger mirroring, listener allow-list) intact.
     *
     * This is the single mutation the dashboard exercises — granular settings
     * still belong to the per-feature settings pages where the surrounding
     * context (e.g. "how many days do we keep audit rows?") is visible.
     */
    public function setEnabled(bool $enabled): void
    {
        $current = $this->all();
        $current['enabled'] = $enabled;
        WpHelper::updateOption(self::OPTION_NAME, $current);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        return [
            'enabled' => $this->toBool($raw['enabled'] ?? true),
            'retention_days' => max(1, min(3650, (int) ($raw['retention_days'] ?? 90))),
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
