<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\TwoFactor;

/**
 * Persistence contract for per-user 2FA state.
 *
 * The default implementation stores everything in user_meta as a single JSON-encoded
 * blob — atomic per-user updates, scoped to the user, and inherited by site cleanup
 * when a user is deleted. The `find()` contract guarantees a non-null state even when
 * nothing is persisted, returning {@see TwoFactorState::disabled()} so callers can
 * skip null-checks.
 */
interface TwoFactorUserRepositoryInterface
{
    public function find(int $userId): TwoFactorState;

    public function save(int $userId, TwoFactorState $state): void;

    public function delete(int $userId): void;
}
