<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Auth\TwoFactor\EmailOtpProvider;

final class EmailOtpProviderTest extends TestCase
{
    public function test_generated_code_round_trips(): void
    {
        $provider = new EmailOtpProvider();
        $generated = $provider->generate(1_700_000_000);

        self::assertMatchesRegularExpression('/^\d{6}$/', $generated['code']);
        self::assertTrue($provider->verify($generated['code'], $generated, 1_700_000_000));
    }

    public function test_verify_strips_whitespace_in_user_input(): void
    {
        $provider = new EmailOtpProvider();
        $generated = $provider->generate(1_700_000_000);

        $padded = ' ' . $generated['code'][0] . ' ' . substr($generated['code'], 1) . ' ';
        self::assertTrue($provider->verify($padded, $generated, 1_700_000_000));
    }

    public function test_verify_rejects_expired_entries(): void
    {
        $provider = new EmailOtpProvider(6, 600);
        $generated = $provider->generate(1_700_000_000);

        // 1 second after expiry.
        self::assertFalse($provider->verify($generated['code'], $generated, 1_700_000_000 + 601));
    }

    public function test_verify_rejects_wrong_codes(): void
    {
        $provider = new EmailOtpProvider();
        $generated = $provider->generate(1_700_000_000);
        $wrong = $generated['code'] === '111111' ? '222222' : '111111';

        self::assertFalse($provider->verify($wrong, $generated, 1_700_000_000));
    }

    public function test_ttl_seconds_is_exposed(): void
    {
        $provider = new EmailOtpProvider(6, 1234);
        self::assertSame(1234, $provider->ttlSeconds());
    }
}
