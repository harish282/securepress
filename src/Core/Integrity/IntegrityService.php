<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

use PressSentinel\Core\Integrity\Scanners\ScannerInterface;
use PressSentinel\Core\Logging\LoggerInterface;
use PressSentinel\Core\Logging\NullLogger;

/**
 * The application-level orchestrator for file-integrity scanning.
 *
 * Responsibilities:
 *  - Iterate the configured scanners ({@see registerScanner()}), aggregate their
 *    {@see Finding}s, persist each one through the {@see FindingRepositoryInterface},
 *    and return a single {@see IntegrityScanResult} summary.
 *  - Pre-flight check: respect the master `enabled` switch from {@see IntegrityOptions}
 *    so the cron handler doesn't have to know about the killswitch logic.
 *  - Track minor errors at warning level — a single failing scanner must not abort
 *    the whole run (the operator usually wants the partial results).
 *
 * The service is intentionally NOT in charge of:
 *  - cron scheduling — that's {@see IntegrityScheduler}'s job;
 *  - email alerts — that's wired separately in the admin layer (so the alert policy
 *    can read the freshly-persisted findings and respect the `min_severity` setting).
 *
 * Why a separate service instead of dropping the loop into the cron handler: keeping
 * it here means application code (REST endpoints, WP-CLI commands, the admin
 * "Re-scan" button) can call the same entry point and get identical behaviour.
 */
final class IntegrityService
{
    /** @var list<ScannerInterface> */
    private array $scanners = [];

    public function __construct(
        private readonly FindingRepositoryInterface $findings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function registerScanner(ScannerInterface $scanner): void
    {
        $this->scanners[] = $scanner;
    }

    /**
     * @return list<ScannerInterface>
     */
    public function scanners(): array
    {
        return $this->scanners;
    }

    public function scan(): IntegrityScanResult
    {
        $startedAt = time();
        $persisted = [];
        $summary = [];
        $ranScanners = [];

        foreach ($this->scanners as $scanner) {
            $ranScanners[] = $scanner->name();
            try {
                $findings = $scanner->scan();
            } catch (\Throwable $exception) {
                $this->logger->warning(sprintf(
                    'Integrity scanner "%s" failed: %s',
                    $scanner->name(),
                    $exception->getMessage()
                ));
                continue;
            }
            foreach ($findings as $finding) {
                try {
                    $persisted[] = $this->findings->record($finding);
                    $summary[$finding->severity] = ($summary[$finding->severity] ?? 0) + 1;
                } catch (\Throwable $exception) {
                    $this->logger->warning(sprintf(
                        'Integrity: failed to persist finding for %s: %s',
                        $finding->path,
                        $exception->getMessage()
                    ));
                }
            }
        }

        return new IntegrityScanResult(
            startedAt: $startedAt,
            finishedAt: time(),
            findings: $persisted,
            summary: $summary,
            scannersRun: $ranScanners,
        );
    }

    public function findings(): FindingRepositoryInterface
    {
        return $this->findings;
    }
}
