<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Admin\FeatureRegistry;
use NiyiGuard\Core\Audit\AuditLogOptions;
use NiyiGuard\Core\Auth\AuthHardeningOptions;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\Integrity\IntegrityOptions;
use NiyiGuard\Core\RateLimit\RateLimitOptions;
use NiyiGuard\Core\UrlDisguise\UrlDisguiseOptions;
use NiyiGuard\Core\Support\WpHelper;
use NiyiGuard\Tests\Stubs\WpStubState;
use NiyiGuard\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * @see \NiyiGuard\Admin\FeatureRegistry
 *
 * The FeatureRegistry is the dashboard's source of truth. These tests pin
 * down the three guarantees the dashboard relies on:
 *
 *  1. The full feature list is exposed in a stable, predictable order
 *     (the view iterates it directly).
 *  2. `apply()` flips every feature whose desired state differs and
 *     returns those keys (drives the "N features updated" notice).
 *  3. Unrecognised keys are silently ignored so a stale form field can't
 *     blow up the save flow.
 */
final class FeatureRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_all_returns_every_known_feature_in_stable_order(): void
    {
        $registry = $this->makeRegistry();

        $keys = array_map(static fn ($f) => $f->key, $registry->all());

        self::assertSame(
            [
                'auth_hardening',
                'security_headers',
                'rate_limit',
                'url_disguise',
                'file_integrity',
                'audit_log',
                'woocommerce_protection',
            ],
            $keys,
            'Feature order is part of the dashboard contract — re-ordering shifts the UI; do it consciously.'
        );
    }

    public function test_get_returns_descriptor_by_key_and_null_for_unknown(): void
    {
        $registry = $this->makeRegistry();

        self::assertNotNull($registry->get('audit_log'));
        self::assertSame('Audit Log', $registry->get('audit_log')->label);
        self::assertNull($registry->get('not-a-real-feature'));
    }

    public function test_apply_flips_only_changed_features_and_returns_their_keys(): void
    {
        $registry = $this->makeRegistry();
        // Defaults: every feature ON. Disable two of them.
        $changed = $registry->apply([
            'audit_log' => false,
            'auth_hardening' => false,
            // file_integrity intentionally omitted → unchanged (stays ON).
        ]);

        self::assertEqualsCanonicalizing(['auth_hardening', 'audit_log'], $changed);

        $auditLogStored = WpHelper::getOption(AuditLogOptions::OPTION_NAME);
        self::assertIsArray($auditLogStored);
        self::assertFalse($auditLogStored['enabled']);

        $authStored = WpHelper::getOption(AuthHardeningOptions::OPTION_NAME);
        self::assertIsArray($authStored);
        self::assertFalse($authStored['enabled']);

        // file_integrity wasn't in the payload, so no option write happened
        // for it — the default config-level "on" persists unchanged.
        self::assertFalse(
            isset(WpStubState::$options[IntegrityOptions::OPTION_NAME]),
            'apply() must never write options for features the caller didn\'t mention.'
        );
    }

    public function test_apply_is_a_no_op_when_desired_state_matches_current(): void
    {
        $registry = $this->makeRegistry();

        // Every feature at its default persisted state; ask for no changes.
        $changed = $registry->apply([
            'auth_hardening' => true,
            'security_headers' => true,
            'rate_limit' => false,
            'url_disguise' => false,
            'file_integrity' => true,
            'audit_log' => true,
            'woocommerce_protection' => true,
        ]);

        self::assertSame([], $changed);
        // Nothing was written — we never want to touch wp_options unnecessarily
        // because each write fires a `update_option` action and invalidates
        // the autoload cache.
        self::assertSame([], WpStubState::$options);
    }

    public function test_apply_silently_ignores_unknown_keys(): void
    {
        $registry = $this->makeRegistry();

        $changed = $registry->apply([
            'audit_log' => false,
            'totally-not-real' => false,
            'sql_injection_blocker_3000' => true,
        ]);

        self::assertSame(['audit_log'], $changed);
    }

    private function makeRegistry(): FeatureRegistry
    {
        $config = new Config();

        return new FeatureRegistry(
            new AuditLogOptions($config),
            new AuthHardeningOptions($config),
            new SecurityHeadersOptions($config),
            new IntegrityOptions($config),
            new WooCommerceProtectionOptions($config),
            new RateLimitOptions($config),
            new UrlDisguiseOptions($config),
        );
    }
}
