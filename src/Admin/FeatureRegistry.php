<?php

declare(strict_types=1);

namespace NiyiGuard\Admin;

use NiyiGuard\Core\Audit\AuditLogOptions;
use NiyiGuard\Core\Auth\AuthHardeningOptions;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\Integrity\IntegrityOptions;
use NiyiGuard\Core\RateLimit\RateLimitOptions;
use NiyiGuard\Core\UrlDisguise\UrlDisguiseOptions;
use NiyiGuard\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * Source of truth for the "what features can the dashboard toggle?" question.
 */
final class FeatureRegistry
{
    /** @var list<FeatureDescriptor> */
    private array $features = [];

    public function __construct(
        AuditLogOptions $auditLog,
        AuthHardeningOptions $authHardening,
        SecurityHeadersOptions $securityHeaders,
        IntegrityOptions $integrity,
        WooCommerceProtectionOptions $wcProtection,
        RateLimitOptions $rateLimit,
        UrlDisguiseOptions $urlDisguise,
    ) {
        $this->features = [
            new FeatureDescriptor(
                key: 'auth_hardening',
                label: 'Authentication Hardening',
                description: 'Login lockout, two-factor authentication, session tracking and suspicious-login alerts.',
                isEnabled: static fn (): bool => $authHardening->isEnabled(),
                setEnabled: static function (bool $on) use ($authHardening): void {
                    $authHardening->setEnabled($on);
                },
                isPro: false,
            ),
            new FeatureDescriptor(
                key: 'security_headers',
                label: 'Security Headers',
                description: 'HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy and X-Content-Type-Options on every response.',
                isEnabled: static fn (): bool => $securityHeaders->isEnabled(),
                setEnabled: static function (bool $on) use ($securityHeaders): void {
                    $securityHeaders->setEnabled($on);
                },
                isPro: false,
            ),
            new FeatureDescriptor(
                key: 'rate_limit',
                label: 'Rate Limiting',
                description: 'Global per-user / per-IP request throttling with HTTP 429 responses and standard Retry-After / X-RateLimit headers.',
                isEnabled: static fn (): bool => $rateLimit->isEnabled(),
                setEnabled: static function (bool $on) use ($rateLimit): void {
                    $rateLimit->setEnabled($on);
                },
                isPro: false,
            ),
            new FeatureDescriptor(
                key: 'url_disguise',
                label: 'URL disguise',
                description: 'Optional custom path for the login screen instead of wp-login.php.',
                isEnabled: static fn (): bool => $urlDisguise->isEnabled(),
                setEnabled: static function (bool $on) use ($urlDisguise): void {
                    $urlDisguise->setEnabled($on);
                },
                isPro: false,
            ),
            new FeatureDescriptor(
                key: 'file_integrity',
                label: 'File Integrity Monitoring',
                description: 'Hash and re-scan core, plugins and uploads; flag unexpected PHP and modified core files.',
                isEnabled: static fn (): bool => $integrity->isEnabled(),
                setEnabled: static function (bool $on) use ($integrity): void {
                    $integrity->setEnabled($on);
                },
                isPro: false,
            ),
            new FeatureDescriptor(
                key: 'audit_log',
                label: 'Audit Log',
                description: 'Record authentication events, plugin changes, role changes, file-editor edits and WooCommerce actions.',
                isEnabled: static fn (): bool => $auditLog->isEnabled(),
                setEnabled: static function (bool $on) use ($auditLog): void {
                    $auditLog->setEnabled($on);
                },
                isPro: false,
            ),
            new FeatureDescriptor(
                key: 'woocommerce_protection',
                label: 'WooCommerce Protection',
                description: 'Behavioural abuse prevention for checkout, registration, cart and REST endpoints.',
                isEnabled: static fn (): bool => $wcProtection->isEnabled(),
                setEnabled: static function (bool $on) use ($wcProtection): void {
                    $wcProtection->setEnabled($on);
                },
                isPro: false,
            ),
        ];
    }

    /**
     * @return list<FeatureDescriptor>
     */
    public function all(): array
    {
        return $this->features;
    }

    public function get(string $key): ?FeatureDescriptor
    {
        foreach ($this->features as $feature) {
            if ($feature->key === $key) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * @param array<string, bool> $desired
     * @return list<string> Feature keys that were actually flipped.
     */
    public function apply(array $desired): array
    {
        $changed = [];
        foreach ($this->features as $feature) {
            if (!array_key_exists($feature->key, $desired)) {
                continue;
            }
            $next = $desired[$feature->key];
            if ($feature->isEnabled() === $next) {
                continue;
            }
            $feature->setEnabled($next);
            $changed[] = $feature->key;
        }

        return $changed;
    }
}
