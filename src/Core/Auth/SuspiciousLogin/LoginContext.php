<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\SuspiciousLogin;

use PressSentinel\Core\Auth\Sessions\DeviceFingerprint;

/**
 * Snapshot of "who is trying to log in, from where" passed to the rule engine.
 *
 * Built once per login attempt (after the password has been verified, before the
 * session is established) so all rules see the same canonical view of the request.
 */
final class LoginContext
{
    public function __construct(
        public readonly int $userId,
        public readonly DeviceFingerprint $fingerprint,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly int $timestamp,
    ) {
    }
}
