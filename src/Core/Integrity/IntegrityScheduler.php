<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity;

use NiyiGuard\Core\Logging\LoggerInterface;
use NiyiGuard\Core\Logging\NullLogger;
use NiyiGuard\Core\Support\WpHelper;
use Throwable;

/**
 * Schedules and dispatches the recurring integrity scan via WP-Cron.
 *
 * Mirrors the {@see \NiyiGuard\Core\Audit\AuditLogPruner} pattern (cron hook +
 * `scheduleIfMissing`). On every tick we delegate to {@see IntegrityService::scan()},
 * then dispatch the result to the optional notifier so operators can be e-mailed when
 * critical findings appear.
 *
 * The recurrence comes from {@see IntegrityOptions::all()}; default `daily`. We honour
 * the master `enabled` flag and `cron.enabled` flag so the option screen toggles
 * actually do something — the scheduler skips both registering AND firing when those
 * are off.
 */
final class IntegrityScheduler
{
    public const HOOK = 'niyiguard_integrity_scan';

    public function __construct(
        private readonly IntegrityService $service,
        private readonly IntegrityOptions $options,
        private readonly LoggerInterface $logger = new NullLogger(),
        /** @var callable(IntegrityScanResult): void|null */
        private $notifier = null,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction(self::HOOK, [$this, 'run']);
        $this->scheduleIfMissing();
    }

    public function scheduleIfMissing(): void
    {
        $config = $this->options->all();
        $cron = is_array($config['cron'] ?? null) ? $config['cron'] : [];

        if (!($config['enabled'] ?? true) || !($cron['enabled'] ?? true)) {
            $this->unschedule();

            return;
        }

        $recurrence = is_string($cron['recurrence'] ?? null) ? (string) $cron['recurrence'] : 'daily';
        WpHelper::scheduleEvent(time() + 60, $recurrence, self::HOOK);
    }

    public function unschedule(): void
    {
        WpHelper::clearScheduledHook(self::HOOK);
    }

    /**
     * Cron entry point — kept public for the WP-Cron callback. Returns the scan result
     * so the same method is reusable from the admin UI's "Re-scan now" button.
     */
    public function run(): ?IntegrityScanResult
    {
        if (!$this->options->isEnabled()) {
            return null;
        }

        try {
            $result = $this->service->scan();
        } catch (Throwable $exception) {
            $this->logger->warning('Integrity scan failed: ' . $exception->getMessage());

            return null;
        }

        if ($this->notifier !== null && $result->totalFindings() > 0) {
            try {
                ($this->notifier)($result);
            } catch (Throwable $exception) {
                $this->logger->warning('Integrity notifier failed: ' . $exception->getMessage());
            }
        }

        return $result;
    }
}
