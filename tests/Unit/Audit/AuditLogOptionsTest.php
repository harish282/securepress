<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
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

        // config/plugin.php ships audit_log.enabled = true and retention 90 days.
        self::assertTrue($options->isEnabled());
        self::assertSame(90, $options->retentionDays());
        self::assertFalse($options->mirrorToFileLogger());
    }

    public function test_stored_option_overrides_config_for_enabled_flag(): void
    {
        WpStubState::$options[AuditLogOptions::OPTION_NAME] = ['enabled' => false];

        $options = new AuditLogOptions(new Config());

        self::assertFalse(
            $options->isEnabled(),
            'wp_option override should win over config/plugin.php so the dashboard toggle takes effect.'
        );
        // Other defaults survive the partial override.
        self::assertSame(90, $options->retentionDays());
    }

    public function test_set_enabled_persists_only_the_enabled_flag(): void
    {
        $options = new AuditLogOptions(new Config());

        $options->setEnabled(false);

        $stored = WpStubState::$options[AuditLogOptions::OPTION_NAME] ?? null;
        self::assertIsArray($stored);
        self::assertFalse($stored['enabled']);
        // Other sub-keys round-trip from the config defaults so re-enabling
        // doesn't lose retention configuration.
        self::assertSame(90, $stored['retention_days']);
    }

    public function test_set_enabled_round_trip_restores_state(): void
    {
        $options = new AuditLogOptions(new Config());
        self::assertTrue($options->isEnabled());

        $options->setEnabled(false);
        self::assertFalse($options->isEnabled());

        $options->setEnabled(true);
        self::assertTrue($options->isEnabled());
    }

    public function test_retention_days_clamps_to_valid_range(): void
    {
        WpStubState::$options[AuditLogOptions::OPTION_NAME] = ['retention_days' => 0];
        $options = new AuditLogOptions(new Config());
        self::assertSame(
            1,
            $options->retentionDays(),
            'Zero retention would mean "delete every row" — clamp to a sane minimum so a fat-fingered config can never wipe the audit log.'
        );

        WpStubState::$options[AuditLogOptions::OPTION_NAME] = ['retention_days' => 99_999];
        $options = new AuditLogOptions(new Config());
        self::assertSame(3650, $options->retentionDays(), 'Cap at 10 years so we never silently round to overflow.');
    }
}
