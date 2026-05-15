<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SecurePress\Admin\AuditLogSettingsPage;
use SecurePress\Core\Audit\AuditLogOptions;
use SecurePress\Core\Config\Config;
use SecurePress\Core\View\View;
use SecurePress\Tests\Stubs\WpStubState;

final class AuditLogSettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_register_exposes_settings_api_wiring(): void
    {
        $page = new AuditLogSettingsPage(
            new AuditLogOptions(new Config()),
            new View(\dirname(__DIR__, 3) . '/resources/views')
        );
        $page->register();
        $page->registerSettings();

        self::assertArrayHasKey(AuditLogOptions::OPTION_NAME, WpStubState::$registeredOptions);
        self::assertSame(AuditLogSettingsPage::OPTION_GROUP, WpStubState::$registeredOptions[AuditLogOptions::OPTION_NAME]['group']);
        self::assertArrayHasKey(AuditLogSettingsPage::SECTION, WpStubState::$settingsSections);
    }
}
