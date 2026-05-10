<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Auth\Notifications\AuthNotifier;
use SecurePress\Core\Auth\Notifications\ArrayMailer;
use SecurePress\Core\Logging\NullLogger;

final class AuthNotifierTest extends TestCase
{
    public function test_send_otp_records_mail_payload(): void
    {
        $mailer = new ArrayMailer();
        $notifier = new AuthNotifier($mailer, new NullLogger(), 'Tests', 'https://example.test');

        self::assertTrue($notifier->sendOtpCode('admin@example.test', 'Alice', '123456', 600));

        $sent = $mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('admin@example.test', $sent[0]['to']);
        self::assertStringContainsString('123456', $sent[0]['subject']);
        self::assertStringContainsString('123456', $sent[0]['body']);
    }

    public function test_invalid_email_short_circuits_and_logs(): void
    {
        $mailer = new ArrayMailer();
        $notifier = new AuthNotifier($mailer, new NullLogger(), 'Tests', 'https://example.test');

        self::assertFalse($notifier->sendOtpCode('not-an-email', 'Alice', '123456', 600));
        self::assertSame([], $mailer->sent());
    }
}
