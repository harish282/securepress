<?php

declare(strict_types=1);

namespace PressSentinel\Core\Config;

use PressSentinel\Core\Licensing\LicenseHmacSecretProvisioner;
use PressSentinel\Core\Support\WpHelper;

/**
 * Loads merged settings from {@see PRESS_SENTINEL_CONFIG_PATH}/plugin.php.
 *
 * Licensing secret resolution (first match wins): non-empty
 * {@see PRESS_SENTINEL_LICENSE_SECRET} constant → strong value in option
 * {@see LicenseHmacSecretProvisioner::OPTION_NAME} (auto-generated, plug-and-play)
 * → value in `licensing.secret` from the config file → {@see apply_filters()}
 * for hook `presssentinel_licensing_secret`.
 *
 * PHPUnit sets {@see PRESS_SENTINEL_TESTING}; {@see load()} forces early access and
 * beta trial off unless a test opts in via the `presssentinel_config` filter.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items;

    public function __construct()
    {
        $this->items = $this->load();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $default;
        }

        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $file = PRESS_SENTINEL_CONFIG_PATH . '/plugin.php';
        $config = is_readable($file) ? require $file : [];

        if (!is_array($config)) {
            $config = [];
        }

        $config['app']['env'] = (string) ($config['app']['env'] ?? 'production');
        $config['app']['debug'] = (bool) ($config['app']['debug'] ?? false);
        $config['requirements']['php'] = (string) ($config['requirements']['php'] ?? '8.2.0');
        $config['requirements']['wordpress'] = (string) ($config['requirements']['wordpress'] ?? '6.4');
        $config['logging']['channel'] = (string) ($config['logging']['channel'] ?? 'file');
        $config['logging']['level'] = (string) ($config['logging']['level'] ?? 'info');
        $config['logging']['file'] = (string) ($config['logging']['file'] ?? 'presssentinel.log');
        $config['signed_url']['ttl_default'] = (int) ($config['signed_url']['ttl_default'] ?? 3600);
        $config['signed_url']['secret'] = (string) ($config['signed_url']['secret'] ?? '');

        if (!is_array($config['pro_license'] ?? null)) {
            $config['pro_license'] = [];
        }

        $config['pro_license']['license_key'] = (string) ($config['pro_license']['license_key'] ?? '');
        $config['pro_license']['early_access'] = (bool) ($config['pro_license']['early_access'] ?? false);

        if (!is_array($config['pro_license']['beta_trial'] ?? null)) {
            $config['pro_license']['beta_trial'] = [];
        }

        $config['pro_license']['beta_trial']['enabled'] = (bool) (
            $config['pro_license']['beta_trial']['enabled'] ?? false
        );
        $trialDays = (int) ($config['pro_license']['beta_trial']['duration_days'] ?? 182);
        $config['pro_license']['beta_trial']['duration_days'] = max(1, min(730, $trialDays));

        if (!is_array($config['recovery'] ?? null)) {
            $config['recovery'] = [];
        }
        $config['recovery']['safe_mode'] = (bool) ($config['recovery']['safe_mode'] ?? false);

        if (!is_array($config['licensing'] ?? null)) {
            $config['licensing'] = [];
        }
        $defaultLicenseSecret = (string) ($config['licensing']['secret'] ?? 'change-me-in-production');
        $secret = $this->resolveLicensingSecret($defaultLicenseSecret);
        if (\function_exists('apply_filters')) {
            $filtered = \apply_filters('presssentinel_licensing_secret', $secret);
            if (is_string($filtered) && $filtered !== '') {
                $secret = $filtered;
            }
        }
        $config['licensing']['secret'] = $secret;

        if (is_array($config['audit_log'] ?? null)) {
            $audit = $config['audit_log'];
            $config['audit_log']['retention_days'] = max(
                0,
                min(3650, (int) ($audit['retention_days'] ?? 90))
            );
            $config['audit_log']['auto_prune_enabled'] = (bool) ($audit['auto_prune_enabled'] ?? true);
            $config['audit_log']['min_storage_level'] = (string) ($audit['min_storage_level'] ?? 'notice');
        }

        if (\defined('PRESS_SENTINEL_TESTING') && PRESS_SENTINEL_TESTING) {
            $config['pro_license']['early_access'] = false;
            $config['pro_license']['beta_trial']['enabled'] = false;
        }

        if (\function_exists('apply_filters')) {
            $filtered = \apply_filters('presssentinel_config', $config);
            if (is_array($filtered)) {
                $config = $filtered;
            }
        }

        return $config;
    }

    private function resolveLicensingSecret(string $defaultLicenseSecret): string
    {
        if (\defined('PRESS_SENTINEL_LICENSE_SECRET')) {
            $fromConstant = \constant('PRESS_SENTINEL_LICENSE_SECRET');
            if (is_string($fromConstant) && $fromConstant !== '') {
                return $fromConstant;
            }
        }

        $dbRaw = WpHelper::getOption(LicenseHmacSecretProvisioner::OPTION_NAME, '');
        $dbSecret = is_string($dbRaw) ? trim($dbRaw) : '';
        if (LicenseHmacSecretProvisioner::isStoredSecretStrong($dbSecret)) {
            return $dbSecret;
        }

        return $defaultLicenseSecret;
    }
}
