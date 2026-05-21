<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Scanners;

use PressSentinel\Core\Integrity\Finding;
use PressSentinel\Core\Integrity\FindingSeverity;
use PressSentinel\Core\Integrity\FindingType;
use PressSentinel\Core\Integrity\Manifest;
use PressSentinel\Core\Integrity\ManifestBuilder;
use PressSentinel\Core\Integrity\ManifestDiff;
use PressSentinel\Core\Integrity\ManifestRepositoryInterface;

/**
 * Diffs the live filesystem against a stored baseline for one scope.
 *
 * Use the same scanner for **plugins**, **themes**, or any custom directory:
 *
 *     new ManifestDiffScanner('plugins', WP_PLUGIN_DIR, $builder, $repo);
 *
 * Behaviour:
 *  - On the **first run** (no baseline saved yet) the scanner captures a baseline and
 *    emits zero findings — it has nothing to compare against. The captured manifest is
 *    persisted so the second run produces a meaningful diff. This makes "install plugin
 *    and forget" deployment safe: nobody gets paged about thousands of "new file"
 *    findings the first time the cron runs.
 *  - On **subsequent runs**, the scanner rebuilds the live manifest and emits one
 *    finding per added / modified / deleted file, then *updates* the baseline to the
 *    new manifest. This means findings represent change-since-last-scan, not
 *    change-since-install. Operators who want install-time-baseline semantics should
 *    skip the baseline-update step (currently configurable via $persistOnDiff).
 *
 * The diff is hash-only (see {@see ManifestDiff}). Modified-file severity is `HIGH`
 * by default — a hash change in core/plugin files is one of the strongest signals of
 * a compromise.
 */
final class ManifestDiffScanner implements ScannerInterface
{
    public function __construct(
        private readonly string $scope,
        private readonly string $rootPath,
        private readonly ManifestBuilder $builder,
        private readonly ManifestRepositoryInterface $repository,
        private readonly string $addedSeverity = FindingSeverity::MEDIUM,
        private readonly string $modifiedSeverity = FindingSeverity::HIGH,
        private readonly string $deletedSeverity = FindingSeverity::HIGH,
        private readonly bool $persistOnDiff = true,
    ) {
    }

    public function name(): string
    {
        return 'manifest_diff:' . $this->scope;
    }

    public function scan(): array
    {
        $current = $this->builder->build($this->scope, $this->rootPath);
        $baseline = $this->repository->load($this->scope);

        if ($baseline === null) {
            $this->repository->save($current);
            return [];
        }

        $diff = ManifestDiff::between($baseline, $current);
        $findings = $this->diffToFindings($diff);

        if ($this->persistOnDiff && !$diff->isEmpty()) {
            $this->repository->save($current);
        }

        return $findings;
    }

    public function captureBaseline(): Manifest
    {
        $manifest = $this->builder->build($this->scope, $this->rootPath);
        $this->repository->save($manifest);

        return $manifest;
    }

    public function resetBaseline(): void
    {
        $this->repository->delete($this->scope);
    }

    /**
     * @return list<Finding>
     */
    private function diffToFindings(ManifestDiff $diff): array
    {
        $findings = [];

        foreach ($diff->added as $entry) {
            $findings[] = Finding::make(
                scope: $this->scope,
                type: FindingType::FILE_ADDED,
                severity: $this->addedSeverity,
                path: $entry->path,
                message: 'New file appeared since the previous baseline.',
                details: [
                    'hash' => $entry->hash,
                    'size' => $entry->size,
                    'mtime' => $entry->mtime,
                ],
            );
        }

        foreach ($diff->modified as $change) {
            $findings[] = Finding::make(
                scope: $this->scope,
                type: FindingType::FILE_MODIFIED,
                severity: $this->modifiedSeverity,
                path: $change['path'],
                message: 'File content changed since the previous baseline.',
                details: [
                    'old_hash' => $change['old']->hash,
                    'new_hash' => $change['new']->hash,
                    'old_size' => $change['old']->size,
                    'new_size' => $change['new']->size,
                    'mtime' => $change['new']->mtime,
                ],
            );
        }

        foreach ($diff->deleted as $entry) {
            $findings[] = Finding::make(
                scope: $this->scope,
                type: FindingType::FILE_DELETED,
                severity: $this->deletedSeverity,
                path: $entry->path,
                message: 'File present in the baseline is missing from the filesystem.',
                details: [
                    'previous_hash' => $entry->hash,
                    'previous_size' => $entry->size,
                ],
            );
        }

        return $findings;
    }
}
