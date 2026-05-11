<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SecurePress\Admin\AuthHardeningSettingsPage;
use SecurePress\Core\Auth\AuthHardeningOptions;
use SecurePress\Core\Config\Config;
use SecurePress\Core\View\View;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * Smoke coverage for the admin settings page — verifies the field-render closures
 * produce HTML referencing the canonical option name, and that the registered slug /
 * option-group constants stay aligned with what the WordPress Settings API expects.
 *
 * @see \SecurePress\Admin\AuthHardeningSettingsPage
 */
final class AuthHardeningSettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_field_renderers_emit_html_bound_to_option_name(): void
    {
        $page = $this->page();

        // Each renderer writes directly to the output buffer (matching WP Settings API
        // conventions). We capture the buffer to verify the HTML references the same
        // option name the sanitize callback is registered against.
        $checkbox = $this->capture(static fn () => $page->renderMasterEnabled());
        self::assertStringContainsString(AuthHardeningOptions::OPTION_NAME . '[enabled]', $checkbox);
        self::assertStringContainsString('type="checkbox"', $checkbox);

        $threshold = $this->capture(static fn () => $page->renderSuspicionThreshold());
        self::assertStringContainsString(AuthHardeningOptions::OPTION_NAME . '[suspicion][alert_threshold]', $threshold);
        self::assertStringContainsString('type="number"', $threshold);

        $issuer = $this->capture(static fn () => $page->renderTwoFactorIssuer());
        self::assertStringContainsString(AuthHardeningOptions::OPTION_NAME . '[two_factor][issuer]', $issuer);
        self::assertStringContainsString('value="SecurePress"', $issuer);

        $rule = $this->capture(static fn () => $page->renderSuspicionNewDevice());
        self::assertStringContainsString(AuthHardeningOptions::OPTION_NAME . '[suspicion][rules][new_device]', $rule);
    }

    public function test_renderer_reflects_stored_option_overrides(): void
    {
        WpStubState::$options[AuthHardeningOptions::OPTION_NAME] = [
            'enabled' => false,
            'two_factor' => ['issuer' => 'Acme HQ'],
        ];

        $page = $this->page();

        $checkbox = $this->capture(static fn () => $page->renderMasterEnabled());
        self::assertStringNotContainsString('checked', $checkbox);

        $issuer = $this->capture(static fn () => $page->renderTwoFactorIssuer());
        self::assertStringContainsString('value="Acme HQ"', $issuer);
    }

    public function test_registered_constants_stay_aligned(): void
    {
        // Settings API requires page slug and option group to be plain strings — guard
        // against accidental rename by pinning them here. If you intentionally rename
        // them, update this assertion in the same commit.
        self::assertSame('securepress-authentication', AuthHardeningSettingsPage::PAGE_SLUG);
        self::assertSame('securepress_auth_hardening_group', AuthHardeningSettingsPage::OPTION_GROUP);
        self::assertSame('securepress_auth_hardening', AuthHardeningOptions::OPTION_NAME);
    }

    private function page(): AuthHardeningSettingsPage
    {
        return new AuthHardeningSettingsPage(
            new AuthHardeningOptions(new Config()),
            new View(SECUREPRESS_VIEWS_PATH),
        );
    }

    /**
     * @param callable():void $callable
     */
    private function capture(callable $callable): string
    {
        ob_start();
        try {
            $callable();
        } finally {
            $output = ob_get_clean();
        }

        return is_string($output) ? $output : '';
    }
}
