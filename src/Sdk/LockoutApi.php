<?php

declare(strict_types=1);

namespace NiyiGuard\Sdk;

use NiyiGuard\Core\Auth\Lockout\LoginLockoutPolicy;
use NiyiGuard\Core\Auth\Lockout\LoginLockoutService;
use NiyiGuard\Core\Container;

/**
 * Developer surface for the login-lockout subsystem.
 *
 * Useful for plugins that want to participate in the same brute-force defence as
 * NiyiGuard's own login kernel. For example, a custom REST login endpoint can:
 *
 * ```php
 * $api = Security::lockout();
 * if ($api->isLocked($username, $ip)) {
 *     return new WP_Error('locked', 'Too many failed attempts.', ['status' => 423]);
 * }
 *
 * if (!password_verify($input, $hash)) {
 *     $api->registerFailure($username, $ip);
 *     return new WP_Error('invalid', 'Bad credentials.');
 * }
 *
 * $api->clear($username, $ip);
 * ```
 *
 * That keeps the counters shared with the core kernel: a REST-side bruteforce now
 * also locks the `wp-login.php` UI, and vice versa.
 */
final class LockoutApi
{
    public function __construct(private readonly Container $container)
    {
    }

    public function isLocked(string $username, ?string $ip): bool
    {
        return $this->service()->isLocked($username, $ip);
    }

    /**
     * Records a single failed attempt. Returns `true` if this failure tipped the user
     * or IP over the threshold (i.e., they're locked NOW).
     */
    public function registerFailure(string $username, ?string $ip): bool
    {
        return $this->service()->registerFailure($username, $ip);
    }

    public function clear(string $username, ?string $ip): void
    {
        $this->service()->clear($username, $ip);
    }

    public function lockExpiresAt(string $username, ?string $ip): ?int
    {
        return $this->service()->lockExpiresAt($username, $ip);
    }

    public function policy(): LoginLockoutPolicy
    {
        return $this->service()->policy();
    }

    private function service(): LoginLockoutService
    {
        return $this->container->get(LoginLockoutService::class);
    }
}
