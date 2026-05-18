<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

/**
 * Production manifest repository backed by `$wpdb`.
 *
 * Storage strategy: one row per (scope, path). Saving a manifest is a delete-all-for-scope
 * followed by a chunked INSERT — simpler than computing per-row diffs and bounded in cost
 * to the size of the scope, which is small enough to rewrite each scan (~10–20k rows for a
 * typical core baseline).
 *
 * Writes happen inside a transactional pair when supported by the underlying engine. We do
 * NOT explicitly issue `START TRANSACTION` because:
 *  - InnoDB autocommits each insert anyway;
 *  - the operation is idempotent — a half-written manifest just produces a noisy diff on
 *    the next scan, which is recoverable, while explicit transactions interact badly with
 *    wpdb's connection lifecycle.
 */
final class WpdbManifestRepository implements ManifestRepositoryInterface
{
    public function __construct(private readonly IntegritySchema $schema)
    {
    }

    public function save(Manifest $manifest): void
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return;
        }
        $table = $this->schema->baselineTable();

        $wpdb->query($wpdb->prepare('DELETE FROM ' . $table . ' WHERE scope = %s', $manifest->scope));

        foreach (array_chunk($manifest->entries(), 100, true) as $chunk) {
            foreach ($chunk as $entry) {
                $wpdb->insert(
                    $table,
                    [
                        'scope' => $manifest->scope,
                        'path' => $entry->path,
                        'hash' => $entry->hash,
                        'size' => $entry->size,
                        'mtime' => $entry->mtime,
                        'captured_at' => $manifest->capturedAt,
                    ],
                    ['%s', '%s', '%s', '%d', '%d', '%d']
                );
            }
        }
    }

    public function load(string $scope): ?Manifest
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return null;
        }
        $table = $this->schema->baselineTable();
        $sql = $wpdb->prepare('SELECT * FROM ' . $table . ' WHERE scope = %s', $scope);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $manifest = Manifest::empty($scope, '');
        $capturedAt = 0;
        foreach ($rows as $row) {
            $manifest = $manifest->withEntry(new ManifestEntry(
                path: (string) $row['path'],
                hash: (string) $row['hash'],
                size: (int) $row['size'],
                mtime: (int) $row['mtime'],
            ));
            $capturedAt = max($capturedAt, (int) $row['captured_at']);
        }

        return new Manifest($scope, '', $manifest->entries(), $capturedAt);
    }

    public function delete(string $scope): void
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return;
        }
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $this->schema->baselineTable() . ' WHERE scope = %s', $scope));
    }

    public function scopes(): array
    {
        $wpdb = $this->wpdbOrNull();
        if ($wpdb === null) {
            return [];
        }
        $rows = $wpdb->get_col('SELECT DISTINCT scope FROM ' . $this->schema->baselineTable());

        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    private function wpdbOrNull(): ?object
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        return is_object($wpdb) && method_exists($wpdb, 'prepare') ? $wpdb : null;
    }
}
