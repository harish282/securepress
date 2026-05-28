<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit;

/**
 * Persistence contract for audit log entries.
 *
 * Two implementations ship today:
 *  - {@see WpdbAuditLogRepository} — production-grade, persists to a custom DB table.
 *  - {@see ArrayAuditLogRepository} — in-memory, used in unit tests.
 *
 * The interface is deliberately narrow — listeners only need {@see record()}, the admin UI
 * needs {@see paginate()} + {@see findById()}, and house-keeping needs the prune / count
 * methods. Anything richer (faceted aggregations, time-series rollups, etc.) should live in
 * a dedicated reporting service rather than swelling this interface.
 */
interface AuditLogRepositoryInterface
{
    public function record(AuditEvent $event): AuditEvent;

    public function findById(int $id): ?AuditEvent;

    public function paginate(AuditLogQuery $query): AuditLogPage;

    public function count(): int;

    public function deleteAll(): int;

    /**
     * Deletes every entry whose `occurred_at` is strictly older than `$olderThan` (UTC unix).
     */
    public function deleteOlderThan(int $olderThan): int;
}
