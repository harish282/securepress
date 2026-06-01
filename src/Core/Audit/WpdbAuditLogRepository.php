<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit;

use RuntimeException;

/**
 * Production audit log repository backed by `$wpdb`.
 *
 * All write paths go through `wpdb::insert()` / `wpdb::query()` with `wpdb::prepare()` for
 * dynamic values — never string-concat user input into SQL. Reads use `wpdb::prepare()` for
 * the dynamic WHERE fragments and bind both the LIMIT/OFFSET so paging is also injection-safe.
 *
 * The repository is tolerant of partial WPDB failures: if `$wpdb` is unavailable (e.g. tests
 * accidentally instantiated this instead of the array repo), reads return empty results and
 * writes throw — failing loud on writes is intentional, silent failure here would mean
 * losing audit entries, which is exactly the problem this feature exists to solve.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom tables; names from schema helpers; values use $wpdb->prepare().
final class WpdbAuditLogRepository implements AuditLogRepositoryInterface
{
    public function __construct(private readonly AuditLogSchema $schema)
    {
    }

    public function record(AuditEvent $event): AuditEvent
    {
        $wpdb = $this->wpdb();
        $row = $event->toRow();
        unset($row['id']);

        $inserted = $wpdb->insert(
            $this->schema->tableName(),
            $row,
            $this->insertFormats()
        );

        if ($inserted === false || $inserted === 0) {
            $wpdbError = is_object($wpdb) && isset($wpdb->last_error)
                ? (string) $wpdb->last_error
                : 'unknown wpdb error';
            throw new RuntimeException(
                sprintf(
                    'Failed to persist audit event "%s": %s',
                    esc_html($event->action),
                    esc_html($wpdbError)
                )
            );
        }

        $newId = isset($wpdb->insert_id) ? (int) $wpdb->insert_id : 0;

        return $newId > 0 ? $event->withId($newId) : $event;
    }

    public function findById(int $id): ?AuditEvent
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null || $id <= 0) {
            return null;
        }

        $sql = $wpdb->prepare(
            'SELECT * FROM ' . $this->schema->tableName() . ' WHERE id = %d LIMIT 1',
            $id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        return AuditEvent::fromRow($row);
    }

    public function paginate(AuditLogQuery $query): AuditLogPage
    {
        $wpdb = $this->wpdbOrNull();
        $query = $query->withDefaults();

        if ($wpdb === null) {
            return new AuditLogPage([], 0, $query->page, $query->perPage);
        }

        [$where, $params] = $this->buildWhere($query);
        $table = $this->schema->tableName();

        $countSql = "SELECT COUNT(*) FROM {$table}";
        if ($where !== '') {
            $countSql .= ' WHERE ' . $where;
        }
        $countSql = $params === [] ? $countSql : $wpdb->prepare($countSql, ...$params);
        $total = (int) $wpdb->get_var($countSql);

        $direction = $query->direction === 'asc' ? 'ASC' : 'DESC';
        $listSql = "SELECT * FROM {$table}";
        if ($where !== '') {
            $listSql .= ' WHERE ' . $where;
        }
        $listSql .= " ORDER BY occurred_at {$direction}, id {$direction} LIMIT %d OFFSET %d";

        $listParams = array_merge($params, [$query->perPage, $query->offset()]);
        $listSql = $wpdb->prepare($listSql, ...$listParams);
        $rows = $wpdb->get_results($listSql, ARRAY_A);

        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $items[] = AuditEvent::fromRow($row);
                }
            }
        }

        return new AuditLogPage($items, $total, $query->page, $query->perPage);
    }

    public function count(): int
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return 0;
        }

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->schema->tableName());
    }

    public function deleteAll(): int
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return 0;
        }

        $result = $wpdb->query('DELETE FROM ' . $this->schema->tableName());

        return (int) $result;
    }

    public function deleteOlderThan(int $olderThan): int
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null || $olderThan <= 0) {
            return 0;
        }

        $sql = $wpdb->prepare(
            'DELETE FROM ' . $this->schema->tableName() . ' WHERE occurred_at < %s',
            gmdate('Y-m-d H:i:s', $olderThan)
        );

        return (int) $wpdb->query($sql);
    }

    /**
     * @return array{0: string, 1: array<int, scalar>}
     */
    private function buildWhere(AuditLogQuery $query): array
    {
        $clauses = [];
        $params = [];

        if ($query->category !== null && $query->category !== '') {
            $clauses[] = 'category = %s';
            $params[] = $query->category;
        }
        if ($query->level !== null && $query->level !== '') {
            $clauses[] = 'level = %s';
            $params[] = $query->level;
        }
        if ($query->actorId !== null) {
            $clauses[] = 'actor_id = %d';
            $params[] = $query->actorId;
        }
        if ($query->dateFrom !== null) {
            $clauses[] = 'occurred_at >= %s';
            $params[] = gmdate('Y-m-d H:i:s', $query->dateFrom);
        }
        if ($query->dateTo !== null) {
            $clauses[] = 'occurred_at <= %s';
            $params[] = gmdate('Y-m-d H:i:s', $query->dateTo);
        }
        if ($query->search !== null && $query->search !== '') {
            $clauses[] = '(action LIKE %s OR message LIKE %s OR actor_name LIKE %s OR target_id LIKE %s)';
            $like = '%' . $this->likeEscape($query->search) . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function likeEscape(string $value): string
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb !== null && method_exists($wpdb, 'esc_like')) {
            return (string) $wpdb->esc_like($value);
        }

        return addcslashes($value, '_%\\');
    }

    /**
     * @return list<string>
     */
    private function insertFormats(): array
    {
        return [
            '%s', // occurred_at
            '%s', // level
            '%s', // category
            '%s', // action
            '%d', // actor_id
            '%s', // actor_name
            '%s', // target_type
            '%s', // target_id
            '%s', // ip
            '%s', // user_agent
            '%s', // request_uri
            '%s', // message
            '%s', // context
        ];
    }

    private function wpdb(): object
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            throw new RuntimeException('WPDB is not available; cannot persist audit log entry.');
        }

        return $wpdb;
    }

    private function wpdbOrNull(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return is_object($wpdb) ? $wpdb : null;
    }
}
