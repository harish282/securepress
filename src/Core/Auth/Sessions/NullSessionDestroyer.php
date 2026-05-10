<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Sessions;

/**
 * No-op destroyer used in tests / environments where WP session tokens aren't
 * available. Calls succeed silently; nothing is destroyed.
 */
final class NullSessionDestroyer implements SessionDestroyerInterface
{
    public function destroyForToken(int $userId, string $sessionToken): void
    {
        // No-op. WP session tokens aren't reachable from this context.
    }
}
