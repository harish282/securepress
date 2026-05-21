<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\Sessions;

/**
 * Destroys the matching entry in WordPress core's session token store so the user's
 * cookie stops authenticating immediately after {@see SessionService::revoke()}.
 */
final class WpSessionDestroyer implements SessionDestroyerInterface
{
    public function destroyForToken(int $userId, string $sessionToken): void
    {
        if ($userId <= 0 || $sessionToken === '' || !\class_exists('WP_Session_Tokens')) {
            return;
        }

        \WP_Session_Tokens::get_instance($userId)->destroy($sessionToken);
    }
}
