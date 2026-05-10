<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Sessions;

/**
 * In-memory {@see SessionRepositoryInterface} for tests and ephemeral environments.
 *
 * Mirrors the wpdb implementation's auto-increment + revocation semantics. Token lookup
 * scans linearly because test datasets are tiny; production uses a `KEY token_idx` on
 * the wpdb-backed variant for O(log n) lookups.
 */
final class ArraySessionRepository implements SessionRepositoryInterface
{
    /** @var array<int, SessionRecord> */
    private array $rows = [];

    private int $nextId = 1;

    public function create(SessionRecord $session): SessionRecord
    {
        $id = $this->nextId++;
        $stored = $session->withId($id);
        $this->rows[$id] = $stored;

        return $stored;
    }

    public function findByToken(string $token): ?SessionRecord
    {
        foreach ($this->rows as $row) {
            if (hash_equals($row->token, $token)) {
                return $row;
            }
        }

        return null;
    }

    public function findById(int $id): ?SessionRecord
    {
        return $this->rows[$id] ?? null;
    }

    public function touch(int $id, int $lastSeenAt): void
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null) {
            return;
        }
        $this->rows[$id] = $row->withLastSeen($lastSeenAt);
    }

    public function revoke(int $id, int $revokedAt): void
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null) {
            return;
        }
        $this->rows[$id] = $row->revoke($revokedAt);
    }

    public function findActiveForUser(int $userId): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            if ($row->userId === $userId && $row->isActive()) {
                $out[] = $row;
            }
        }

        // Newest first.
        usort($out, static fn (SessionRecord $a, SessionRecord $b): int => $b->lastSeenAt <=> $a->lastSeenAt);

        return $out;
    }

    public function deleteOlderThan(int $cutoffTimestamp): int
    {
        $deleted = 0;
        foreach ($this->rows as $id => $row) {
            $reference = $row->revokedAt ?? $row->lastSeenAt;
            if ($reference < $cutoffTimestamp) {
                unset($this->rows[$id]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return list<SessionRecord>
     */
    public function all(): array
    {
        return array_values($this->rows);
    }

    public function clear(): void
    {
        $this->rows = [];
        $this->nextId = 1;
    }
}
