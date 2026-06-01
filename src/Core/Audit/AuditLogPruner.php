<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit;

use NiyiGuard\Core\Logging\LoggerInterface;
use NiyiGuard\Core\Support\WpHelper;
use Throwable;

/**
 * Cron-driven retention pruner for the audit log.
 *
 * When {@see AuditLogOptions::isAutoPruneEnabled()} is true and
 * {@see AuditLogOptions::retentionDays()} is greater than zero, schedules a daily
 * WP cron event that deletes entries older than the retention window.
 *
 * Manual invocation via {@see prune(manual: true)} (admin "Run prune now") ignores
 * the auto-prune toggle but still requires a positive retention window.
 *
 * Set retention to `0` to disable pruning entirely — useful for compliance regimes
 * where logs must be retained forever and external archival is the lifecycle owner.
 */
final class AuditLogPruner
{
    public const HOOK = 'niyiguard_audit_log_prune';

    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly AuditLogOptions $options,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction(self::HOOK, [$this, 'prune']);
        $this->syncSchedule();
    }

    public function syncSchedule(): void
    {
        if ($this->shouldRunScheduledPrune()) {
            $this->scheduleIfMissing();

            return;
        }

        $this->unschedule();
    }

    public function scheduleIfMissing(): void
    {
        WpHelper::scheduleEvent(time() + 60, 'daily', self::HOOK);
    }

    public function unschedule(): void
    {
        WpHelper::clearScheduledHook(self::HOOK);
    }

    /**
     * @param bool $manual When true (admin "Run prune now"), runs even if automatic pruning is off.
     * @return int Rows removed.
     */
    public function prune(bool $manual = false): int
    {
        $retentionDays = $this->options->retentionDays();
        if ($retentionDays <= 0) {
            return 0;
        }
        if (!$manual && !$this->shouldRunScheduledPrune()) {
            return 0;
        }

        $cutoff = time() - ($retentionDays * 86400);

        try {
            $deleted = $this->repository->deleteOlderThan($cutoff);
        } catch (Throwable $e) {
            $this->logger->warning('Audit log pruning failed: ' . $e->getMessage(), [
                'cutoff' => gmdate('c', $cutoff),
            ]);

            return 0;
        }

        if ($deleted > 0) {
            $this->logger->info(sprintf(
                'Pruned %d audit log entries older than %d days.',
                $deleted,
                $retentionDays
            ));
        }

        return $deleted;
    }

    private function shouldRunScheduledPrune(): bool
    {
        return $this->options->isAutoPruneEnabled() && $this->options->retentionDays() > 0;
    }
}
