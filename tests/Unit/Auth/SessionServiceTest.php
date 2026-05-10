<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Auth\Sessions\ArraySessionRepository;
use SecurePress\Core\Auth\Sessions\NullSessionDestroyer;
use SecurePress\Core\Auth\Sessions\SessionFingerprinter;
use SecurePress\Core\Auth\Sessions\SessionService;
use SecurePress\Core\Logging\NullLogger;

final class SessionServiceTest extends TestCase
{
    public function test_track_creates_active_session_and_revoke_marks_revoked(): void
    {
        $repo = new ArraySessionRepository();
        $service = new SessionService($repo, new SessionFingerprinter(), new NullLogger(), new NullSessionDestroyer());

        $record = $service->track(42, '203.0.113.10', 'SecurePressTest/1', null, 1_700_000_000);

        self::assertNotNull($record->id);
        self::assertSame(42, $record->userId);
        self::assertTrue($record->isActive());

        $listed = $service->listActive(42);
        self::assertCount(1, $listed);

        self::assertTrue($service->revoke(42, (int) $record->id, 1_700_000_010));
        self::assertSame([], $service->listActive(42));
    }

    public function test_revoke_all_except_current_skips_given_id(): void
    {
        $repo = new ArraySessionRepository();
        $service = new SessionService($repo, new SessionFingerprinter(), new NullLogger(), new NullSessionDestroyer());

        $a = $service->track(9, '203.0.113.10', 'UA', null, 100);
        $b = $service->track(9, '203.0.113.10', 'UA', null, 200);

        self::assertCount(2, $service->listActive(9));

        $count = $service->revokeAllExceptCurrent(9, (int) $a->id, 300);
        self::assertSame(1, $count);

        $remaining = $service->listActive(9);
        self::assertCount(1, $remaining);
        self::assertSame((int) $a->id, (int) $remaining[0]->id);
    }
}
