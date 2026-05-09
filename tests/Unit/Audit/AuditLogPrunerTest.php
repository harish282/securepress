<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Audit\ArrayAuditLogRepository;
use SecurePress\Core\Audit\AuditEvent;
use SecurePress\Core\Audit\AuditLogPruner;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Tests\Stubs\WpStubState;

final class AuditLogPrunerTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_prune_deletes_entries_older_than_retention_window(): void
    {
        $repo = new ArrayAuditLogRepository();
        $now = time();

        $repo->record(AuditEvent::make('old')->withOccurredAt($now - (10 * 86400)));
        $repo->record(AuditEvent::make('keep')->withOccurredAt($now - (3 * 86400)));

        $pruner = new AuditLogPruner($repo, new NullLogger(), retentionDays: 7);
        $deleted = $pruner->prune();

        self::assertSame(1, $deleted);
        self::assertSame(1, $repo->count());
    }

    public function test_prune_is_noop_when_retention_is_disabled(): void
    {
        $repo = new ArrayAuditLogRepository();
        $repo->record(AuditEvent::make('ancient')->withOccurredAt(1));

        $pruner = new AuditLogPruner($repo, new NullLogger(), retentionDays: 0);

        self::assertSame(0, $pruner->prune());
        self::assertSame(1, $repo->count(), 'nothing should be deleted with retention=0');
    }

    public function test_register_schedules_daily_event(): void
    {
        $repo = new ArrayAuditLogRepository();
        $pruner = new AuditLogPruner($repo, new NullLogger(), retentionDays: 30);

        $pruner->register();

        self::assertArrayHasKey(AuditLogPruner::HOOK, WpStubState::$scheduledEvents);
        self::assertSame('daily', WpStubState::$scheduledEvents[AuditLogPruner::HOOK]['recurrence']);
    }

    public function test_register_does_not_double_schedule(): void
    {
        $repo = new ArrayAuditLogRepository();
        $pruner = new AuditLogPruner($repo, new NullLogger(), retentionDays: 30);

        $pruner->register();
        $existing = WpStubState::$scheduledEvents[AuditLogPruner::HOOK];

        $pruner->register();

        self::assertSame(
            $existing['timestamp'],
            WpStubState::$scheduledEvents[AuditLogPruner::HOOK]['timestamp'],
            'second register call must not overwrite the existing schedule'
        );
    }
}
