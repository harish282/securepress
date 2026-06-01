<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Lockout;

/**
 * Tunable thresholds for the lockout state machine.
 *
 * Defaults err on the lenient side so a typo doesn't lock out a legitimate user:
 *  - 5 failures within 15 minutes triggers a 15-minute lock.
 *  - The very first lock is the same length as subsequent ones (no escalating backoff
 *    in v1; we can layer that in later if abuse warrants it).
 */
final class LoginLockoutPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 5,
        public readonly int $windowSeconds = 900,
        public readonly int $lockSeconds = 900,
        public readonly bool $enabled = true,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            max(1, (int) ($config['max_attempts'] ?? 5)),
            max(60, (int) ($config['window_seconds'] ?? 900)),
            max(60, (int) ($config['lock_seconds'] ?? 900)),
            (bool) ($config['enabled'] ?? true),
        );
    }
}
