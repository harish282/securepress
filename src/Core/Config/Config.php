<?php

declare(strict_types=1);

namespace SecurePress\Core\Config;

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
        $file = SECUREPRESS_CONFIG_PATH . '/plugin.php';
        $config = is_readable($file) ? require $file : [];

        if (!is_array($config)) {
            $config = [];
        }

        $config['app']['env'] = $this->env('SECUREPRESS_APP_ENV', (string) ($config['app']['env'] ?? 'production'));
        $config['app']['debug'] = filter_var(
            $this->env('SECUREPRESS_DEBUG', ($config['app']['debug'] ?? false) ? 'true' : 'false'),
            FILTER_VALIDATE_BOOL
        );
        $config['requirements']['php'] = $this->env('SECUREPRESS_MIN_PHP_VERSION', (string) ($config['requirements']['php'] ?? '8.2.0'));
        $config['requirements']['wordpress'] = $this->env('SECUREPRESS_MIN_WP_VERSION', (string) ($config['requirements']['wordpress'] ?? '6.4'));
        $config['logging']['channel'] = $this->env('SECUREPRESS_LOG_CHANNEL', (string) ($config['logging']['channel'] ?? 'file'));
        $config['logging']['level'] = $this->env('SECUREPRESS_LOG_LEVEL', (string) ($config['logging']['level'] ?? 'info'));
        $config['logging']['file'] = $this->env('SECUREPRESS_LOG_FILE', (string) ($config['logging']['file'] ?? 'securepress.log'));
        $config['signed_url']['ttl_default'] = (int) $this->env(
            'SECUREPRESS_SIGNED_URL_TTL',
            (string) ($config['signed_url']['ttl_default'] ?? 3600)
        );

        $betaTrialDefault = ($config['pro_license']['beta_trial']['enabled'] ?? false) ? 'true' : 'false';
        $config['pro_license']['beta_trial']['enabled'] = filter_var(
            $this->env('SECUREPRESS_BETA_TRIAL_ENABLED', $betaTrialDefault),
            FILTER_VALIDATE_BOOL
        );
        $trialDays = (int) $this->env(
            'SECUREPRESS_BETA_TRIAL_DURATION_DAYS',
            (string) ($config['pro_license']['beta_trial']['duration_days'] ?? 182)
        );
        $config['pro_license']['beta_trial']['duration_days'] = max(1, min(730, $trialDays));

        return $config;
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
