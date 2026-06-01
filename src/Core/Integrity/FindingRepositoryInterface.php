<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity;

/**
 * Persistence for {@see Finding} rows.
 *
 * The repository is intentionally low-level: it deals with append / mark-reviewed /
 * filter / count / delete. Aggregation and presentation live in the admin controller
 * and the {@see IntegrityService} respectively, so this interface stays narrow enough
 * to back with several stores (array for tests, wpdb for production, plausibly a
 * file-backed log for headless installs).
 *
 * Open findings are findings with `reviewedAt === null`. Findings are never deleted on
 * "review"; the admin marks them resolved, which preserves the audit trail without
 * cluttering the dashboard.
 */
interface FindingRepositoryInterface
{
    public function record(Finding $finding): Finding;

    public function markReviewed(int $id, ?int $now = null): bool;

    public function delete(int $id): bool;

    public function deleteAll(): int;

    /**
     * @return list<Finding>
     */
    public function open(): array;

    /**
     * @return list<Finding>
     */
    public function all(): array;

    public function countOpen(): int;
}
