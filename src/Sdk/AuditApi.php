<?php

declare(strict_types=1);

namespace SecurePress\Sdk;

use SecurePress\Core\Audit\AuditEvent;
use SecurePress\Core\Audit\AuditEventBuilder;
use SecurePress\Core\Audit\AuditLoggerInterface;
use SecurePress\Core\Container;

/**
 * Instance-style entry point into the audit-log subsystem.
 *
 * The {@see \SecurePress\Facades\AuditLog} static facade is the original, Laravel-style
 * API. This wrapper exists so developers can route everything through a single
 * `Security::audit()` entry point and keep the SDK consistent:
 *
 * ```php
 * Security::audit()->info('cart.cleared', ['cart_id' => $id]);
 *
 * Security::audit()->for($user)
 *     ->category('woocommerce')
 *     ->action('refund.issued')
 *     ->record();
 * ```
 *
 * Internally it resolves the same {@see AuditLoggerInterface} as the static facade —
 * the two are interchangeable.
 */
final class AuditApi
{
    public function __construct(private readonly Container $container)
    {
    }

    public function record(AuditEvent $event): ?AuditEvent
    {
        return $this->logger()->record($event);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $action, array $context = []): ?AuditEvent
    {
        return $this->logger()->log($level, $action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $action, array $context = []): ?AuditEvent
    {
        return $this->logger()->info($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notice(string $action, array $context = []): ?AuditEvent
    {
        return $this->logger()->notice($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $action, array $context = []): ?AuditEvent
    {
        return $this->logger()->warning($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $action, array $context = []): ?AuditEvent
    {
        return $this->logger()->error($action, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function critical(string $action, array $context = []): ?AuditEvent
    {
        return $this->logger()->critical($action, $context);
    }

    public function for(mixed $actor): AuditEventBuilder
    {
        return AuditEventBuilder::create($this->logger())->for($actor);
    }

    public function action(string $action): AuditEventBuilder
    {
        return AuditEventBuilder::create($this->logger())->action($action);
    }

    public function category(string $category): AuditEventBuilder
    {
        return AuditEventBuilder::create($this->logger())->category($category);
    }

    private function logger(): AuditLoggerInterface
    {
        return $this->container->get(AuditLoggerInterface::class);
    }
}
