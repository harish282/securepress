<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Headers;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Headers\HeaderRegistryFactory;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Tests\Stubs\WpStubState;

/**
 * The security-headers feature gained a master `enabled` switch so the
 * NiyiGuard dashboard can turn the whole module off in one click without
 * zeroing out the per-header sub-config. These tests pin down:
 *
 *  - the master defaults to ON (preserves backwards compatibility — every
 *    install that upgrades keeps its existing header behaviour),
 *  - flipping it persists into the wp_option and `isEnabled()` reflects it,
 *  - {@see HeaderRegistryFactory} short-circuits to an empty registry when
 *    the master is off, so the dispatcher emits nothing,
 *  - and the sub-config is preserved across master toggles so an admin
 *    doesn't lose their HSTS/CSP settings when they pause the feature.
 */
final class SecurityHeadersMasterSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_master_switch_defaults_to_enabled(): void
    {
        $options = new SecurityHeadersOptions(new Config());

        self::assertTrue($options->isEnabled());
    }

    public function test_set_enabled_persists_master_switch_only(): void
    {
        $options = new SecurityHeadersOptions(new Config());

        $options->setEnabled(false);

        self::assertFalse($options->isEnabled());

        $stored = WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] ?? null;
        self::assertIsArray($stored);
        self::assertFalse($stored['enabled']);
        // The per-header sub-config still ships its config-file defaults so
        // re-enabling restores the exact same headers without further admin
        // action.
        self::assertSame('SAMEORIGIN', $stored['x_frame_options']['value']);
        self::assertTrue($stored['x_content_type_options']['enabled']);
    }

    public function test_factory_returns_empty_registry_when_master_is_off(): void
    {
        $options = new SecurityHeadersOptions(new Config());
        $options->setEnabled(false);

        $factory = new HeaderRegistryFactory($options);
        $registry = $factory->make();

        self::assertSame(
            [],
            $registry->emit(),
            'When the master switch is off no header should be emitted, regardless of per-header settings.'
        );
        self::assertTrue($registry->isEmpty());
    }

    /**
     * Regression: the dashboard's master toggle (which writes the top-level
     * `enabled` flag) used to get silently re-enabled the next time an admin
     * saved the Security Headers settings page — because that form doesn't
     * post the master field, and `normalize()` fell back to `true` for
     * missing keys. Both views must read AND write the same variable.
     */
    public function test_settings_page_save_preserves_dashboard_disabled_master(): void
    {
        $options = new SecurityHeadersOptions(new Config());

        // Admin turns the master OFF from the dashboard.
        $options->setEnabled(false);
        self::assertFalse($options->isEnabled(), 'precondition: dashboard wrote enabled=false');

        // Admin then opens the Security Headers settings page and toggles HSTS.
        // The Settings API sends the full sub-config but no master field — the
        // master toggle didn't exist on this form historically.
        $formPayload = [
            'hsts' => ['enabled' => '1', 'max_age' => '31536000'],
            'csp' => ['enabled' => '0', 'policy' => "default-src 'self'", 'report_only' => '1'],
            'x_frame_options' => ['enabled' => '1', 'value' => 'SAMEORIGIN'],
            'referrer_policy' => ['enabled' => '1', 'policy' => 'strict-origin-when-cross-origin'],
            'permissions_policy' => ['enabled' => '1', 'policy' => 'geolocation=()'],
            'x_content_type_options' => ['enabled' => '1'],
        ];
        $sanitized = $options->sanitize($formPayload);

        // Persist it the way WordPress would on a register_setting save.
        \NiyiGuard\Tests\Stubs\WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = $sanitized;

        $reloaded = new SecurityHeadersOptions(new Config());

        self::assertFalse(
            $reloaded->isEnabled(),
            'Saving the settings page must NOT re-enable a master switch that the dashboard disabled — partial submissions must preserve out-of-band keys.'
        );
    }

    public function test_factory_emits_headers_again_after_master_re_enabled(): void
    {
        $options = new SecurityHeadersOptions(new Config());
        $factory = new HeaderRegistryFactory($options);

        $options->setEnabled(false);
        self::assertSame([], $factory->make()->emit());

        $options->setEnabled(true);
        $emitted = $factory->make()->emit();

        // We just need to see at least one header back — X-Frame-Options
        // ships on by default, so its presence proves the registry is alive
        // again. The exact header set is tested elsewhere.
        self::assertArrayHasKey('X-Frame-Options', $emitted);
    }
}
