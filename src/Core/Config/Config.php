<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Config;

use NiyiGuard\Core\Support\WpHelper;

/**
 * Loads merged settings from {@see NIYIGUARD_CONFIG_PATH}/plugin.php.
 *
 * Internal secret resolution: `NIYIGUARD_INTERNAL_SECRET` constant →
 * `security.internal_secret` in config → filter `niyiguard_internal_secret`.
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
        $file = NIYIGUARD_CONFIG_PATH . '/plugin.php';
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
        $config['logging']['file'] = (string) ($config['logging']['file'] ?? 'niyiguard.log');
        $config['signed_url']['ttl_default'] = (int) ($config['signed_url']['ttl_default'] ?? 3600);
        $config['signed_url']['secret'] = (string) ($config['signed_url']['secret'] ?? '');

        if (!is_array($config['support'] ?? null)) {
            $config['support'] = [];
        }
        $config['support']['review_url'] = (string) ($config['support']['review_url'] ?? '');
        $config['support']['donation_url'] = (string) ($config['support']['donation_url'] ?? '');
        $config['support']['donation_label'] = (string) (
            $config['support']['donation_label'] ?? 'Support on Ko-fi'
        );

        if (!is_array($config['recovery'] ?? null)) {
            $config['recovery'] = [];
        }
        $config['recovery']['safe_mode'] = (bool) ($config['recovery']['safe_mode'] ?? false);

        if (!is_array($config['security'] ?? null)) {
            $config['security'] = [];
        }
        $defaultSecret = (string) ($config['security']['internal_secret'] ?? 'change-me-in-production');
        $secret = $this->resolveInternalSecret($defaultSecret);
        if (\function_exists('apply_filters')) {
            $filtered = \apply_filters('niyiguard_internal_secret', $secret);
            if (is_string($filtered) && $filtered !== '') {
                $secret = $filtered;
            }
        }
        $config['security']['internal_secret'] = $secret;

        if (is_array($config['audit_log'] ?? null)) {
            $audit = $config['audit_log'];
            $config['audit_log']['retention_days'] = max(
                0,
                min(3650, (int) ($audit['retention_days'] ?? 90))
            );
            $config['audit_log']['auto_prune_enabled'] = (bool) ($audit['auto_prune_enabled'] ?? true);
            $config['audit_log']['min_storage_level'] = (string) ($audit['min_storage_level'] ?? 'notice');
        }

        if (\function_exists('apply_filters')) {
            $support = $config['support'];
            $filteredSupport = \apply_filters('niyiguard_support', $support);
            if (is_array($filteredSupport)) {
                $config['support'] = array_merge($support, $filteredSupport);
            }

            $filtered = \apply_filters('niyiguard_config', $config);
            if (is_array($filtered)) {
                $config = $filtered;
            }
        }

        return $config;
    }

    private function resolveInternalSecret(string $defaultSecret): string
    {
        if (\defined('NIYIGUARD_INTERNAL_SECRET')) {
            $fromConstant = \constant('NIYIGUARD_INTERNAL_SECRET');
            if (is_string($fromConstant) && $fromConstant !== '') {
                return $fromConstant;
            }
        }

        return $defaultSecret;
    }
}
