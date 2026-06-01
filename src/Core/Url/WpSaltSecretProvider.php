<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Url;

use NiyiGuard\Core\Support\WpHelper;

/**
 * Resolves the URL-signing secret with the priority:
 *
 *  1. Non-empty `signed_url.secret` in config/plugin.php (stable across WP salt rotation).
 *  2. `wp_salt('auth')` — zero-config fallback on any WordPress install.
 *  3. {@see SignedUrlException} — never falls back to a hardcoded value.
 */
final class WpSaltSecretProvider implements SecretProviderInterface
{
    public function secret(): string
    {
        $configured = $this->configuredSecret();
        if ($configured !== '') {
            return $configured;
        }

        $salt = WpHelper::salt(self::SALT_SCHEME);
        if ($salt !== '') {
            return $salt;
        }

        throw new SignedUrlException(
            'Cannot resolve URL-signing secret: set signed_url.secret in config/plugin.php '
            . 'or ensure wp_salt() is available.'
        );
    }

    private function configuredSecret(): string
    {
        if (!\defined('NIYIGUARD_CONFIG_PATH')) {
            return '';
        }

        $file = NIYIGUARD_CONFIG_PATH . '/plugin.php';
        if (!is_readable($file)) {
            return '';
        }

        $config = require $file;

        return is_array($config) ? trim((string) ($config['signed_url']['secret'] ?? '')) : '';
    }
}
