<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\TwoFactor;

/**
 * In-memory {@see ChallengeStoreInterface} for tests.
 *
 * Tests typically advance a synthetic clock via the {@see now()} closure to force
 * expiry without sleeping; production transients expire on wall-clock time.
 */
final class ArrayChallengeStore implements ChallengeStoreInterface
{
    /** @var array<string, PendingChallenge> */
    private array $entries = [];

    /** @var \Closure(): int */
    private \Closure $now;

    public function __construct(?\Closure $now = null)
    {
        $this->now = $now ?? static fn (): int => time();
    }

    public function put(PendingChallenge $challenge): void
    {
        $this->entries[$challenge->token] = $challenge;
    }

    public function find(string $token): ?PendingChallenge
    {
        $challenge = $this->entries[$token] ?? null;
        if ($challenge === null) {
            return null;
        }
        if ($challenge->isExpired(($this->now)())) {
            unset($this->entries[$token]);

            return null;
        }

        return $challenge;
    }

    public function forget(string $token): void
    {
        unset($this->entries[$token]);
    }

    public function size(): int
    {
        return count($this->entries);
    }
}
