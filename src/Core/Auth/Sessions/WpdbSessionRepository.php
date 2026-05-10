<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Sessions;

/**
 * Production session repository backed by `$wpdb`.
 *
 * Modelled on {@see \SecurePress\Core\Audit\WpdbAuditLogRepository} so the access
 * patterns are familiar:
 *  - All raw input goes through `$wpdb->prepare` to defeat injection.
 *  - Reads use `ARRAY_A` and `SessionRecord::fromRow()` to keep the public boundary
 *    typed.
 *  - Writes return useful information (the new id; the affected row count) so callers
 *    don't have to do follow-up SELECTs.
 */
final class WpdbSessionRepository implements SessionRepositoryInterface
{
    public function __construct(private readonly SessionSchema $schema)
    {
    }

    public function create(SessionRecord $session): SessionRecord
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return $session;
        }

        $row = [
            'user_id' => $session->userId,
            'token' => $session->token,
            'fingerprint_hash' => $session->fingerprintHash,
            'ip' => $session->ip,
            'user_agent' => $session->userAgent,
            'label' => $session->label,
            'created_at' => $session->createdAt,
            'last_seen_at' => $session->lastSeenAt,
            'revoked_at' => $session->revokedAt,
        ];

        $wpdb->insert($this->schema->tableName(), $row);

        $insertId = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;

        return $session->withId($insertId);
    }

    public function findByToken(string $token): ?SessionRecord
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return null;
        }

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . $this->schema->tableName() . ' WHERE token = %s LIMIT 1',
            $token
        );
        $row = $wpdb->get_row($sql, \constant('ARRAY_A'));

        return is_array($row) ? SessionRecord::fromRow($row) : null;
    }

    public function findById(int $id): ?SessionRecord
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return null;
        }

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . $this->schema->tableName() . ' WHERE id = %d LIMIT 1',
            $id
        );
        $row = $wpdb->get_row($sql, \constant('ARRAY_A'));

        return is_array($row) ? SessionRecord::fromRow($row) : null;
    }

    public function touch(int $id, int $lastSeenAt): void
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return;
        }
        $wpdb->update($this->schema->tableName(), ['last_seen_at' => $lastSeenAt], ['id' => $id], ['%d'], ['%d']);
    }

    public function revoke(int $id, int $revokedAt): void
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return;
        }
        $wpdb->update($this->schema->tableName(), ['revoked_at' => $revokedAt], ['id' => $id], ['%d'], ['%d']);
    }

    public function findActiveForUser(int $userId): array
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return [];
        }

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . $this->schema->tableName() . ' WHERE user_id = %d AND revoked_at IS NULL ORDER BY last_seen_at DESC',
            $userId
        );
        $rows = $wpdb->get_results($sql, \constant('ARRAY_A'));

        if (!is_array($rows)) {
            return [];
        }

        return array_values(array_map(static fn (array $row): SessionRecord => SessionRecord::fromRow($row), $rows));
    }

    public function deleteOlderThan(int $cutoffTimestamp): int
    {
        $wpdb = $this->wpdb();
        if ($wpdb === null) {
            return 0;
        }

        $sql = $wpdb->prepare(
            'DELETE FROM ' . $this->schema->tableName()
            . ' WHERE (revoked_at IS NOT NULL AND revoked_at < %d) OR last_seen_at < %d',
            $cutoffTimestamp,
            $cutoffTimestamp
        );
        $result = $wpdb->query($sql);

        return is_int($result) ? $result : 0;
    }

    private function wpdb(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return is_object($wpdb) ? $wpdb : null;
    }
}
