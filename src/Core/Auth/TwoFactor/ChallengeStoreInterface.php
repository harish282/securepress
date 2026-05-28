<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\TwoFactor;

/**
 * Persistence contract for the short-lived "halfway through login" record.
 *
 * The TTL on each entry is governed by the challenge's own `expires_at`; implementations
 * are expected to expire entries automatically (transients do this naturally; arrays
 * filter on read). Callers must not rely on `find()` returning an expired challenge.
 */
interface ChallengeStoreInterface
{
    public function put(PendingChallenge $challenge): void;

    public function find(string $token): ?PendingChallenge;

    public function forget(string $token): void;
}
