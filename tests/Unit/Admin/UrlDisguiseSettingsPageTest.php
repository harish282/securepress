<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PressSentinel\Admin\UrlDisguiseSettingsPage;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\UrlDisguise\UrlDisguiseOptions;
use PressSentinel\Core\View\View;
use PressSentinel\Tests\Stubs\WpStubState;

final class UrlDisguiseSettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_register_exposes_settings_api_wiring(): void
    {
        $page = new UrlDisguiseSettingsPage(
            new UrlDisguiseOptions(new Config()),
            new View(\dirname(__DIR__, 3) . '/resources/views')
        );
        $page->register();
        $page->registerSettings();

        self::assertArrayHasKey(UrlDisguiseOptions::OPTION_NAME, WpStubState::$registeredOptions);
        self::assertSame(UrlDisguiseSettingsPage::OPTION_GROUP, WpStubState::$registeredOptions[UrlDisguiseOptions::OPTION_NAME]['group']);
        self::assertArrayHasKey(UrlDisguiseSettingsPage::SECTION, WpStubState::$settingsSections);
        self::assertSame(UrlDisguiseSettingsPage::PAGE_SLUG, WpStubState::$settingsSections[UrlDisguiseSettingsPage::SECTION]['page']);
    }
}
