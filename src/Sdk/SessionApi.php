<?php

declare(strict_types=1);

namespace NiyiGuard\Sdk;

use NiyiGuard\Core\Auth\Sessions\SessionRecord;
use NiyiGuard\Core\Auth\Sessions\SessionService;
use NiyiGuard\Core\Container;

/**
 * Public surface for session tracking & revocation.
 *
 * Mirrors {@see SessionService} but with names tuned for application developers (e.g.,
 * `activeFor()` reads more naturally than `listActive()`). Keeping the SDK names and the
 * core service names slightly different is intentional: the SDK is the *stable* contract,
 * the core service is free to evolve.
 *
 * Examples:
 *
 * ```php
 * foreach (Security::sessions()->activeFor($user->ID) as $session) {
 *     // build a "where I'm logged in" list
 * }
 *
 * // Force-logout everyone except the current admin session:
 * Security::sessions()->revokeAllExceptCurrent($user->ID, $currentSessionId);
 * ```
 */
final class SessionApi
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @return list<SessionRecord>
     */
    public function activeFor(int $userId): array
    {
        return $this->service()->listActive($userId);
    }

    public function track(int $userId, ?string $ip, ?string $userAgent, ?string $label = null): SessionRecord
    {
        return $this->service()->track($userId, $ip, $userAgent, $label);
    }

    public function revoke(int $userId, int $sessionId): bool
    {
        return $this->service()->revoke($userId, $sessionId);
    }

    /**
     * @return int Number of sessions revoked.
     */
    public function revokeAllExceptCurrent(int $userId, ?int $currentSessionId = null): int
    {
        return $this->service()->revokeAllExceptCurrent($userId, $currentSessionId);
    }

    private function service(): SessionService
    {
        return $this->container->get(SessionService::class);
    }
}
