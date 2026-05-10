<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Auth\Notifications\AuthNotifier;
use SecurePress\Core\Auth\Notifications\ArrayMailer;
use SecurePress\Core\Auth\TwoFactor\ArrayChallengeStore;
use SecurePress\Core\Auth\TwoFactor\ArrayTwoFactorRepository;
use SecurePress\Core\Auth\TwoFactor\EmailOtpProvider;
use SecurePress\Core\Auth\TwoFactor\RecoveryCodeService;
use SecurePress\Core\Auth\TwoFactor\TotpProvider;
use SecurePress\Core\Auth\TwoFactor\TwoFactorMethod;
use SecurePress\Core\Auth\TwoFactor\TwoFactorService;
use SecurePress\Core\Logging\NullLogger;

final class TwoFactorServiceTest extends TestCase
{
    public function test_totp_enrolment_flow(): void
    {
        $users = new ArrayTwoFactorRepository();
        $challenges = new ArrayChallengeStore();
        $totp = new TotpProvider();
        $mailer = new ArrayMailer();
        $notifier = new AuthNotifier($mailer, new NullLogger(), 'Tests', 'https://example.test');

        $service = new TwoFactorService(
            $users,
            $challenges,
            $totp,
            new EmailOtpProvider(),
            new RecoveryCodeService(),
            $notifier,
            new NullLogger(),
            'Tests',
            600,
        );

        $bundle = $service->beginTotpEnrolment('alice@example.test');
        $code = $totp->code($bundle['secret']);

        $confirmed = $service->confirmTotp(5, $bundle['secret'], $code, 'alice@example.test', 'Alice');
        self::assertNotNull($confirmed);
        self::assertCount(8, $confirmed['recovery_codes']);

        $state = $users->find(5);
        self::assertTrue($state->isEnabled());
        self::assertSame(TwoFactorMethod::TOTP, $state->method);
        self::assertNotNull($state->secret);

        self::assertTrue($service->requiresChallenge(5));

        $challenge = $service->startChallenge(5, 'alice@example.test', 'Alice', '203.0.113.10', 'UA', '/wp-admin/', false);
        self::assertSame(TwoFactorMethod::TOTP, $challenge->method);

        $live = $totp->code($state->secret);
        self::assertNotNull($service->verifyChallenge($challenge->token, $live));
        $service->consumeChallenge($challenge->token);
        self::assertNull($service->findChallenge($challenge->token));
    }

    public function test_email_otp_challenge_stores_hash_and_verifies(): void
    {
        $users = new ArrayTwoFactorRepository();
        $challenges = new ArrayChallengeStore();
        $emailOtp = new EmailOtpProvider();

        $users->save(3, \SecurePress\Core\Auth\TwoFactor\TwoFactorState::enabled(
            TwoFactorMethod::EMAIL_OTP,
            null,
            (new RecoveryCodeService(2))->generate()['hashes'],
            time(),
        ));

        $mailer = new ArrayMailer();
        $notifier = new AuthNotifier($mailer, new NullLogger(), 'Tests', 'https://example.test');

        $service = new TwoFactorService(
            $users,
            $challenges,
            new TotpProvider(),
            $emailOtp,
            new RecoveryCodeService(2),
            $notifier,
            new NullLogger(),
            'Tests',
            600,
        );

        $challenge = $service->startChallenge(3, 'bob@example.test', 'Bob', null, null, null, false);

        self::assertSame(TwoFactorMethod::EMAIL_OTP, $challenge->method);
        self::assertNotNull($challenge->otpHash);
        self::assertCount(1, $mailer->sent());

        // OTP plain code was emailed — recover from transient challenge only via verify:
        // We cannot read plaintext from challenge; simulate user typing code by extracting from mail body if we stored it — hack: call generate again inconsistent.

        // Instead generate deterministic: read mail body - our notifier includes code in subject for OTP
        $sent = $mailer->sent()[0];
        preg_match('/(\d{6})/', $sent['subject'], $m);
        self::assertArrayHasKey(1, $m);

        self::assertNotNull($service->verifyChallenge($challenge->token, $m[1]));
    }
}
