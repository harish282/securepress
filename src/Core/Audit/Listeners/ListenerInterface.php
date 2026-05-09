<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit\Listeners;

/**
 * Marker for self-registering audit listeners.
 *
 * The plugin instantiates each listener through the container and calls `register()` once
 * during boot — concrete classes wire their WordPress action / filter hooks there. Each
 * listener gets the {@see \SecurePress\Core\Audit\AuditLoggerInterface} via constructor.
 */
interface ListenerInterface
{
    public function register(): void;
}
