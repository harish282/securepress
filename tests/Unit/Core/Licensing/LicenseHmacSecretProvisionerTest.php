<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Core\Licensing;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Licensing\LicenseHmacSecretProvisioner;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * @see \SecurePress\Core\Licensing\LicenseHmacSecretProvisioner
 */
final class LicenseHmacSecretProvisionerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME]);
    }

    public function test_ensure_writes_strong_secret_when_missing(): void
    {
        unset(WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME]);

        LicenseHmacSecretProvisioner::ensure();

        $v = WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] ?? null;
        self::assertIsString($v);
        self::assertTrue(LicenseHmacSecretProvisioner::isStoredSecretStrong($v));
    }

    public function test_ensure_is_idempotent_when_secret_already_strong(): void
    {
        $existing = str_repeat('a', 32);
        WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] = $existing;

        LicenseHmacSecretProvisioner::ensure();

        self::assertSame($existing, WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME]);
    }

    public function test_ensure_replaces_weak_secret(): void
    {
        WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] = 'short';

        LicenseHmacSecretProvisioner::ensure();

        $v = (string) (WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] ?? '');
        self::assertNotSame('short', $v);
        self::assertTrue(LicenseHmacSecretProvisioner::isStoredSecretStrong($v));
    }
}
