<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Licensing;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Licensing\LocalLicenseValidator;

/**
 * @see \SecurePress\Core\Licensing\LocalLicenseValidator
 */
final class LocalLicenseValidatorTest extends TestCase
{
    public function test_validates_an_issued_key(): void
    {
        $validator = new LocalLicenseValidator('test-secret');
        $key = $validator->issue('PRO', 1714780800, 1746316800);

        $status = $validator->validate($key);

        self::assertTrue($status->isActive() || $status->state === LicenseStatus::STATE_EXPIRED);
        self::assertSame('pro', $status->tier);
    }

    public function test_validates_active_key_in_future(): void
    {
        $validator = new LocalLicenseValidator('test-secret');
        $future = time() + 86400 * 30;
        $key = $validator->issue('PRO', time(), $future);

        $status = $validator->validate($key);

        self::assertTrue($status->isActive());
        self::assertSame('pro', $status->tier);
        self::assertSame($future, $status->expiresAt);
    }

    public function test_perpetual_key_when_expires_at_is_zero(): void
    {
        $validator = new LocalLicenseValidator('test-secret');
        $key = $validator->issue('PRO', time(), 0);

        $status = $validator->validate($key);

        self::assertTrue($status->isActive());
        self::assertNull($status->expiresAt);
    }

    public function test_expired_key_reports_expired_state(): void
    {
        $validator = new LocalLicenseValidator('test-secret');
        $key = $validator->issue('PRO', time() - 86400 * 60, time() - 86400);

        $status = $validator->validate($key);

        self::assertSame(LicenseStatus::STATE_EXPIRED, $status->state);
        self::assertFalse($status->isActive());
    }

    public function test_tampered_key_reports_invalid(): void
    {
        $validator = new LocalLicenseValidator('test-secret');
        $key = $validator->issue('PRO', time(), time() + 86400);
        // Flip a hex char in the HMAC suffix.
        $tampered = substr($key, 0, -1) . ($key[-1] === '0' ? '1' : '0');

        $status = $validator->validate($tampered);

        self::assertSame(LicenseStatus::STATE_INVALID, $status->state);
        self::assertSame('Signature mismatch.', $status->reason);
    }

    public function test_malformed_key_is_invalid(): void
    {
        $validator = new LocalLicenseValidator('test-secret');

        $status = $validator->validate('not-a-license');

        self::assertSame(LicenseStatus::STATE_INVALID, $status->state);
    }

    public function test_empty_key_returns_none_status(): void
    {
        $validator = new LocalLicenseValidator('secret');

        self::assertSame(LicenseStatus::STATE_NONE, $validator->validate('')->state);
        self::assertSame(LicenseStatus::STATE_NONE, $validator->validate('   ')->state);
    }

    public function test_different_secret_rejects_key(): void
    {
        $issuer = new LocalLicenseValidator('vendor-secret');
        $key = $issuer->issue('PRO', time(), time() + 86400);

        $other = new LocalLicenseValidator('attacker-secret');
        self::assertSame(LicenseStatus::STATE_INVALID, $other->validate($key)->state);
    }
}
