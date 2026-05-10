<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\SuspiciousLogin\Rules;

use SecurePress\Core\Auth\Sessions\SessionRepositoryInterface;
use SecurePress\Core\Auth\SuspiciousLogin\LoginContext;

/**
 * Fires when no past session for this user matches the current device fingerprint.
 *
 * "First-ever login" is *also* flagged — the very first session always looks new because
 * the user has no recorded sessions yet. Suppressing that case requires a separate
 * "first login" signal in user_meta; for now we err on the side of alerting (and that
 * email is genuinely informative, telling the user where their account is being signed
 * into for the first time).
 */
final class NewDeviceRule implements RuleInterface
{
    public function __construct(
        private readonly SessionRepositoryInterface $sessions,
        private readonly int $score = 60,
    ) {
    }

    public function name(): string
    {
        return 'new_device';
    }

    public function evaluate(LoginContext $context): int
    {
        foreach ($this->sessions->findActiveForUser($context->userId) as $session) {
            if (hash_equals($session->fingerprintHash, $context->fingerprint->hash)) {
                return 0;
            }
        }

        return $this->score;
    }
}
