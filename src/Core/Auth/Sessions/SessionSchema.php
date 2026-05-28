<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Sessions;

/**
 * DDL for the NiyiGuard sessions table, mirrored on the audit-log schema pattern.
 *
 * Stored separately from `wp_user_meta['session_tokens']` because that field is
 * (a) opaque/serialised, (b) overwritten on every login, and (c) not designed for
 * arbitrary querying. Our table supports `findActiveForUser()` and pruning natively.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema DDL via dbDelta; DROP uses internal table names only.
final class SessionSchema
{
    public const TABLE = 'niyiguard_sessions';
    public const VERSION_OPTION = 'niyiguard_sessions_db_version';
    public const VERSION = 1;

    public function tableName(): string
    {
        $wpdb = $this->wpdb();
        $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';

        return $prefix . self::TABLE;
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

        if (!$this->runDbDelta($this->createTableSql())) {
            return false;
        }

        \call_user_func('update_option', self::VERSION_OPTION, self::VERSION);

        return true;
    }

    public function uninstall(): void
    {
        $wpdb = $this->wpdb();
        if ($wpdb !== null && method_exists($wpdb, 'query')) {
            $wpdb->query('DROP TABLE IF EXISTS ' . $this->tableName());
        }
        if (\function_exists('delete_option')) {
            \call_user_func('delete_option', self::VERSION_OPTION);
        }
    }

    public function createTableSql(): string
    {
        $table = $this->tableName();
        $charsetCollate = $this->charsetCollate();

        return 'CREATE TABLE ' . $table . ' (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token VARCHAR(64) NOT NULL,
    fingerprint_hash VARCHAR(64) NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    label VARCHAR(120) NULL,
    created_at BIGINT UNSIGNED NOT NULL,
    last_seen_at BIGINT UNSIGNED NOT NULL,
    revoked_at BIGINT UNSIGNED NULL,
    PRIMARY KEY  (id),
    UNIQUE KEY token_idx (token),
    KEY user_idx (user_id, revoked_at),
    KEY fingerprint_idx (user_id, fingerprint_hash)
) ' . $charsetCollate . ';';
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

    private function wpdb(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return is_object($wpdb) ? $wpdb : null;
    }
}
