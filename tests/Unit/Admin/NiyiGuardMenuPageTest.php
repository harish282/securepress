<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use NiyiGuard\Admin\FeatureRegistry;
use NiyiGuard\Admin\NiyiGuardMenuPage;
use NiyiGuard\Core\Audit\AuditLogOptions;
use NiyiGuard\Core\Auth\AuthHardeningOptions;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\Integrity\IntegrityOptions;
use NiyiGuard\Core\RateLimit\RateLimitOptions;
use NiyiGuard\Core\UrlDisguise\UrlDisguiseOptions;
use NiyiGuard\Tests\Stubs\WpDieException;
use NiyiGuard\Tests\Stubs\WpStubState;
use NiyiGuard\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * End-to-end coverage for the dashboard's feature-toggle save handler.
 *
 * The handler is the linchpin of the user-visible "turn things on/off from
 * one place" feature, so these tests model the full POST flow:
 *
 *  - missing capability → wp_die,
 *  - bad nonce → wp_die,
 *  - happy path → options written + redirect with status flag.
 *
 * The page itself is built via `newInstanceWithoutConstructor` because the
 * handler only touches `FeatureRegistry`, so we don't need to spin up the
 * full DI graph (license, dashboard tile data, audit repo, etc.).
 */
final class NiyiGuardMenuPageTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    /**
     * WordPress's `wp_verify_nonce()` (and NiyiGuard's wrapper) reads from
     * $_REQUEST. PHP only auto-populates $_REQUEST on real HTTP boots, so
     * tests have to set both $_POST AND $_REQUEST to model the same nonce
     * arriving via a real form submission.
     */
    private function presentNonce(string $action, string $value): void
    {
        WpStubState::registerNonce($action, $value);
        $_POST['_wpnonce'] = $value;
        $_REQUEST['_wpnonce'] = $value;
    }

    public function test_handle_save_features_persists_changes_and_redirects(): void
    {
        $page = $this->makePage();

        // Grant cap + nonce so the guards pass.
        WpStubState::$currentUserCapabilities = ['manage_options' => true];
        $this->presentNonce(NiyiGuardMenuPage::NONCE_ACTION, 'tk');

        // Form payload: turn audit_log off, leave everything else checked.
        $_POST['features'] = [
            'auth_hardening' => '1',
            'security_headers' => '1',
            'rate_limit' => '1',
            'file_integrity' => '1',
            // audit_log omitted → unchecked.
            'woocommerce_protection' => '1',
        ];

        $page->handleSaveFeatures();

        // Option write happened just for the changed feature.
        $stored = WpStubState::$options[AuditLogOptions::OPTION_NAME] ?? null;
        self::assertIsArray($stored);
        self::assertFalse($stored['enabled']);

        // Redirect to the dashboard with the saved-count status flag.
        self::assertCount(1, WpStubState::$redirects);
        $url = WpStubState::$redirects[0];
        self::assertStringContainsString('page=' . NiyiGuardMenuPage::DASHBOARD_SLUG, $url);
        self::assertStringContainsString(NiyiGuardMenuPage::STATUS_QUERY_KEY . '=saved%3A2', $url);

        $rate = WpStubState::$options[RateLimitOptions::OPTION_NAME] ?? null;
        self::assertIsArray($rate);
        self::assertTrue($rate['enabled']);
    }

    public function test_handle_save_features_no_op_redirect_still_renders_zero(): void
    {
        $page = $this->makePage();
        WpStubState::$currentUserCapabilities = ['manage_options' => true];
        $this->presentNonce(NiyiGuardMenuPage::NONCE_ACTION, 'tk');
        // Every master switch at its default config state (URL disguise defaults off).
        $_POST['features'] = [
            'auth_hardening' => '1',
            'security_headers' => '1',
            'file_integrity' => '1',
            'audit_log' => '1',
            'woocommerce_protection' => '1',
        ];

        $page->handleSaveFeatures();

        self::assertSame([], WpStubState::$options);
        self::assertStringContainsString('saved%3A0', WpStubState::$redirects[0]);
    }

    public function test_handle_save_features_aborts_without_manage_options_capability(): void
    {
        $page = $this->makePage();
        // No capability granted.
        $this->presentNonce(NiyiGuardMenuPage::NONCE_ACTION, 'tk');
        $_POST['features'] = ['audit_log' => '0'];

        try {
            $page->handleSaveFeatures();
            self::fail('Handler must wp_die() without manage_options capability.');
        } catch (WpDieException) {
            // expected
        }

        self::assertSame(403, WpStubState::$wpDieCalls[0]['args']['response']);
        // Nothing written, no redirect issued.
        self::assertSame([], WpStubState::$options);
        self::assertSame([], WpStubState::$redirects);
    }

    public function test_handle_save_features_aborts_on_bad_nonce(): void
    {
        $page = $this->makePage();
        WpStubState::$currentUserCapabilities = ['manage_options' => true];
        // Nonce field present but not registered as valid — the verify_nonce
        // stub looks the token up in WpStubState's nonce store, so an
        // un-registered value fails the check.
        $_POST['_wpnonce'] = 'forged';
        $_REQUEST['_wpnonce'] = 'forged';
        $_POST['features'] = ['audit_log' => '0'];

        $this->expectException(WpDieException::class);
        $page->handleSaveFeatures();
    }

    public function test_handle_save_features_ignores_unknown_keys_in_post(): void
    {
        $page = $this->makePage();
        WpStubState::$currentUserCapabilities = ['manage_options' => true];
        $this->presentNonce(NiyiGuardMenuPage::NONCE_ACTION, 'tk');
        $_POST['features'] = [
            'audit_log' => '1',
            'totally_made_up_feature' => '1',
            'sql_injection_blocker_3000' => '1',
        ];

        $page->handleSaveFeatures(); // must not blow up

        // Only the known key was processed; no surprise wp_options written.
        self::assertArrayNotHasKey('totally_made_up_feature', WpStubState::$options);
        self::assertArrayNotHasKey('sql_injection_blocker_3000', WpStubState::$options);
    }

    /**
     * Builds a NiyiGuardMenuPage with a real FeatureRegistry but a dummy
     * license manager. The handler doesn't touch any of the dashboard-render
     * dependencies, so we only need to inject what `handleSaveFeatures()` and
     * `redirect()` actually reach for.
     */
    private function makePage(): NiyiGuardMenuPage
    {
        $config = new Config();
        $registry = new FeatureRegistry(
            new AuditLogOptions($config),
            new AuthHardeningOptions($config),
            new SecurityHeadersOptions($config),
            new IntegrityOptions($config),
            new WooCommerceProtectionOptions($config),
            new RateLimitOptions($config),
            new UrlDisguiseOptions($config),
        );

        $page = (new ReflectionClass(NiyiGuardMenuPage::class))->newInstanceWithoutConstructor();
        // The page's `register()` and dashboard render paths read other
        // properties, but `handleSaveFeatures()` only reads the registry.
        $featuresProp = (new ReflectionClass(NiyiGuardMenuPage::class))->getProperty('features');
        $featuresProp->setValue($page, $registry);

        return $page;
    }
}
