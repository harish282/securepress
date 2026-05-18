<?php

declare(strict_types=1);

namespace PressSentinel\Core\Config;

use PressSentinel\Core\Licensing\LicenseHmacSecretProvisioner;
use PressSentinel\Core\Support\WpHelper;

/**
 * Merged file config + environment.
 *
 * Licensing secret resolution (first match wins): non-empty
 * {@see PRESS_SENTINEL_LICENSE_SECRET} constant → strong value in option
 * {@see LicenseHmacSecretProvisioner::OPTION_NAME} (auto-generated, plug-and-play)
 * → `PRESS_SENTINEL_LICENSE_SECRET` environment variable → shipped file default →
 * {@see apply_filters()} for hook `presssentinel_licensing_secret`.
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

        $config['app']['env'] = $this->env('PRESS_SENTINEL_APP_ENV', (string) ($config['app']['env'] ?? 'production'));
        $config['app']['debug'] = filter_var(
            $this->env('PRESS_SENTINEL_DEBUG', ($config['app']['debug'] ?? false) ? 'true' : 'false'),
            FILTER_VALIDATE_BOOL
        );
        $config['requirements']['php'] = $this->env('PRESS_SENTINEL_MIN_PHP_VERSION', (string) ($config['requirements']['php'] ?? '8.2.0'));
        $config['requirements']['wordpress'] = $this->env('PRESS_SENTINEL_MIN_WP_VERSION', (string) ($config['requirements']['wordpress'] ?? '6.4'));
        $config['logging']['channel'] = $this->env('PRESS_SENTINEL_LOG_CHANNEL', (string) ($config['logging']['channel'] ?? 'file'));
        $config['logging']['level'] = $this->env('PRESS_SENTINEL_LOG_LEVEL', (string) ($config['logging']['level'] ?? 'info'));
        $config['logging']['file'] = $this->env('PRESS_SENTINEL_LOG_FILE', (string) ($config['logging']['file'] ?? 'presssentinel.log'));
        $config['signed_url']['ttl_default'] = (int) $this->env(
            'PRESS_SENTINEL_SIGNED_URL_TTL',
            (string) ($config['signed_url']['ttl_default'] ?? 3600)
        );

        if (!is_array($config['pro_license'] ?? null)) {
            $config['pro_license'] = [];
        }

        $earlyDefault = ($config['pro_license']['early_access'] ?? false) ? 'true' : 'false';
        $config['pro_license']['early_access'] = filter_var(
            $this->env('PRESS_SENTINEL_EARLY_ACCESS', $earlyDefault),
            FILTER_VALIDATE_BOOL
        );

        if (!is_array($config['pro_license']['beta_trial'] ?? null)) {
            $config['pro_license']['beta_trial'] = [];
        }

        $betaTrialDefault = ($config['pro_license']['beta_trial']['enabled'] ?? false) ? 'true' : 'false';
        $config['pro_license']['beta_trial']['enabled'] = filter_var(
            $this->env('PRESS_SENTINEL_BETA_TRIAL_ENABLED', $betaTrialDefault),
            FILTER_VALIDATE_BOOL
        );
        $trialDays = (int) $this->env(
            'PRESS_SENTINEL_BETA_TRIAL_DURATION_DAYS',
            (string) ($config['pro_license']['beta_trial']['duration_days'] ?? 182)
        );
        $config['pro_license']['beta_trial']['duration_days'] = max(1, min(730, $trialDays));

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
                min(3650, (int) $this->env(
                    'PRESS_SENTINEL_AUDIT_RETENTION_DAYS',
                    (string) ($audit['retention_days'] ?? 90)
                ))
            );
            $config['audit_log']['auto_prune_enabled'] = filter_var(
                $this->env(
                    'PRESS_SENTINEL_AUDIT_AUTO_PRUNE',
                    ($audit['auto_prune_enabled'] ?? true) ? 'true' : 'false'
                ),
                FILTER_VALIDATE_BOOL
            );
            $config['audit_log']['min_storage_level'] = $this->env(
                'PRESS_SENTINEL_AUDIT_MIN_STORAGE_LEVEL',
                (string) ($audit['min_storage_level'] ?? 'notice')
            );
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

        return $this->env('PRESS_SENTINEL_LICENSE_SECRET', $defaultLicenseSecret);
    }

    private function env(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}
