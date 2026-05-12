<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Admin;

use SecurePress\Core\Config\Config;
use SecurePress\Core\Support\WpHelper;

/**
 * Resolves the effective WooCommerce-protection configuration for the current request.
 *
 * Defaults live in `config/plugin.php` (`woocommerce_protection.*`); the admin
 * Settings page persists overrides into a single autoloaded `wp_option`. This is the
 * same pattern as {@see \SecurePress\Core\Auth\AuthHardeningOptions},
 * {@see \SecurePress\Core\Integrity\IntegrityOptions}, and
 * {@see \SecurePress\Core\Headers\SecurityHeadersOptions} — pick a pattern, stick to it.
 *
 * Schema (normalised):
 *
 *     [
 *         'enabled' => bool,
 *         'checkout' => [
 *             'enabled' => bool,
 *             'velocity_soft' => int,
 *             'velocity_hard' => int,
 *             'velocity_window' => int,
 *             'min_seconds_to_submit' => int,
 *             'fraud' => ['challenge_threshold' => int, 'deny_threshold' => int],
 *         ],
 *         'registration' => [
 *             'enabled' => bool,
 *             'rate_limit' => int,
 *             'window' => int,
 *             'deny_disposable_emails' => bool,
 *             'honeypot_field_name' => string,
 *             'min_seconds_to_submit' => int,
 *         ],
 *         'api' => [
 *             'enabled' => bool,
 *             'default_limit' => int,
 *             'default_window' => int,
 *             'pass_when_authenticated' => bool,
 *             'deny_on_scanner_ua' => bool,
 *             'per_route' => array<string, array{limit:int, window:int}>,
 *         ],
 *         'cart' => [
 *             'enabled' => bool,
 *             'velocity_soft' => int,
 *             'velocity_hard' => int,
 *             'window' => int,
 *             'coupon_soft' => int,
 *             'coupon_hard' => int,
 *         ],
 *     ]
 */
final class WooCommerceProtectionOptions
{
    public const OPTION_NAME = 'securepress_wc_protection';

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $defaults = $this->normalize(
            is_array($this->config->get('woocommerce_protection')) ? $this->config->get('woocommerce_protection') : []
        );
        $stored = WpHelper::getOption(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return $this->normalize(array_replace_recursive($defaults, $stored));
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->all()['enabled'] ?? true);
    }

    public function isCheckoutEnabled(): bool
    {
        return $this->isEnabled() && (bool) ($this->all()['checkout']['enabled'] ?? true);
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->isEnabled() && (bool) ($this->all()['registration']['enabled'] ?? true);
    }

    public function isApiEnabled(): bool
    {
        return $this->isEnabled() && (bool) ($this->all()['api']['enabled'] ?? true);
    }

    public function isCartEnabled(): bool
    {
        return $this->isEnabled() && (bool) ($this->all()['cart']['enabled'] ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitize(mixed $input): array
    {
        return $this->normalize(is_array($input) ? $input : []);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $checkout = is_array($raw['checkout'] ?? null) ? $raw['checkout'] : [];
        $registration = is_array($raw['registration'] ?? null) ? $raw['registration'] : [];
        $api = is_array($raw['api'] ?? null) ? $raw['api'] : [];
        $cart = is_array($raw['cart'] ?? null) ? $raw['cart'] : [];
        $fraud = is_array($checkout['fraud'] ?? null) ? $checkout['fraud'] : [];
        $perRoute = is_array($api['per_route'] ?? null) ? $api['per_route'] : [];

        return [
            'enabled' => $this->bool($raw['enabled'] ?? true),
            'checkout' => [
                'enabled' => $this->bool($checkout['enabled'] ?? true),
                'velocity_soft' => $this->intIn($checkout['velocity_soft'] ?? 3, 1, 999),
                'velocity_hard' => $this->intIn($checkout['velocity_hard'] ?? 8, 1, 9999),
                'velocity_window' => $this->intIn($checkout['velocity_window'] ?? 120, 5, 86400),
                'min_seconds_to_submit' => $this->intIn($checkout['min_seconds_to_submit'] ?? 3, 0, 3600),
                'fraud' => [
                    'challenge_threshold' => $this->intIn($fraud['challenge_threshold'] ?? 40, 1, 1000),
                    'deny_threshold' => $this->intIn($fraud['deny_threshold'] ?? 80, 1, 1000),
                ],
            ],
            'registration' => [
                'enabled' => $this->bool($registration['enabled'] ?? true),
                'rate_limit' => $this->intIn($registration['rate_limit'] ?? 5, 1, 1000),
                'window' => $this->intIn($registration['window'] ?? 600, 30, 86400),
                'deny_disposable_emails' => $this->bool($registration['deny_disposable_emails'] ?? true),
                'honeypot_field_name' => $this->string($registration['honeypot_field_name'] ?? 'securepress_hp'),
                'min_seconds_to_submit' => $this->intIn($registration['min_seconds_to_submit'] ?? 2, 0, 600),
            ],
            'api' => [
                'enabled' => $this->bool($api['enabled'] ?? true),
                'default_limit' => $this->intIn($api['default_limit'] ?? 60, 1, 100000),
                'default_window' => $this->intIn($api['default_window'] ?? 60, 1, 86400),
                'pass_when_authenticated' => $this->bool($api['pass_when_authenticated'] ?? true),
                'deny_on_scanner_ua' => $this->bool($api['deny_on_scanner_ua'] ?? true),
                'per_route' => $this->normalizePerRoute($perRoute),
            ],
            'cart' => [
                'enabled' => $this->bool($cart['enabled'] ?? true),
                'velocity_soft' => $this->intIn($cart['velocity_soft'] ?? 20, 1, 9999),
                'velocity_hard' => $this->intIn($cart['velocity_hard'] ?? 60, 1, 99999),
                'window' => $this->intIn($cart['window'] ?? 60, 5, 86400),
                'coupon_soft' => $this->intIn($cart['coupon_soft'] ?? 4, 1, 999),
                'coupon_hard' => $this->intIn($cart['coupon_hard'] ?? 10, 1, 9999),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, array{limit:int, window:int}>
     */
    private function normalizePerRoute(array $raw): array
    {
        $out = [];
        foreach ($raw as $prefix => $cfg) {
            if (!is_string($prefix) || $prefix === '' || !is_array($cfg)) {
                continue;
            }
            $out[$prefix] = [
                'limit' => $this->intIn($cfg['limit'] ?? 60, 1, 100000),
                'window' => $this->intIn($cfg['window'] ?? 60, 1, 86400),
            ];
        }

        return $out;
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }

    private function intIn(mixed $value, int $min, int $max): int
    {
        $n = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $n));
    }

    private function string(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? 'securepress_hp' : $value;
    }
}
