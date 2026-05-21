<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\Sessions;

/**
 * Plugs PressSentinel session revocation into the *real* WordPress session-token store.
 *
 * Without this, {@see SessionService::revoke()} only marks our row as revoked — the
 * user's WP session cookie would keep working until WP's own expiry. The default
 * production binding wraps `WP_Session_Tokens::get_instance($userId)->destroy($token)`;
 * the test stub is a no-op so unit tests don't need a live `$wpdb`.
 */
interface SessionDestroyerInterface
{
    public function destroyForToken(int $userId, string $sessionToken): void;
}
