<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Licensing;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Licensing\LocalLicenseValidator;
use SecurePress\Core\Licensing\StaticLicenseValidator;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * @see \SecurePress\Core\Licensing\LicenseManager
 */
final class LicenseManagerTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::$options = [];
        putenv(LicenseManager::ENV_VAR);
    }

    protected function tearDown(): void
    {
        putenv(LicenseManager::ENV_VAR);
        WpStubState::$options = [];
    }

    public function test_is_not_pro_without_any_key(): void
    {
        $manager = new LicenseManager(new LocalLicenseValidator('secret'));

        self::assertFalse($manager->isPro());
        self::assertSame(LicenseStatus::STATE_NONE, $manager->status()->state);
    }

    public function test_is_pro_with_valid_option_key(): void
    {
        $validator = new LocalLicenseValidator('secret');
        $key = $validator->issue('PRO', time(), time() + 86400 * 30);
        WpStubState::$options[LicenseManager::OPTION_NAME] = $key;

        $manager = new LicenseManager($validator);

        self::assertTrue($manager->isPro());
        self::assertSame('pro', $manager->tier());
    }

    public function test_invalid_option_key_is_not_pro(): void
    {
        WpStubState::$options[LicenseManager::OPTION_NAME] = 'garbage';
        $manager = new LicenseManager(new LocalLicenseValidator('secret'));

        self::assertFalse($manager->isPro());
        self::assertSame(LicenseStatus::STATE_INVALID, $manager->status()->state);
    }

    public function test_env_var_overrides_when_option_missing(): void
    {
        $validator = new LocalLicenseValidator('secret');
        $key = $validator->issue('PRO', time(), time() + 86400);
        putenv(LicenseManager::ENV_VAR . '=' . $key);

        $manager = new LicenseManager($validator);

        self::assertTrue($manager->isPro());
    }

    public function test_static_validator_accepts_allowlisted_key(): void
    {
        WpStubState::$options[LicenseManager::OPTION_NAME] = 'TEST-1234';
        $manager = new LicenseManager(new StaticLicenseValidator([
            'TEST-1234' => ['tier' => 'pro'],
        ]));

        self::assertTrue($manager->isPro());
        self::assertSame('pro', $manager->tier());
    }

    public function test_set_license_persists_and_returns_status(): void
    {
        $validator = new LocalLicenseValidator('secret');
        $key = $validator->issue('AGENCY', time(), time() + 86400);

        $manager = new LicenseManager($validator);
        $status = $manager->setLicense($key);

        self::assertTrue($status->isActive());
        self::assertSame('agency', $status->tier);
        self::assertSame($key, WpStubState::$options[LicenseManager::OPTION_NAME]);
    }

    public function test_clear_license_removes_option_and_resets_status(): void
    {
        $validator = new LocalLicenseValidator('secret');
        $key = $validator->issue('PRO', time(), time() + 86400);
        WpStubState::$options[LicenseManager::OPTION_NAME] = $key;

        $manager = new LicenseManager($validator);
        self::assertTrue($manager->isPro());

        $manager->clearLicense();

        self::assertArrayNotHasKey(LicenseManager::OPTION_NAME, WpStubState::$options);
        self::assertFalse($manager->isPro());
    }

    public function test_status_is_cached_within_request(): void
    {
        $validator = new class implements \SecurePress\Core\Licensing\LicenseValidatorInterface {
            public int $calls = 0;
            public function validate(string $key): LicenseStatus
            {
                $this->calls++;
                return LicenseStatus::active('pro', null);
            }
        };
        WpStubState::$options[LicenseManager::OPTION_NAME] = 'anything';

        $manager = new LicenseManager($validator);
        $manager->isPro();
        $manager->status();
        $manager->tier();

        self::assertSame(1, $validator->calls);
    }
}
