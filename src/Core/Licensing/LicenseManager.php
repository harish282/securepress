<?php

declare(strict_types=1);

namespace SecurePress\Core\Licensing;

use SecurePress\Core\Support\WpHelper;

/**
 * The plugin's single source of truth for "is this install Pro?".
 *
 * Resolution order (first match wins, low priority → high):
 *
 *   1. `wp_option('securepress_pro_license')` — the admin-managed key on the Settings → License page.
 *   2. `SECUREPRESS_PRO_LICENSE` environment variable — handy for CI / containerised installs.
 *   3. `SECUREPRESS_PRO_LICENSE` constant — for hard-coded staging boxes.
 *   4. `apply_filters('securepress.pro_license', '')` — programmatic override (extensions, tests).
 *
 * After resolution the key is validated through the injected
 * {@see LicenseValidatorInterface} and the result is cached **for the lifetime of the
 * current request only**. We deliberately do NOT persist the status to a transient:
 * the cost of validation is microseconds (HMAC over ~30 bytes), and persisted status
 * is the classic vector for "I revoked the key but Pro still feels active" support
 * tickets.
 *
 * The "is Pro" check is wrapped in a filter (`securepress.is_pro`) so:
 *  - test suites can flip behaviour without faking a license key;
 *  - integration packs (e.g., a future "Pro Bundle" plugin) can flip behaviour for
 *    the whole site without rewriting the manager.
 */
final class LicenseManager
{
    public const OPTION_NAME = 'securepress_pro_license';

    public const ENV_VAR = 'SECUREPRESS_PRO_LICENSE';

    public const PHP_CONSTANT = 'SECUREPRESS_PRO_LICENSE';

    public const FILTER_LICENSE = 'securepress.pro_license';

    public const FILTER_IS_PRO = 'securepress.is_pro';

    private ?LicenseStatus $cached = null;

    public function __construct(private readonly LicenseValidatorInterface $validator)
    {
    }

    public function isPro(): bool
    {
        $isPro = $this->status()->isActive();

        if (\function_exists('apply_filters')) {
            $isPro = (bool) \call_user_func('apply_filters', self::FILTER_IS_PRO, $isPro, $this);
        }

        return $isPro;
    }

    public function tier(): string
    {
        return $this->status()->tier;
    }

    public function status(): LicenseStatus
    {
        return $this->cached ??= $this->validator->validate($this->resolveKey());
    }

    /**
     * Persists a license key into the autoloaded option, re-validates, and returns the
     * resulting status. An invalid key is still persisted (so the admin can see WHY it
     * was rejected), but {@see isPro()} will return false.
     */
    public function setLicense(string $key): LicenseStatus
    {
        $key = trim($key);
        WpHelper::updateOption(self::OPTION_NAME, $key);
        $this->cached = null;

        return $this->status();
    }

    public function clearLicense(): void
    {
        WpHelper::deleteOption(self::OPTION_NAME);
        $this->cached = null;
    }

    /**
     * Resets the in-memory cache. Required between tests so flipping the filter is
     * observable.
     */
    public function flushCache(): void
    {
        $this->cached = null;
    }

    private function resolveKey(): string
    {
        $candidates = [
            (string) WpHelper::getOption(self::OPTION_NAME, ''),
            (string) (getenv(self::ENV_VAR) ?: ''),
            \defined(self::PHP_CONSTANT) ? (string) \constant(self::PHP_CONSTANT) : '',
        ];

        if (\function_exists('apply_filters')) {
            $candidates[] = (string) \call_user_func('apply_filters', self::FILTER_LICENSE, '', $this);
        }

        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }
}
