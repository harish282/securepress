<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit;

use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Support\WpHelper;
use Throwable;

/**
 * Cron-driven retention pruner for the audit log.
 *
 * Schedules a daily WP cron event that deletes entries older than the configured retention
 * window. The pruner is also exposed for manual invocation (e.g. from a WP-CLI script or
 * the admin UI's "Run prune now" button) via {@see prune()}.
 *
 * Set retention to `0` (or negative) to disable pruning entirely — useful for compliance
 * regimes where logs must be retained forever and external archival is the lifecycle owner.
 */
final class AuditLogPruner
{
    public const HOOK = 'securepress_audit_log_prune';

    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly int $retentionDays,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction(self::HOOK, [$this, 'prune']);
        $this->scheduleIfMissing();
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
     * Returns the number of rows removed.
     */
    public function prune(): int
    {
        if ($this->retentionDays <= 0) {
            return 0;
        }

        $cutoff = time() - ($this->retentionDays * 86400);

        try {
            $deleted = $this->repository->deleteOlderThan($cutoff);
        } catch (Throwable $e) {
            $this->logger->warning('Audit log pruning failed: ' . $e->getMessage(), [
                'cutoff' => gmdate('c', $cutoff),
            ]);

            return 0;
        }

        if ($deleted > 0) {
            $this->logger->info(sprintf('Pruned %d audit log entries older than %d days.', $deleted, $this->retentionDays));
        }

        return $deleted;
    }
}
