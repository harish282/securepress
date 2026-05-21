<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\TwoFactor;

use PressSentinel\Core\Support\WpHelper;

/**
 * Production challenge store backed by WordPress transients.
 *
 * Why transients?
 *  - They expire automatically (no need for a cron sweep), and on object-cache enabled
 *    sites they don't even hit the database.
 *  - The TTL of the transient is derived from the challenge's own `expires_at` so
 *    leftover state evaporates exactly when the challenge becomes invalid.
 *  - Token lookups are O(1) and the keyspace (`presssentinel_2fa_*`) is namespaced enough
 *    to avoid collisions with audit-pruner and rate-limiter buckets.
 */
final class TransientChallengeStore implements ChallengeStoreInterface
{
    public const KEY_PREFIX = 'presssentinel_2fa_';

    public function put(PendingChallenge $challenge): void
    {
        $ttl = max(60, $challenge->expiresAt - time());
        WpHelper::setTransient(self::KEY_PREFIX . $challenge->token, $challenge->toArray(), $ttl);
    }

    public function find(string $token): ?PendingChallenge
    {
        if ($token === '') {
            return null;
        }

        $row = WpHelper::getTransient(self::KEY_PREFIX . $token);
        if (!is_array($row)) {
            return null;
        }

        $challenge = PendingChallenge::fromArray($row);
        if ($challenge->isExpired()) {
            WpHelper::deleteTransient(self::KEY_PREFIX . $token);

            return null;
        }

        return $challenge;
    }

    public function forget(string $token): void
    {
        if ($token === '') {
            return;
        }
        WpHelper::deleteTransient(self::KEY_PREFIX . $token);
    }
}
