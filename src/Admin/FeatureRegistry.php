<?php

declare(strict_types=1);

namespace SecurePress\Admin;

use SecurePress\Core\Audit\AuditLogOptions;
use SecurePress\Core\Auth\AuthHardeningOptions;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Core\Integrity\IntegrityOptions;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\RateLimit\RateLimitOptions;
use SecurePress\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * Source of truth for the "what features can the dashboard toggle?" question.
 *
 * Each registered feature is a triple of:
 *
 *  - `key`            — stable identifier used by the dashboard form fields
 *                       (e.g. `auth_hardening`). Never user-visible.
 *  - `label`          — short title shown in the dashboard tile / toggle row.
 *  - `description`    — one-line operator-facing summary of what the toggle
 *                       controls. Keep it factual — "this turns off X" — not
 *                       marketing copy.
 *  - `isEnabled()`    — current effective state. Reads through the matching
 *                       Options class so it reflects whatever's persisted in
 *                       `wp_options` overlaid on `config/plugin.php`.
 *  - `setEnabled()`   — flips just the master `enabled` flag without touching
 *                       any of the feature's granular sub-settings. That way
 *                       admins can freely toggle on/off from the dashboard
 *                       without ever losing their detailed configuration.
 *  - `isPro`          — whether the toggle is gated behind a Pro license.
 *                       Free users see the toggle disabled with an upgrade
 *                       hint; clicking still works once they activate a key.
 *
 * Centralizing this list here means:
 *
 *  - The dashboard view stays dumb — it just iterates whatever the registry
 *    gives it.
 *  - The admin-post handler reuses the same key→feature mapping it just
 *    rendered, so there's no risk of a form field name drifting away from
 *    its handler.
 *  - Adding a sixth feature (e.g. a future "Backups" module) is a single
 *    `add()` call here and one new tile in the view — no Plugin.php or DI
 *    surgery needed.
 *
 * Pro gating is a soft check by design: we still allow the option to be
 * written, but the matching module's hook bootstrap (e.g. WooCommerceModule)
 * is what actually decides whether to act on it. That keeps the dashboard
 * logic about "what the admin asked for" and pushes "what we're licensed to
 * do" down to where it belongs.
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
        private readonly LicenseManager $license,
    ) {
        // Closures (not arrow fns) for the setters because arrow fns implicitly
        // return their expression — incompatible with a `void` return type and
        // a hard PHP TypeError once the runtime would coerce the void method's
        // result. Keeping the body explicit also makes "what does flipping this
        // toggle actually call" greppable.
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
                description: 'Behavioural abuse prevention for checkout, registration, cart and REST endpoints. Requires a Pro license.',
                isEnabled: static fn (): bool => $wcProtection->isEnabled(),
                setEnabled: static function (bool $on) use ($wcProtection): void {
                    $wcProtection->setEnabled($on);
                },
                isPro: true,
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

    public function isPro(): bool
    {
        return $this->license->isPro();
    }

    /**
     * Applies an `[key => on|off]` map. Unknown keys are silently ignored —
     * we never want a stale form field to throw on save. Pro-gated features
     * still accept the flip when the install isn't licensed so the operator
     * can pre-configure intent; the corresponding module's bootstrap
     * decides whether to actually run.
     *
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
                continue; // No-op: avoid touching wp_options when nothing changed.
            }
            $feature->setEnabled($next);
            $changed[] = $feature->key;
        }

        return $changed;
    }
}
