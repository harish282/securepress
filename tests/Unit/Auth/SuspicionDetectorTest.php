<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Auth\Sessions\ArraySessionRepository;
use NiyiGuard\Core\Auth\Sessions\SessionFingerprinter;
use NiyiGuard\Core\Auth\Sessions\SessionRecord;
use NiyiGuard\Core\Auth\SuspiciousLogin\LoginContext;
use NiyiGuard\Core\Auth\SuspiciousLogin\Rules\NewDeviceRule;
use NiyiGuard\Core\Auth\SuspiciousLogin\SuspicionDetector;

final class SuspicionDetectorTest extends TestCase
{
    public function test_new_device_rule_no_signal_when_fingerprint_seen_before(): void
    {
        $repo = new ArraySessionRepository();
        $fingerprinter = new SessionFingerprinter();
        $fp = $fingerprinter->fingerprint('203.0.113.10', 'Mozilla/5.0');

        $repo->create(new SessionRecord(null, 7, 'tok-known', $fp->hash, '203.0.113.10', 'Mozilla/5.0', time(), time()));

        $context = new LoginContext(7, $fp, '203.0.113.10', 'Mozilla/5.0', time());
        $detector = new SuspicionDetector([new NewDeviceRule($repo, 60)]);

        self::assertFalse($detector->evaluate($context)->isSuspicious());
    }

    public function test_new_device_rule_fires_when_repository_empty(): void
    {
        $repo = new ArraySessionRepository();
        $fingerprinter = new SessionFingerprinter();
        $fp = $fingerprinter->fingerprint('203.0.113.10', 'Mozilla/5.0');

        $context = new LoginContext(7, $fp, '203.0.113.10', 'Mozilla/5.0', time());
        $detector = new SuspicionDetector([new NewDeviceRule($repo, 60)]);

        $result = $detector->evaluate($context);
        self::assertTrue($result->isSuspicious());
        self::assertSame(['new_device'], $result->reasons);
    }
}
