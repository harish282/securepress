<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Auth\TwoFactor\Base32;
use NiyiGuard\Core\Auth\TwoFactor\TotpProvider;

final class TotpProviderTest extends TestCase
{
    public function test_known_rfc6238_vector(): void
    {
        // RFC 6238 §B test vector: secret "12345678901234567890", T = 59 -> 287082 (SHA-1)
        $totp = new TotpProvider(6, 30, 'sha1');
        $secret = Base32::encode('12345678901234567890');

        self::assertSame('287082', $totp->code($secret, 59));
    }

    public function test_round_trip_generated_code_verifies(): void
    {
        $totp = new TotpProvider();
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;

        self::assertTrue($totp->verify($secret, $totp->code($secret, $now), 1, $now));
    }

    public function test_verify_accepts_codes_within_skew_window(): void
    {
        $totp = new TotpProvider();
        $secret = $totp->generateSecret();
        $base = 1_700_000_000;

        $previousCode = $totp->code($secret, $base - 30);
        $nextCode = $totp->code($secret, $base + 30);

        self::assertTrue($totp->verify($secret, $previousCode, 1, $base));
        self::assertTrue($totp->verify($secret, $nextCode, 1, $base));
    }

    public function test_verify_rejects_codes_outside_skew_window(): void
    {
        $totp = new TotpProvider();
        $secret = $totp->generateSecret();
        $base = 1_700_000_000;

        $tooOld = $totp->code($secret, $base - 120);

        self::assertFalse($totp->verify($secret, $tooOld, 1, $base));
    }

    public function test_verify_rejects_non_digit_or_wrong_length_input(): void
    {
        $totp = new TotpProvider();
        $secret = $totp->generateSecret();

        self::assertFalse($totp->verify($secret, '12345'));
        self::assertFalse($totp->verify($secret, '1234567'));
        self::assertFalse($totp->verify($secret, 'abcdef'));
        self::assertFalse($totp->verify($secret, ''));
    }

    public function test_provisioning_uri_includes_required_parameters(): void
    {
        $totp = new TotpProvider();
        $uri = $totp->provisioningUri('NiyiGuard', 'alice@example.com', 'JBSWY3DPEHPK3PXP');

        self::assertStringStartsWith('otpauth://totp/NiyiGuard:alice%40example.com?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=NiyiGuard', $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
    }
}
