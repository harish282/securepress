<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\Sessions;

use PressSentinel\Core\Logging\LoggerInterface;
use PressSentinel\Core\Support\WpHelper;

/**
 * Deletes stale rows from the PressSentinel sessions table on a daily cron tick.
 *
 * Mirrors {@see \PressSentinel\Core\Audit\AuditLogPruner}. Without pruning, revoked /
 * expired sessions accumulate indefinitely — rare but measurable on busy membership sites.
 */
final class SessionPruner
{
    public const CRON_HOOK = 'presssentinel_sessions_prune';

    public function __construct(
        private readonly SessionRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly int $retentionDays,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction(self::CRON_HOOK, [$this, 'run']);
        WpHelper::scheduleEvent(time() + 3600, 'daily', self::CRON_HOOK);
    }

    public function run(): void
    {
        $cutoff = time() - ($this->retentionDays * 86400);
        $deleted = $this->repository->deleteOlderThan($cutoff);
        if ($deleted > 0) {
            $this->logger->info(sprintf('SessionPruner: deleted %d stale session row(s).', $deleted));
        }
    }
}
