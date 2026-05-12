<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity;

/**
 * Aggregated outcome of one {@see IntegrityService::scan()} invocation.
 *
 * Returned from the service so callers (admin UI, cron handler, audit logger) can
 * answer "what happened in this run?" without re-querying the repository. The list of
 * persisted findings is included so the admin can show a summary banner ("4 new
 * critical findings") right after the user clicks Re-scan.
 *
 * `summary` is a `count-by-severity` map for the in-this-run findings — handy for
 * email-alert thresholds (e.g., "only notify if there's at least one `critical`").
 */
final class IntegrityScanResult
{
    public function __construct(
        public readonly int $startedAt,
        public readonly int $finishedAt,
        /** @var list<Finding> */
        public readonly array $findings,
        /** @var array<string, int> */
        public readonly array $summary,
        /** @var list<string> */
        public readonly array $scannersRun,
    ) {
    }

    public function durationSeconds(): int
    {
        return max(0, $this->finishedAt - $this->startedAt);
    }

    public function hasCritical(): bool
    {
        return ($this->summary[FindingSeverity::CRITICAL] ?? 0) > 0;
    }

    public function totalFindings(): int
    {
        return count($this->findings);
    }
}
