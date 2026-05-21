<?php

declare(strict_types=1);

namespace PressSentinel\Core\Audit;

/**
 * In-memory implementation of {@see AuditLogRepositoryInterface} for tests.
 *
 * The query semantics mirror the real WPDB-backed repository (filter -> sort -> paginate).
 * Implementations of paginate / search are intentionally simple: contains-match on action /
 * message / actor name, exact-match on category / level / actor id, range on dates.
 */
final class ArrayAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var array<int, AuditEvent> */
    private array $events = [];

    private int $autoIncrement = 0;

    public function record(AuditEvent $event): AuditEvent
    {
        $this->autoIncrement++;
        $stored = $event->withId($this->autoIncrement);
        $this->events[$stored->id] = $stored;

        return $stored;
    }

    public function findById(int $id): ?AuditEvent
    {
        return $this->events[$id] ?? null;
    }

    public function paginate(AuditLogQuery $query): AuditLogPage
    {
        $query = $query->withDefaults();

        $matched = array_values(array_filter(
            $this->events,
            fn (AuditEvent $event): bool => $this->matches($event, $query)
        ));

        usort(
            $matched,
            fn (AuditEvent $a, AuditEvent $b): int => $query->direction === 'asc'
                ? $a->occurredAt <=> $b->occurredAt
                : $b->occurredAt <=> $a->occurredAt
        );

        $total = count($matched);
        $offset = $query->offset();
        $items = array_slice($matched, $offset, $query->perPage);

        return new AuditLogPage(
            items: array_values($items),
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function deleteAll(): int
    {
        $count = count($this->events);
        $this->events = [];
        $this->autoIncrement = 0;

        return $count;
    }

    public function deleteOlderThan(int $olderThan): int
    {
        $deleted = 0;
        foreach ($this->events as $id => $event) {
            if ($event->occurredAt < $olderThan) {
                unset($this->events[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function matches(AuditEvent $event, AuditLogQuery $query): bool
    {
        if ($query->category !== null && $event->category !== $query->category) {
            return false;
        }
        if ($query->level !== null && $event->level !== $query->level) {
            return false;
        }
        if ($query->actorId !== null && $event->actorId !== $query->actorId) {
            return false;
        }
        if ($query->dateFrom !== null && $event->occurredAt < $query->dateFrom) {
            return false;
        }
        if ($query->dateTo !== null && $event->occurredAt > $query->dateTo) {
            return false;
        }
        if ($query->search !== null && $query->search !== '') {
            $needle = strtolower($query->search);
            $haystack = strtolower(implode(' ', array_filter([
                $event->action,
                $event->message,
                $event->actorName,
                $event->targetType,
                $event->targetId,
            ])));
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }
}
