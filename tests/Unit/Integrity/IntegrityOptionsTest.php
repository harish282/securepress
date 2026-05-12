<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Integrity\FindingSeverity;
use SecurePress\Core\Integrity\IntegrityOptions;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * @see \SecurePress\Core\Integrity\IntegrityOptions
 */
final class IntegrityOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_defaults_from_config_plugin_php(): void
    {
        $options = new IntegrityOptions(new Config());
        $values = $options->all();

        self::assertTrue($values['enabled']);
        self::assertTrue($values['scan_core']);
        self::assertTrue($values['scan_plugins']);
        self::assertFalse($values['scan_themes']);
        self::assertTrue($values['scan_uploads']);
        self::assertSame('daily', $values['cron']['recurrence']);
        self::assertSame(FindingSeverity::HIGH, $values['notifications']['min_severity']);
        self::assertSame(60, $values['retention_days']);
    }

    public function test_wp_option_overrides_config_defaults(): void
    {
        WpStubState::$options[IntegrityOptions::OPTION_NAME] = [
            'enabled' => false,
            'scan_themes' => true,
            'cron' => ['recurrence' => 'hourly'],
            'notifications' => ['min_severity' => 'critical', 'recipients' => 'a@b.com, c@d.com'],
            'retention_days' => 30,
        ];

        $options = new IntegrityOptions(new Config());
        $values = $options->all();

        self::assertFalse($values['enabled']);
        self::assertTrue($values['scan_themes']);
        self::assertSame('hourly', $values['cron']['recurrence']);
        self::assertSame('critical', $values['notifications']['min_severity']);
        self::assertSame(['a@b.com', 'c@d.com'], $values['notifications']['recipients']);
        self::assertSame(30, $values['retention_days']);
    }

    public function test_sanitize_falls_back_to_safe_values_for_garbage(): void
    {
        $options = new IntegrityOptions(new Config());
        $cleaned = $options->sanitize([
            'enabled' => 'yes',
            'cron' => ['recurrence' => 'whenever'],
            'notifications' => ['min_severity' => 'mega-loud', 'recipients' => ['ok@example.com', 'bad', '']],
            'retention_days' => -10,
        ]);

        self::assertTrue($cleaned['enabled']);
        self::assertSame('daily', $cleaned['cron']['recurrence']);
        self::assertSame(FindingSeverity::HIGH, $cleaned['notifications']['min_severity']);
        self::assertSame(['ok@example.com'], $cleaned['notifications']['recipients']);
        self::assertSame(1, $cleaned['retention_days']);
    }

    public function test_isEnabled_reflects_master_switch(): void
    {
        WpStubState::$options[IntegrityOptions::OPTION_NAME] = ['enabled' => false];
        $options = new IntegrityOptions(new Config());
        self::assertFalse($options->isEnabled());

        WpStubState::$options[IntegrityOptions::OPTION_NAME] = ['enabled' => true];
        $options2 = new IntegrityOptions(new Config());
        self::assertTrue($options2->isEnabled());
    }
}
