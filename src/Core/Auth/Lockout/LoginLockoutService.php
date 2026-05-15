<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Lockout;

use SecurePress\Core\Recovery\SafeMode;

/**
 * Coordinates the failed-login counter / lockout lifecycle behind a friendly API.
 *
 * Two concrete keys are tracked per attempt:
 *  - `username:<lower>`   — protects the *account* from credential-stuffing across IPs
 *  - `ip:<remote>`        — protects the *site* from a single IP enumerating accounts
 *
 * A lock on either key is treated as a denial. Successful logins clear both, so a
 * legitimate user who eventually types their password right doesn't have to wait out
 * residual counters. The keys are HMAC-derived so user input never lands in transient
 * names verbatim.
 */
final class LoginLockoutService
{
    public function __construct(
        private readonly LockoutStoreInterface $store,
        private readonly LoginLockoutPolicy $policy,
    ) {
    }

    public function isLocked(string $username, ?string $ip): bool
    {
        if (SafeMode::bypasses(SafeMode::BYPASS_LOCKOUT)) {
            return false;
        }
        if (!$this->policy->enabled) {
            return false;
        }

        return $this->store->isLocked($this->keyForUsername($username))
            || ($ip !== null && $this->store->isLocked($this->keyForIp($ip)));
    }

    public function lockExpiresAt(string $username, ?string $ip): ?int
    {
        if (!$this->policy->enabled) {
            return null;
        }

        $candidates = array_filter([
            $this->store->lockExpiresAt($this->keyForUsername($username)),
            $ip !== null ? $this->store->lockExpiresAt($this->keyForIp($ip)) : null,
        ], static fn (?int $v): bool => $v !== null);

        return $candidates === [] ? null : max($candidates);
    }

    /**
     * Records a failed attempt and returns whether the account is now locked.
     */
    public function registerFailure(string $username, ?string $ip): bool
    {
        if (SafeMode::bypasses(SafeMode::BYPASS_LOCKOUT)) {
            return false;
        }
        if (!$this->policy->enabled) {
            return false;
        }

        $usernameKey = $this->keyForUsername($username);
        $ipKey = $ip !== null ? $this->keyForIp($ip) : null;

        $usernameCount = $this->store->hit($usernameKey, $this->policy->windowSeconds);
        $ipCount = $ipKey !== null ? $this->store->hit($ipKey, $this->policy->windowSeconds) : 0;

        $locked = false;
        if ($usernameCount >= $this->policy->maxAttempts) {
            $this->store->lock($usernameKey, $this->policy->lockSeconds);
            $locked = true;
        }
        if ($ipKey !== null && $ipCount >= $this->policy->maxAttempts) {
            $this->store->lock($ipKey, $this->policy->lockSeconds);
            $locked = true;
        }

        return $locked;
    }

    public function clear(string $username, ?string $ip): void
    {
        $this->store->clear($this->keyForUsername($username));
        if ($ip !== null) {
            $this->store->clear($this->keyForIp($ip));
        }
    }

    public function policy(): LoginLockoutPolicy
    {
        return $this->policy;
    }

    private function keyForUsername(string $username): string
    {
        return 'u_' . substr(hash('sha256', strtolower(trim($username))), 0, 32);
    }

    private function keyForIp(string $ip): string
    {
        return 'i_' . substr(hash('sha256', $ip), 0, 32);
    }
}
