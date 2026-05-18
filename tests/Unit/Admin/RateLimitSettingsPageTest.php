<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PressSentinel\Admin\RateLimitSettingsPage;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\RateLimit\RateLimitOptions;
use PressSentinel\Core\View\View;
use PressSentinel\Tests\Stubs\WpStubState;

/**
 * Confirms the Rate Limiting settings page registers the right
 * section + fields against the right option group, and that its
 * field renderers respect the currently-stored values.
 */
final class RateLimitSettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_register_settings_creates_section_and_three_fields_on_the_page(): void
    {
        $page = $this->makePage();
        $page->registerSettings();

        // Option name + group line up with RateLimitOptions::OPTION_NAME so
        // the page writes to the same wp_option the dashboard toggle reads.
        self::assertArrayHasKey(RateLimitOptions::OPTION_NAME, WpStubState::$registeredOptions);
        self::assertSame(
            RateLimitSettingsPage::OPTION_GROUP,
            WpStubState::$registeredOptions[RateLimitOptions::OPTION_NAME]['group']
        );

        // Single section, three fields, all attached to the same page slug.
        self::assertArrayHasKey(RateLimitSettingsPage::SECTION, WpStubState::$settingsSections);
        self::assertSame(
            RateLimitSettingsPage::PAGE_SLUG,
            WpStubState::$settingsSections[RateLimitSettingsPage::SECTION]['page']
        );

        foreach (['rl_enabled', 'rl_limit', 'rl_window'] as $fieldId) {
            self::assertArrayHasKey($fieldId, WpStubState::$settingsFields, "missing field: $fieldId");
            self::assertSame(
                RateLimitSettingsPage::SECTION,
                WpStubState::$settingsFields[$fieldId]['section'],
                "field $fieldId should live in the master section"
            );
        }
    }

    public function test_enabled_field_renders_checked_when_option_is_on(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => true,
            'limit' => 60,
            'window' => 60,
        ];

        $page = $this->makePage();

        ob_start();
        $page->renderEnabled();
        $html = (string) ob_get_clean();

        self::assertStringContainsString(
            'name="' . RateLimitOptions::OPTION_NAME . '[enabled]"',
            $html
        );
        self::assertStringContainsString('value="1" checked', $html);
        self::assertStringContainsString('type="hidden"', $html); // unchecked-submits-0 helper
    }

    public function test_enabled_field_reflects_dashboard_disabled_state(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => false,
            'limit' => 60,
            'window' => 60,
        ];

        $page = $this->makePage();

        ob_start();
        $page->renderEnabled();
        $html = (string) ob_get_clean();

        // Same variable as the dashboard's master toggle — turning it OFF
        // there should propagate into this checkbox's rendered state.
        self::assertStringNotContainsString('value="1" checked', $html);
    }

    public function test_limit_and_window_fields_render_their_current_values_with_bounds(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => true,
            'limit' => 250,
            'window' => 120,
        ];

        $page = $this->makePage();

        ob_start();
        $page->renderLimit();
        $limitHtml = (string) ob_get_clean();

        ob_start();
        $page->renderWindow();
        $windowHtml = (string) ob_get_clean();

        self::assertStringContainsString('value="250"', $limitHtml);
        self::assertStringContainsString('min="' . RateLimitOptions::LIMIT_MIN . '"', $limitHtml);
        self::assertStringContainsString('max="' . RateLimitOptions::LIMIT_MAX . '"', $limitHtml);

        self::assertStringContainsString('value="120"', $windowHtml);
        self::assertStringContainsString('min="' . RateLimitOptions::WINDOW_MIN . '"', $windowHtml);
        self::assertStringContainsString('max="' . RateLimitOptions::WINDOW_MAX . '"', $windowHtml);
    }

    /**
     * End-to-end of the original feature request: tuning `limit` on this
     * page must round-trip through `RateLimitOptions` so the global
     * middleware binding (and `RateLimitMiddleware`) sees the new value
     * on the next request.
     */
    public function test_setting_a_new_limit_round_trips_through_options(): void
    {
        $options = new RateLimitOptions(new Config());

        $sanitized = $options->sanitize([
            'enabled' => '1',
            'limit' => '120',
            'window' => '300',
        ]);
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = $sanitized;

        $reloaded = new RateLimitOptions(new Config());

        self::assertTrue($reloaded->isEnabled());
        self::assertSame(120, $reloaded->limit());
        self::assertSame(300, $reloaded->window());
    }

    private function makePage(): RateLimitSettingsPage
    {
        return new RateLimitSettingsPage(
            new RateLimitOptions(new Config()),
            new View(\dirname(__DIR__, 3) . '/resources/views')
        );
    }
}
