<?php

declare(strict_types=1);

namespace PressSentinel\Core\Audit;

/**
 * DDL + idempotent migration for the audit log table.
 *
 * Uses {@see dbDelta()} so the migration can be re-run safely on plugin updates — `dbDelta`
 * compares the requested schema with the current one and emits only the necessary `ALTER`
 * statements. We track our own `presssentinel_db_version` option so we don't even call
 * `dbDelta` when the schema is already current (saves a DB round-trip on every boot).
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema DDL via dbDelta; DROP uses internal table names only.
final class AuditLogSchema
{
    public const TABLE = 'presssentinel_audit_logs';

    public const VERSION_OPTION = 'presssentinel_audit_log_db_version';

    /**
     * Bump this when the schema changes; the installer will re-run dbDelta.
     */
    public const VERSION = 1;

    public function tableName(): string
    {
        $wpdb = $this->wpdb();
        $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : 'wp_';

        return $prefix . self::TABLE;
    }

    /**
     * Runs the migration if the stored version is older than {@see self::VERSION}.
     *
     * Returns `true` when a migration was actually executed.
     */
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
    occurred_at DATETIME NOT NULL,
    level VARCHAR(20) NOT NULL,
    category VARCHAR(40) NOT NULL,
    action VARCHAR(120) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    actor_name VARCHAR(120) NULL,
    target_type VARCHAR(40) NULL,
    target_id VARCHAR(120) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    request_uri VARCHAR(255) NULL,
    message TEXT NULL,
    context LONGTEXT NULL,
    PRIMARY KEY  (id),
    KEY occurred_at_idx (occurred_at),
    KEY actor_idx (actor_id),
    KEY category_action_idx (category, action),
    KEY level_idx (level),
    KEY target_idx (target_type, target_id)
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
