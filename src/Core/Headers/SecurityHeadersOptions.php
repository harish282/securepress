<?php

declare(strict_types=1);

namespace SecurePress\Core\Headers;

use SecurePress\Core\Config\Config;
use SecurePress\Core\Support\WpHelper;

/**
 * Resolves the effective security-headers configuration for the current request.
 *
 * Defaults come from `config/plugin.php` (`security_headers.*`), while the admin Settings
 * page persists overrides into the `wp_option` named by {@see self::OPTION_NAME}. This
 * class reads both and returns a fully-merged, type-coerced array that the registry
 * factory can consume without any further normalization.
 *
 * Storage shape (single autoloaded option):
 *
 *     [
 *         'hsts' => [
 *             'enabled'             => bool,
 *             'max_age'             => int,
 *             'include_subdomains'  => bool,
 *             'preload'             => bool,
 *         ],
 *         'csp' => [
 *             'enabled'     => bool,
 *             'policy'      => string,
 *             'report_only' => bool,
 *         ],
 *         'x_frame_options'     => ['enabled' => bool, 'value' => 'DENY'|'SAMEORIGIN'],
 *         'referrer_policy'     => ['enabled' => bool, 'policy' => string],
 *         'permissions_policy'  => ['enabled' => bool, 'policy' => string],
 *         'x_content_type_options' => ['enabled' => bool],
 *     ]
 */
final class SecurityHeadersOptions
{
    public const OPTION_NAME = 'securepress_security_headers';

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $defaults = $this->normalize(
            is_array($this->config->get('security_headers')) ? $this->config->get('security_headers') : []
        );

        $stored = WpHelper::getOption(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return $this->normalize(array_replace_recursive($defaults, $stored));
    }

    /**
     * Sanitizes the raw POSTed array from the Settings page into the canonical storage shape.
     *
     * Used as the `register_setting()` sanitize callback.
     *
     * @param mixed $input
     * @return array<string, array<string, mixed>>
     */
    public function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        return $this->normalize($input);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, array<string, mixed>>
     */
    private function normalize(array $raw): array
    {
        $hsts = is_array($raw['hsts'] ?? null) ? $raw['hsts'] : [];
        $csp = is_array($raw['csp'] ?? null) ? $raw['csp'] : [];
        $xfo = is_array($raw['x_frame_options'] ?? null) ? $raw['x_frame_options'] : [];
        $ref = is_array($raw['referrer_policy'] ?? null) ? $raw['referrer_policy'] : [];
        $perm = is_array($raw['permissions_policy'] ?? null) ? $raw['permissions_policy'] : [];
        $xcto = is_array($raw['x_content_type_options'] ?? null) ? $raw['x_content_type_options'] : [];

        return [
            'hsts' => [
                'enabled' => $this->toBool($hsts['enabled'] ?? false),
                'max_age' => max(0, (int) ($hsts['max_age'] ?? 31_536_000)),
                'include_subdomains' => $this->toBool($hsts['include_subdomains'] ?? false),
                'preload' => $this->toBool($hsts['preload'] ?? false),
            ],
            'csp' => [
                'enabled' => $this->toBool($csp['enabled'] ?? false),
                'policy' => is_string($csp['policy'] ?? null) ? trim($csp['policy']) : '',
                'report_only' => $this->toBool($csp['report_only'] ?? true),
            ],
            'x_frame_options' => [
                'enabled' => $this->toBool($xfo['enabled'] ?? true),
                'value' => $this->normalizeXfoValue($xfo['value'] ?? null),
            ],
            'referrer_policy' => [
                'enabled' => $this->toBool($ref['enabled'] ?? true),
                'policy' => $this->normalizeReferrerPolicy($ref['policy'] ?? null),
            ],
            'permissions_policy' => [
                'enabled' => $this->toBool($perm['enabled'] ?? true),
                'policy' => is_string($perm['policy'] ?? null) ? trim($perm['policy']) : '',
            ],
            'x_content_type_options' => [
                'enabled' => $this->toBool($xcto['enabled'] ?? true),
            ],
        ];
    }

    private function toBool(mixed $value): bool
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

    private function normalizeXfoValue(mixed $value): string
    {
        $candidate = is_string($value) ? strtoupper(trim($value)) : '';

        return in_array($candidate, XFrameOptionsHeader::VALID_VALUES, true)
            ? $candidate
            : XFrameOptionsHeader::DEFAULT_VALUE;
    }

    private function normalizeReferrerPolicy(mixed $value): string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($candidate, ReferrerPolicyHeader::VALID_POLICIES, true)
            ? $candidate
            : ReferrerPolicyHeader::DEFAULT_POLICY;
    }
}
