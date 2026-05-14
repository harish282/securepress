<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SecurePress\Admin\FeatureRegistry;
use SecurePress\Core\Audit\AuditLogOptions;
use SecurePress\Core\Auth\AuthHardeningOptions;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Core\Integrity\IntegrityOptions;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Licensing\LicenseValidatorInterface;
use SecurePress\Core\RateLimit\RateLimitOptions;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Tests\Stubs\WpStubState;
use SecurePress\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * @see \SecurePress\Admin\FeatureRegistry
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

        // Everything is ON by default; ask for everything to stay ON.
        $changed = $registry->apply([
            'auth_hardening' => true,
            'security_headers' => true,
            'rate_limit' => true,
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

    public function test_is_pro_reflects_license_manager(): void
    {
        // Default validator: no license = not pro.
        $free = $this->makeRegistry();
        self::assertFalse($free->isPro());

        // Same fixture but with a static "always pro" validator.
        $pro = $this->makeRegistry(isPro: true);
        self::assertTrue($pro->isPro());
    }

    private function makeRegistry(bool $isPro = false): FeatureRegistry
    {
        $config = new Config();
        $validator = new class ($isPro) implements LicenseValidatorInterface {
            public function __construct(private readonly bool $isPro)
            {
            }

            public function validate(string $key): LicenseStatus
            {
                return $this->isPro
                    ? LicenseStatus::active('pro', null)
                    : LicenseStatus::none();
            }
        };
        if ($isPro) {
            // Any non-empty key triggers the validator above.
            WpStubState::$options[LicenseManager::OPTION_NAME] = 'KEY-FOR-TESTING';
        }

        return new FeatureRegistry(
            new AuditLogOptions($config),
            new AuthHardeningOptions($config),
            new SecurityHeadersOptions($config),
            new IntegrityOptions($config),
            new WooCommerceProtectionOptions($config),
            new RateLimitOptions($config),
            new LicenseManager($validator, new Config()),
        );
    }
}
