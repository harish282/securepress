<?php

declare(strict_types=1);

namespace PressSentinel\Core\Url;

use PressSentinel\Core\Support\WpHelper;

/**
 * Resolves the URL-signing secret with the priority:
 *
 *  1. `PRESS_SENTINEL_URL_SECRET` environment variable (recommended for production).
 *     Pinning a stable secret means existing signed URLs survive WordPress salt rotation.
 *  2. `wp_salt('auth')` — zero-config fallback that works on any WordPress install.
 *  3. {@see SignedUrlException} — never falls back to a hardcoded value.
 *
 * The env variable wins because operators can rotate it independently and replicate it across
 * a multi-server deployment without depending on `wp-config.php` salt synchronization.
 */
final class WpSaltSecretProvider implements SecretProviderInterface
{
    public const ENV_NAME = 'PRESS_SENTINEL_URL_SECRET';

    public const SALT_SCHEME = 'auth';

    public function secret(): string
    {
        $env = getenv(self::ENV_NAME);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $salt = WpHelper::salt(self::SALT_SCHEME);
        if ($salt !== '') {
            return $salt;
        }

        throw new SignedUrlException(sprintf(
            'Cannot resolve URL-signing secret: set the "%s" environment variable or ensure wp_salt() is available.',
            self::ENV_NAME
        ));
    }
}
