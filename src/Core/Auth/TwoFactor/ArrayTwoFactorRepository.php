<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\TwoFactor;

/**
 * In-memory {@see TwoFactorUserRepositoryInterface} for tests.
 *
 * Mirrors the user_meta semantics: missing user → disabled, save overwrites, delete is
 * idempotent. Used by every test that exercises {@see TwoFactorService} so production
 * code paths stay identical without touching WordPress.
 */
final class ArrayTwoFactorRepository implements TwoFactorUserRepositoryInterface
{
    /** @var array<int, TwoFactorState> */
    private array $entries = [];

    public function find(int $userId): TwoFactorState
    {
        return $this->entries[$userId] ?? TwoFactorState::disabled();
    }

    public function save(int $userId, TwoFactorState $state): void
    {
        $this->entries[$userId] = $state;
    }

    public function delete(int $userId): void
    {
        unset($this->entries[$userId]);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
