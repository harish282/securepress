<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Audit\AuditEventLevel;
use SecurePress\Core\Audit\AuditLogOptions;
use SecurePress\Core\Config\Config;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * @see \SecurePress\Core\Audit\AuditLogOptions
 */
final class AuditLogOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_defaults_come_from_config_when_nothing_stored(): void
    {
        $options = new AuditLogOptions(new Config());

        self::assertTrue($options->isEnabled());
        self::assertSame(90, $options->retentionDays());
        self::assertTrue($options->isAutoPruneEnabled());
        self::assertSame('notice', $options->minStorageLevel());
        self::assertFalse($options->mirrorToFileLogger());
    }

    public function test_stored_option_overrides_config_for_enabled_flag(): void
    {
        WpStubState::$options[AuditLogOptions::OPTION_NAME] = ['enabled' => false];

        $options = new AuditLogOptions(new Config());

        self::assertFalse($options->isEnabled());
        self::assertSame(90, $options->retentionDays());
    }

    public function test_set_enabled_persists_only_the_enabled_flag(): void
    {
        $options = new AuditLogOptions(new Config());

        $options->setEnabled(false);

        $stored = WpStubState::$options[AuditLogOptions::OPTION_NAME] ?? null;
        self::assertIsArray($stored);
        self::assertFalse($stored['enabled']);
        self::assertSame(90, $stored['retention_days']);
    }

    public function test_retention_zero_means_keep_forever(): void
    {
        WpStubState::$options[AuditLogOptions::OPTION_NAME] = ['retention_days' => 0];
        $options = new AuditLogOptions(new Config());
        self::assertSame(0, $options->retentionDays());
    }

    public function test_retention_days_clamps_upper_bound(): void
    {
        WpStubState::$options[AuditLogOptions::OPTION_NAME] = ['retention_days' => 99_999];
        $options = new AuditLogOptions(new Config());
        self::assertSame(3650, $options->retentionDays());
    }

    public function test_should_persist_level_respects_minimum(): void
    {
        WpStubState::$options[AuditLogOptions::OPTION_NAME] = ['min_storage_level' => 'warning'];
        $options = new AuditLogOptions(new Config());

        self::assertFalse($options->shouldPersistLevel(AuditEventLevel::INFO));
        self::assertTrue($options->shouldPersistLevel(AuditEventLevel::WARNING));
        self::assertTrue($options->shouldPersistLevel(AuditEventLevel::ERROR));
    }

    public function test_sanitize_normalizes_submitted_values(): void
    {
        $options = new AuditLogOptions(new Config());
        $out = $options->sanitize([
            'retention_days' => '45',
            'auto_prune_enabled' => '1',
            'min_storage_level' => 'error',
            'mirror_to_file_logger' => '0',
        ]);

        self::assertSame(45, $out['retention_days']);
        self::assertTrue($out['auto_prune_enabled']);
        self::assertSame('error', $out['min_storage_level']);
        self::assertFalse($out['mirror_to_file_logger']);
    }
}
