<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Audit\AuditLogSchema;
use NiyiGuard\Tests\Stubs\WpStubState;

final class AuditLogSchemaTest extends TestCase
{
    /** @var mixed */
    private mixed $wpdbBackup;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->wpdbBackup = $GLOBALS['wpdb'] ?? null;
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
        if ($this->wpdbBackup === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->wpdbBackup;
        }
    }

    public function test_table_name_uses_wp_prefix(): void
    {
        $GLOBALS['wpdb'] = (object) ['prefix' => 'foo_'];

        $schema = new AuditLogSchema();

        self::assertSame('foo_niyiguard_audit_logs', $schema->tableName());
    }

    public function test_table_name_falls_back_when_wpdb_unavailable(): void
    {
        unset($GLOBALS['wpdb']);

        $schema = new AuditLogSchema();

        self::assertSame('wp_niyiguard_audit_logs', $schema->tableName());
    }

    public function test_create_table_sql_contains_required_columns_and_indexes(): void
    {
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        $sql = (new AuditLogSchema())->createTableSql();

        // Columns
        self::assertStringContainsString('id BIGINT UNSIGNED', $sql);
        self::assertStringContainsString('occurred_at DATETIME NOT NULL', $sql);
        self::assertStringContainsString('level VARCHAR(20)', $sql);
        self::assertStringContainsString('category VARCHAR(40)', $sql);
        self::assertStringContainsString('action VARCHAR(120)', $sql);
        self::assertStringContainsString('actor_id BIGINT UNSIGNED', $sql);
        self::assertStringContainsString('context LONGTEXT', $sql);

        // Indexes
        self::assertStringContainsString('PRIMARY KEY  (id)', $sql);
        self::assertStringContainsString('KEY occurred_at_idx', $sql);
        self::assertStringContainsString('KEY category_action_idx', $sql);
        self::assertStringContainsString('KEY level_idx', $sql);

        // Uses CREATE TABLE syntax dbDelta requires (no IF NOT EXISTS, two spaces in PRIMARY KEY)
        self::assertStringContainsString('CREATE TABLE wp_niyiguard_audit_logs (', $sql);
        self::assertStringNotContainsString('IF NOT EXISTS', $sql);
    }

    public function test_install_skips_when_version_already_current(): void
    {
        WpStubState::$options[AuditLogSchema::VERSION_OPTION] = AuditLogSchema::VERSION;

        $schema = new AuditLogSchema();

        self::assertFalse($schema->install(), 'install should be a no-op when DB version is current');
    }
}
