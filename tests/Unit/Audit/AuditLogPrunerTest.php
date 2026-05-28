<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Audit\ArrayAuditLogRepository;
use NiyiGuard\Core\Audit\AuditEvent;
use NiyiGuard\Core\Audit\AuditLogOptions;
use NiyiGuard\Core\Audit\AuditLogPruner;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Logging\NullLogger;
use NiyiGuard\Tests\Stubs\WpStubState;

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

        $pruner = $this->makePruner($repo, ['retention_days' => 7]);
        $deleted = $pruner->prune();

        self::assertSame(1, $deleted);
        self::assertSame(1, $repo->count());
    }

    public function test_prune_is_noop_when_retention_is_zero(): void
    {
        $repo = new ArrayAuditLogRepository();
        $repo->record(AuditEvent::make('ancient')->withOccurredAt(1));

        $pruner = $this->makePruner($repo, ['retention_days' => 0]);

        self::assertSame(0, $pruner->prune(manual: true));
        self::assertSame(1, $repo->count());
    }

    public function test_scheduled_prune_skipped_when_auto_prune_disabled(): void
    {
        $repo = new ArrayAuditLogRepository();
        $repo->record(AuditEvent::make('old')->withOccurredAt(time() - (30 * 86400)));

        $pruner = $this->makePruner($repo, ['retention_days' => 7, 'auto_prune_enabled' => false]);
        self::assertSame(0, $pruner->prune());
        self::assertSame(1, $repo->count());
    }

    public function test_manual_prune_runs_when_auto_prune_disabled(): void
    {
        $repo = new ArrayAuditLogRepository();
        $repo->record(AuditEvent::make('old')->withOccurredAt(time() - (30 * 86400)));

        $pruner = $this->makePruner($repo, ['retention_days' => 7, 'auto_prune_enabled' => false]);
        self::assertSame(1, $pruner->prune(manual: true));
    }

    public function test_register_schedules_daily_event_when_auto_prune_on(): void
    {
        $pruner = $this->makePruner(null, ['retention_days' => 30, 'auto_prune_enabled' => true]);
        $pruner->register();

        self::assertArrayHasKey(AuditLogPruner::HOOK, WpStubState::$scheduledEvents);
        self::assertSame('daily', WpStubState::$scheduledEvents[AuditLogPruner::HOOK]['recurrence']);
    }

    public function test_sync_schedule_clears_cron_when_auto_prune_off(): void
    {
        $pruner = $this->makePruner(null, ['retention_days' => 30, 'auto_prune_enabled' => true]);
        $pruner->register();
        self::assertArrayHasKey(AuditLogPruner::HOOK, WpStubState::$scheduledEvents);

        $pruner = $this->makePruner(null, ['retention_days' => 30, 'auto_prune_enabled' => false]);
        $pruner->syncSchedule();

        self::assertArrayNotHasKey(AuditLogPruner::HOOK, WpStubState::$scheduledEvents);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makePruner(?ArrayAuditLogRepository $repo = null, array $overrides = []): AuditLogPruner
    {
        WpStubState::$options[AuditLogOptions::OPTION_NAME] = array_replace([
            'enabled' => true,
            'retention_days' => 7,
            'auto_prune_enabled' => true,
            'min_storage_level' => 'info',
            'mirror_to_file_logger' => false,
        ], $overrides);

        return new AuditLogPruner(
            $repo ?? new ArrayAuditLogRepository(),
            new NullLogger(),
            new AuditLogOptions(new Config()),
        );
    }
}
