<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit;

use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Support\WpHelper;
use Throwable;

/**
 * Default {@see AuditLoggerInterface} implementation.
 *
 * Responsibilities:
 *  - Auto-fill request metadata (actor id/name, IP, user agent, request URI) for events
 *    that don't carry them already — so listeners can stay terse.
 *  - Pass enriched events to the {@see AuditLogRepositoryInterface} for persistence.
 *  - Mirror to the file logger when configured (for Laravel-style multi-channel logging
 *    and SIEM export pipelines).
 *  - Be killswitch-aware: when `$enabled` is `false` (config flag), every method becomes
 *    a no-op so the audit trail can be disabled in low-trust environments without ripping
 *    out the listener wiring.
 *  - Be exception-tolerant: a failing repository must not break the underlying WordPress
 *    action that triggered the event. We swallow throwables and re-emit them through the
 *    file logger as warnings so operators can still see them.
 */
final class AuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
        private readonly bool $mirrorToFileLogger = false,
        private readonly string $minStorageLevel = AuditEventLevel::INFO,
    ) {
    }

    public function record(AuditEvent $event): ?AuditEvent
    {
        if (!$this->enabled) {
            return null;
        }

        $enriched = $this->enrich($event);

        if (!AuditEventLevel::isAtLeast($enriched->level, $this->minStorageLevel)) {
            if ($this->mirrorToFileLogger) {
                $this->mirror($enriched);
            }

            return null;
        }

        try {
            $stored = $this->repository->record($enriched);
        } catch (Throwable $e) {
            $this->logger->warning('Audit log persistence failed: ' . $e->getMessage(), [
                'action' => $enriched->action,
                'category' => $enriched->category,
            ]);

            return null;
        }

        if ($this->mirrorToFileLogger) {
            $this->mirror($stored);
        }

        return $stored;
    }

    public function log(string $level, string $action, array $context = []): ?AuditEvent
    {
        $event = AuditEvent::make($action, AuditEventCategory::OTHER, $level);
        if ($context !== []) {
            $event = $event->withContext($context);
        }

        return $this->record($event);
    }

    public function info(string $action, array $context = []): ?AuditEvent
    {
        return $this->log(AuditEventLevel::INFO, $action, $context);
    }

    public function notice(string $action, array $context = []): ?AuditEvent
    {
        return $this->log(AuditEventLevel::NOTICE, $action, $context);
    }

    public function warning(string $action, array $context = []): ?AuditEvent
    {
        return $this->log(AuditEventLevel::WARNING, $action, $context);
    }

    public function error(string $action, array $context = []): ?AuditEvent
    {
        return $this->log(AuditEventLevel::ERROR, $action, $context);
    }

    public function critical(string $action, array $context = []): ?AuditEvent
    {
        return $this->log(AuditEventLevel::CRITICAL, $action, $context);
    }

    private function enrich(AuditEvent $event): AuditEvent
    {
        $needsActor = $event->actorId === null && $event->actorName === null;
        $needsRequest = $event->ip === null && $event->userAgent === null && $event->requestUri === null;

        if ($needsActor) {
            $userId = WpHelper::currentUserId();
            $name = WpHelper::currentUserDisplayName();
            if ($userId > 0 || $name !== null) {
                $event = $event->withActor($userId > 0 ? $userId : null, $name);
            }
        }

        if ($needsRequest) {
            $event = $event->withRequest(
                WpHelper::getClientIp(),
                WpHelper::userAgent(),
                WpHelper::requestUri(),
            );
        }

        return $event;
    }

    private function mirror(AuditEvent $event): void
    {
        $message = sprintf(
            '[audit] %s %s',
            $event->category,
            $event->action,
        );

        $context = [
            'occurred_at' => gmdate('c', $event->occurredAt),
            'actor_id' => $event->actorId,
            'actor' => $event->actorName,
            'target' => $event->targetType !== null ? $event->targetType . ':' . $event->targetId : null,
            'ip' => $event->ip,
            'context' => $event->context,
        ];

        match ($event->level) {
            AuditEventLevel::EMERGENCY,
            AuditEventLevel::ALERT,
            AuditEventLevel::CRITICAL,
            AuditEventLevel::ERROR => $this->logger->error($message, $context),
            AuditEventLevel::WARNING => $this->logger->warning($message, $context),
            default => $this->logger->info($message, $context),
        };
    }
}
