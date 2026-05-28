<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Admin\SecurityHeadersSettingsPage;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\View\View;
use NiyiGuard\Tests\Stubs\WpStubState;

/**
 * Covers the wiring that keeps the Security Headers settings page in sync
 * with the dashboard's feature-toggle form. The two views share a single
 * wp_option and must surface the same master `enabled` flag.
 */
final class SecurityHeadersSettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_register_settings_creates_a_master_section_before_the_per_header_sections(): void
    {
        $page = $this->makePage();
        $page->registerSettings();

        // The master section must exist and be registered against the right page.
        self::assertArrayHasKey(SecurityHeadersSettingsPage::SECTION_MASTER, WpStubState::$settingsSections);
        self::assertSame(SecurityHeadersSettingsPage::PAGE_SLUG, WpStubState::$settingsSections[SecurityHeadersSettingsPage::SECTION_MASTER]['page']);

        // And it must come BEFORE the HSTS section in the page's render order
        // — otherwise admins won't see it without scrolling past every
        // sub-section, defeating the whole "make the master visible" fix.
        $ids = array_keys(WpStubState::$settingsSections);
        $masterIdx = array_search(SecurityHeadersSettingsPage::SECTION_MASTER, $ids, true);
        $hstsIdx = array_search(SecurityHeadersSettingsPage::SECTION_HSTS, $ids, true);
        self::assertNotFalse($masterIdx);
        self::assertNotFalse($hstsIdx);
        self::assertLessThan($hstsIdx, $masterIdx, 'master section must be registered before HSTS so it renders first');

        // The master enabled field is wired into the master section.
        self::assertArrayHasKey('master_enabled', WpStubState::$settingsFields);
        self::assertSame(SecurityHeadersSettingsPage::SECTION_MASTER, WpStubState::$settingsFields['master_enabled']['section']);
    }

    public function test_master_field_renders_as_checked_when_option_is_enabled(): void
    {
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = ['enabled' => true];

        $page = $this->makePage();

        ob_start();
        $page->renderMasterEnabled();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('name="' . SecurityHeadersOptions::OPTION_NAME . '[enabled]"', $html);
        self::assertStringContainsString('value="1" checked', $html);
        self::assertStringContainsString('type="hidden"', $html); // unchecked-submits-0 helper
    }

    public function test_master_field_renders_as_unchecked_when_dashboard_disabled_it(): void
    {
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = ['enabled' => false];

        $page = $this->makePage();

        ob_start();
        $page->renderMasterEnabled();
        $html = (string) ob_get_clean();

        // The checkbox name is present but `checked` is not — confirms the
        // dashboard-written state flows into this page's render.
        self::assertStringContainsString('name="' . SecurityHeadersOptions::OPTION_NAME . '[enabled]"', $html);
        self::assertStringNotContainsString('value="1" checked', $html);
    }

    public function test_dashboard_and_settings_page_share_one_wp_option(): void
    {
        $page = $this->makePage();
        $page->registerSettings();

        self::assertArrayHasKey(SecurityHeadersOptions::OPTION_NAME, WpStubState::$registeredOptions);
        self::assertSame(SecurityHeadersSettingsPage::OPTION_GROUP, WpStubState::$registeredOptions[SecurityHeadersOptions::OPTION_NAME]['group']);
    }

    /**
     * End-to-end of the original bug report. The two pages must agree.
     */
    public function test_dashboard_toggle_round_trips_through_settings_page_render(): void
    {
        $options = new SecurityHeadersOptions(new Config());

        // Dashboard turns master OFF.
        $options->setEnabled(false);

        // The settings page now renders.
        $page = new SecurityHeadersSettingsPage($options, $this->makeView());
        ob_start();
        $page->renderMasterEnabled();
        $html = (string) ob_get_clean();

        // The page must reflect the dashboard's state — not silently show
        // "enabled" because of a config default.
        self::assertStringNotContainsString('value="1" checked', $html);

        // Now the admin re-enables the master from the settings page.
        $sanitized = $options->sanitize(['enabled' => '1', 'hsts' => ['enabled' => '1']]);
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = $sanitized;

        $reloaded = new SecurityHeadersOptions(new Config());
        self::assertTrue($reloaded->isEnabled(), 'settings-page submit must propagate the master back to the same option the dashboard reads');
    }

    private function makePage(): SecurityHeadersSettingsPage
    {
        return new SecurityHeadersSettingsPage(
            new SecurityHeadersOptions(new Config()),
            $this->makeView()
        );
    }

    private function makeView(): View
    {
        return new View(\dirname(__DIR__, 3) . '/resources/views');
    }
}
