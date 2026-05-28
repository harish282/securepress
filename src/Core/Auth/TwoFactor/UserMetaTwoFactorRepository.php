<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\TwoFactor;

/**
 * Default repository — persists 2FA state in WordPress user_meta.
 *
 * Storage format: a single user_meta entry under {@see self::META_KEY} containing the
 * JSON-encoded {@see TwoFactorState::toArray()}. One row per user simplifies cleanup
 * (`delete_user_meta($id, ...)` removes everything in one call) and avoids the racey
 * read-modify-write hazard of separate keys for `enabled`, `secret`, `recovery_hashes`.
 *
 * The TOTP `secret` is stored in plaintext intentionally: `wp_options` and `wp_usermeta`
 * are not designed as encrypted vaults, so adding sodium-based encryption here would
 * largely move the problem (we'd still need to store a key reachable from the same DB).
 * Operators who need encryption-at-rest should solve it at the database tier (e.g.,
 * MySQL transparent data encryption) and harden user_meta access through capabilities.
 */
final class UserMetaTwoFactorRepository implements TwoFactorUserRepositoryInterface
{
    public const META_KEY = '_niyiguard_2fa_state';

    public function find(int $userId): TwoFactorState
    {
        if ($userId <= 0 || !\function_exists('get_user_meta')) {
            return TwoFactorState::disabled();
        }

        $raw = \call_user_func('get_user_meta', $userId, self::META_KEY, true);
        if (!is_string($raw) || $raw === '') {
            return TwoFactorState::disabled();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return TwoFactorState::disabled();
        }

        return TwoFactorState::fromArray($decoded);
    }

    public function save(int $userId, TwoFactorState $state): void
    {
        if ($userId <= 0 || !\function_exists('update_user_meta')) {
            return;
        }

        $payload = json_encode($state->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return;
        }

        // WP will wp_slash() the payload internally on writes and unslash on reads —
        // we just hand it the raw JSON string and the round-trip is transparent.
        \call_user_func('update_user_meta', $userId, self::META_KEY, $payload);
    }

    public function delete(int $userId): void
    {
        if ($userId <= 0 || !\function_exists('delete_user_meta')) {
            return;
        }

        \call_user_func('delete_user_meta', $userId, self::META_KEY);
    }
}
