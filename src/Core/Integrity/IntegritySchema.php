<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

/**
 * DDL + idempotent migration for the two integrity-monitoring tables.
 *
 * Two tables instead of one because their access patterns are completely different:
 *
 *  - `wp_presssentinel_integrity_baselines` is a wide, write-heavy table: every scan
 *    re-writes the entire baseline rows for one scope. Indexed by `(scope, path)` so
 *    diff lookups are O(log n).
 *  - `wp_presssentinel_integrity_findings` is append-mostly and read by the admin UI.
 *    Indexed by `(severity, created_at)` for the default "highest severity, newest
 *    first" listing.
 *
 * Both follow the same versioning convention as the other plugin schemas — a stored
 * `..._db_version` option is compared on boot, `dbDelta` only runs when it lags.
 */
final class IntegritySchema
{
    public const BASELINE_TABLE = 'presssentinel_integrity_baselines';
    public const FINDING_TABLE = 'presssentinel_integrity_findings';

    public const VERSION_OPTION = 'presssentinel_integrity_db_version';
    public const VERSION = 1;

    public function baselineTable(): string
    {
        return $this->prefix() . self::BASELINE_TABLE;
    }

    public function findingTable(): string
    {
        return $this->prefix() . self::FINDING_TABLE;
    }

    public function install(): bool
    {
        if (!\function_exists('get_option') || !\function_exists('update_option')) {
            return false;
        }
        $current = (int) \call_user_func('get_option', self::VERSION_OPTION, 0);
        if ($current >= self::VERSION) {
            return false;
        }
        if (!$this->runDbDelta($this->baselineSql() . "\n" . $this->findingSql())) {
            return false;
        }
        \call_user_func('update_option', self::VERSION_OPTION, self::VERSION);

        return true;
    }

    public function uninstall(): void
    {
        $wpdb = $this->wpdb();
        if ($wpdb !== null && method_exists($wpdb, 'query')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DROP TABLE uses schema-derived table names.
            $wpdb->query('DROP TABLE IF EXISTS ' . $this->baselineTable());
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DROP TABLE uses schema-derived table names.
            $wpdb->query('DROP TABLE IF EXISTS ' . $this->findingTable());
        }
        if (\function_exists('delete_option')) {
            \call_user_func('delete_option', self::VERSION_OPTION);
        }
    }

    public function baselineSql(): string
    {
        $table = $this->baselineTable();
        $charsetCollate = $this->charsetCollate();

        return 'CREATE TABLE ' . $table . ' (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(32) NOT NULL,
    path VARCHAR(512) NOT NULL,
    hash CHAR(64) NOT NULL,
    size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mtime INT UNSIGNED NOT NULL DEFAULT 0,
    captured_at INT UNSIGNED NOT NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY scope_path (scope, path(190)),
    KEY scope_idx (scope)
) ' . $charsetCollate . ';';
    }

    public function findingSql(): string
    {
        $table = $this->findingTable();
        $charsetCollate = $this->charsetCollate();

        return 'CREATE TABLE ' . $table . ' (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope VARCHAR(32) NOT NULL,
    type VARCHAR(40) NOT NULL,
    severity VARCHAR(16) NOT NULL,
    path VARCHAR(512) NOT NULL,
    message TEXT NULL,
    details LONGTEXT NULL,
    created_at INT UNSIGNED NOT NULL,
    reviewed_at INT UNSIGNED NULL,
    PRIMARY KEY  (id),
    KEY severity_idx (severity),
    KEY scope_type_idx (scope, type),
    KEY created_at_idx (created_at),
    KEY reviewed_idx (reviewed_at)
) ' . $charsetCollate . ';';
    }

    private function prefix(): string
    {
        $wpdb = $this->wpdb();

        return is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';
    }

    private function charsetCollate(): string
    {
        $wpdb = $this->wpdb();
        if ($wpdb !== null && method_exists($wpdb, 'get_charset_collate')) {
            $value = $wpdb->get_charset_collate();
            if (is_string($value)) {
                return $value;
            }
        }

        return '';
    }

    private function runDbDelta(string $sql): bool
    {
        if (!\function_exists('dbDelta')) {
            $upgradeFile = (\defined('ABSPATH') ? (string) \constant('ABSPATH') : '') . 'wp-admin/includes/upgrade.php';
            if ($upgradeFile === '' || !is_readable($upgradeFile)) {
                return false;
            }
            require_once $upgradeFile;
        }
        \call_user_func('dbDelta', $sql);

        return true;
    }

    private function wpdb(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return is_object($wpdb) ? $wpdb : null;
    }
}
