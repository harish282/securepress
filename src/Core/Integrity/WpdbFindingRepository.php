<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

use RuntimeException;

/**
 * Production finding repository backed by `$wpdb`.
 *
 * Reads always order by severity DESC (criticals first), then by created_at DESC. The
 * severity comparison uses the integer weights in {@see FindingSeverity::weight()},
 * encoded directly in SQL via a CASE expression to avoid having to maintain a parallel
 * lookup table. Tradeoff: adding a new severity level requires a code change here, but
 * keeps the schema dead simple (single VARCHAR column).
 */
final class WpdbFindingRepository implements FindingRepositoryInterface
{
    public function __construct(private readonly IntegritySchema $schema)
    {
    }

    public function record(Finding $finding): Finding
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            throw new RuntimeException('wpdb is not available for finding persistence.');
        }
        $inserted = $wpdb->insert(
            $this->schema->findingTable(),
            [
                'scope' => $finding->scope,
                'type' => $finding->type,
                'severity' => $finding->severity,
                'path' => $finding->path,
                'message' => $finding->message,
                'details' => json_encode($finding->details, JSON_UNESCAPED_SLASHES),
                'created_at' => $finding->createdAt,
                'reviewed_at' => $finding->reviewedAt,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d']
        );

        if ($inserted === false) {
            throw new RuntimeException('Failed to persist finding: ' . (string) ($wpdb->last_error ?? 'unknown wpdb error'));
        }

        $newId = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;

        return $newId > 0 ? $finding->withId($newId) : $finding;
    }

    public function markReviewed(int $id, ?int $now = null): bool
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null || $id <= 0) {
            return false;
        }
        $rows = $wpdb->update(
            $this->schema->findingTable(),
            ['reviewed_at' => $now ?? time()],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        return is_int($rows) && $rows > 0;
    }

    public function delete(int $id): bool
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null || $id <= 0) {
            return false;
        }
        $rows = $wpdb->delete($this->schema->findingTable(), ['id' => $id], ['%d']);

        return is_int($rows) && $rows > 0;
    }

    public function deleteAll(): int
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return 0;
        }
        $rows = $wpdb->query('DELETE FROM ' . $this->schema->findingTable());

        return is_int($rows) ? $rows : 0;
    }

    public function open(): array
    {
        return $this->fetch('WHERE reviewed_at IS NULL');
    }

    public function all(): array
    {
        return $this->fetch('');
    }

    public function countOpen(): int
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return 0;
        }
        $value = $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->schema->findingTable() . ' WHERE reviewed_at IS NULL');

        return is_numeric($value) ? (int) $value : 0;
    }

    private function fetch(string $whereClause): array
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return [];
        }
        $sql = 'SELECT * FROM ' . $this->schema->findingTable()
            . ' ' . $whereClause
            . ' ORDER BY '
            . "CASE severity"
            . " WHEN 'critical' THEN 4"
            . " WHEN 'high' THEN 3"
            . " WHEN 'medium' THEN 2"
            . " WHEN 'low' THEN 1"
            . ' ELSE 0 END DESC, created_at DESC';

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $details = json_decode((string) ($row['details'] ?? ''), true);
            $out[] = new Finding(
                id: isset($row['id']) ? (int) $row['id'] : null,
                scope: (string) ($row['scope'] ?? ''),
                type: (string) ($row['type'] ?? ''),
                severity: (string) ($row['severity'] ?? FindingSeverity::INFO),
                path: (string) ($row['path'] ?? ''),
                message: (string) ($row['message'] ?? ''),
                details: is_array($details) ? $details : [],
                createdAt: (int) ($row['created_at'] ?? 0),
                reviewedAt: isset($row['reviewed_at']) && $row['reviewed_at'] !== null
                    ? (int) $row['reviewed_at']
                    : null,
            );
        }

        return $out;
    }

    private function wpdbOrNull(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return is_object($wpdb) && method_exists($wpdb, 'prepare') ? $wpdb : null;
    }
}
