<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Audit\ArrayAuditLogRepository;
use NiyiGuard\Core\Audit\AuditEvent;
use NiyiGuard\Core\Audit\AuditLogQuery;

final class ArrayAuditLogRepositoryTest extends TestCase
{
    public function test_record_assigns_auto_increment_id(): void
    {
        $repo = new ArrayAuditLogRepository();

        $a = $repo->record(AuditEvent::make('a.action', 'auth', 'info'));
        $b = $repo->record(AuditEvent::make('b.action', 'auth', 'info'));
        $c = $repo->record(AuditEvent::make('c.action', 'auth', 'info'));

        self::assertSame(1, $a->id);
        self::assertSame(2, $b->id);
        self::assertSame(3, $c->id);
        self::assertSame(3, $repo->count());
    }

    public function test_paginate_filters_by_category(): void
    {
        $repo = $this->seed();

        $query = new AuditLogQuery();
        $query->category = 'auth';

        $page = $repo->paginate($query);

        self::assertSame(3, $page->total);
        foreach ($page->items as $item) {
            self::assertSame('auth', $item->category);
        }
    }

    public function test_paginate_filters_by_level(): void
    {
        $repo = $this->seed();

        $query = new AuditLogQuery();
        $query->level = 'warning';

        $page = $repo->paginate($query);

        self::assertSame(2, $page->total);
    }

    public function test_paginate_searches_message_and_actor_and_target(): void
    {
        $repo = $this->seed();

        $query = new AuditLogQuery();
        $query->search = 'alice';

        $page = $repo->paginate($query);

        self::assertGreaterThanOrEqual(1, $page->total);
        foreach ($page->items as $event) {
            $blob = strtolower(implode(' ', array_filter([
                $event->actorName,
                $event->message,
                $event->action,
                $event->targetId,
            ])));
            self::assertStringContainsString('alice', $blob);
        }
    }

    public function test_paginate_paginates_in_descending_order_by_default(): void
    {
        $repo = new ArrayAuditLogRepository();
        for ($i = 1; $i <= 5; $i++) {
            $repo->record(AuditEvent::make('event.' . $i)->withOccurredAt(1700000000 + $i));
        }

        $query = new AuditLogQuery();
        $query->perPage = 2;
        $query->page = 1;

        $page = $repo->paginate($query);

        self::assertCount(2, $page->items);
        self::assertSame('event.5', $page->items[0]->action);
        self::assertSame('event.4', $page->items[1]->action);
        self::assertSame(5, $page->total);
        self::assertSame(3, $page->totalPages());
    }

    public function test_paginate_clamps_per_page_into_safe_range(): void
    {
        $repo = $this->seed();

        $query = new AuditLogQuery();
        $query->perPage = 10000; // unreasonable

        $page = $repo->paginate($query);

        self::assertLessThanOrEqual(200, $page->perPage);
    }

    public function test_paginate_filters_by_date_range(): void
    {
        $repo = new ArrayAuditLogRepository();
        $repo->record(AuditEvent::make('old')->withOccurredAt(1_000));
        $repo->record(AuditEvent::make('mid')->withOccurredAt(2_000));
        $repo->record(AuditEvent::make('new')->withOccurredAt(3_000));

        $query = new AuditLogQuery();
        $query->dateFrom = 1_500;
        $query->dateTo = 2_500;

        $page = $repo->paginate($query);

        self::assertSame(1, $page->total);
        self::assertSame('mid', $page->items[0]->action);
    }

    public function test_delete_older_than_only_removes_old_entries(): void
    {
        $repo = new ArrayAuditLogRepository();
        $repo->record(AuditEvent::make('old')->withOccurredAt(1_000));
        $repo->record(AuditEvent::make('keep')->withOccurredAt(5_000));

        $deleted = $repo->deleteOlderThan(2_000);

        self::assertSame(1, $deleted);
        self::assertSame(1, $repo->count());
    }

    public function test_delete_all_clears_storage_and_resets_ids(): void
    {
        $repo = $this->seed();
        self::assertGreaterThan(0, $repo->count());

        $deleted = $repo->deleteAll();
        $next = $repo->record(AuditEvent::make('after.clear'));

        self::assertGreaterThan(0, $deleted);
        self::assertSame(0, $repo->count() - 1);
        self::assertSame(1, $next->id, 'auto-increment resets after deleteAll');
    }

    public function test_find_by_id_returns_event_or_null(): void
    {
        $repo = new ArrayAuditLogRepository();
        $stored = $repo->record(AuditEvent::make('a'));

        self::assertNotNull($repo->findById((int) $stored->id));
        self::assertNull($repo->findById(999));
    }

    private function seed(): ArrayAuditLogRepository
    {
        $repo = new ArrayAuditLogRepository();

        $repo->record(
            AuditEvent::make('user.login.success', 'auth', 'info')
                ->withActor(1, 'Alice')
                ->withMessage('alice signed in')
                ->withOccurredAt(1700000000)
        );
        $repo->record(
            AuditEvent::make('user.login.failed', 'auth', 'warning')
                ->withActor(null, 'bob')
                ->withMessage('bob failed')
                ->withOccurredAt(1700000100)
        );
        $repo->record(
            AuditEvent::make('user.logout', 'auth', 'info')
                ->withActor(1, 'Alice')
                ->withOccurredAt(1700000200)
        );
        $repo->record(
            AuditEvent::make('plugin.activated', 'plugin', 'warning')
                ->withTarget('plugin', 'akismet/akismet.php')
                ->withMessage('plugin activated')
                ->withOccurredAt(1700000300)
        );

        return $repo;
    }
}
