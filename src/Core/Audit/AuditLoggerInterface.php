<?php

declare(strict_types=1);

namespace PressSentinel\Core\Audit;

/**
 * Public contract for recording audit events.
 *
 * Designed to be PSR-3 friendly while still carrying audit-specific metadata. The two
 * cores are {@see record()} (full-fidelity, accepts a constructed {@see AuditEvent}) and
 * {@see log()} (PSR-3 style — level + action + context).
 *
 * Convenience helpers like {@see info()}, {@see warning()}, {@see critical()} are sugar over
 * `log()`. Listeners typically call `record()` directly so they can attach actor/target.
 */
interface AuditLoggerInterface
{
    public function record(AuditEvent $event): ?AuditEvent;

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $action, array $context = []): ?AuditEvent;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $action, array $context = []): ?AuditEvent;

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string $action, array $context = []): ?AuditEvent;

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $action, array $context = []): ?AuditEvent;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $action, array $context = []): ?AuditEvent;

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $action, array $context = []): ?AuditEvent;
}
