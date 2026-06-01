<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Licensing;

use NiyiGuard\Core\Support\WpHelper;

/**
 * Persists a per-install HMAC secret in the WordPress options table so offline
 * license keys work without wp-config edits or hosting environment variables.
 *
 * The secret is created on first {@see ensure()} (plugin activation and each
 * boot before {@see \NiyiGuard\Core\Config\Config} resolves). Your issuance tooling
 * must sign keys with the same material shown on NiyiGuard → License.
 */
final class LicenseHmacSecretProvisioner
{
    public const OPTION_NAME = 'niyiguard_license_hmac_secret';

    private const MIN_LENGTH = 24;

    public static function isStoredSecretStrong(string $secret): bool
    {
        $secret = trim($secret);
        if ($secret === '' || $secret === 'change-me-in-production') {
            return false;
        }

        return strlen($secret) >= self::MIN_LENGTH;
    }

    public static function ensure(): void
    {
        $existing = trim((string) WpHelper::getOption(self::OPTION_NAME, ''));
        if (self::isStoredSecretStrong($existing)) {
            return;
        }

        try {
            $blob = random_bytes(32);
        } catch (\Throwable) {
            if (\function_exists('wp_generate_password')) {
                $candidate = (string) \call_user_func('wp_generate_password', 64, true, true);
                if (self::isStoredSecretStrong($candidate)) {
                    WpHelper::updateOption(self::OPTION_NAME, $candidate);
                }
            }

            return;
        }

        WpHelper::updateOption(self::OPTION_NAME, bin2hex($blob));
    }
}
