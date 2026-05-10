<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Sessions;

interface SessionRepositoryInterface
{
    public function create(SessionRecord $session): SessionRecord;

    public function findByToken(string $token): ?SessionRecord;

    public function findById(int $id): ?SessionRecord;

    public function touch(int $id, int $lastSeenAt): void;

    public function revoke(int $id, int $revokedAt): void;

    /**
     * @return list<SessionRecord>
     */
    public function findActiveForUser(int $userId): array;

    /**
     * Hard-deletes records older than the given cutoff. Used by the cron pruner so the
     * table doesn't grow unbounded — long-running sites can rack up hundreds of revoked
     * sessions per user otherwise.
     */
    public function deleteOlderThan(int $cutoffTimestamp): int;
}
