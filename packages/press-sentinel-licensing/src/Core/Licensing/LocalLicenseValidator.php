<?php

declare(strict_types=1);

namespace PressSentinel\Core\Licensing;

/**
 * Validates offline-issued HMAC license keys without phoning home.
 *
 * Key format:
 *
 *     SP-<TIER>-<ISSUED_AT>-<EXPIRES_AT>-<HMAC>
 *
 *  - `TIER`        — uppercase identifier ("PRO", "AGENCY"). Anything but `FREE`
 *    upgrades the install above the free tier.
 *  - `ISSUED_AT`   — UNIX timestamp of issuance (10 chars).
 *  - `EXPIRES_AT`  — UNIX timestamp of expiry (10 chars). `0` means perpetual.
 *  - `HMAC`        — first 16 hex chars of `hmac-sha256(secret, "<TIER>-<ISSUED_AT>-<EXPIRES_AT>")`.
 *
 * Example:
 *     SP-PRO-1714780800-1746316800-3f6d1c2e9f6d1c2e
 *
 * Why offline keys:
 *  - work in shared-hosting / air-gapped environments where outbound HTTP is unreliable;
 *  - validation cost is O(few microseconds) — no remote round-trip on every boot;
 *  - the vendor controls issuance via a CLI that signs with the same `$secret`.
 *
 * The shared secret is injected via {@see Config} (typically
 * `define('PRESS_SENTINEL_LICENSE_SECRET', …)` in `wp-config.php`, or `licensing.secret` in
 * `config/plugin.php`
 * env). The plugin only verifies signatures and never has to know the vendor’s
 * signing material beyond that shared secret.
 *
 * Security considerations:
 *  - HMAC is constant-time compared via `hash_equals` to prevent timing leaks.
 *  - Truncating the HMAC to 16 hex chars (64 bits) is intentional — the keys remain
 *    short enough to type, and the brute-force search space (2^64) is far beyond what
 *    matters for license control. Anyone who can do 2^64 work to fake a Pro license
 *    can build their own fork.
 */
final class LocalLicenseValidator implements LicenseValidatorInterface
{
    private const PREFIX = 'SP';
    private const HMAC_LEN = 16;

    public function __construct(private readonly string $secret)
    {
    }

    public function validate(string $key): LicenseStatus
    {
        $key = trim($key);
        if ($key === '') {
            return LicenseStatus::none();
        }

        $masked = $this->mask($key);

        $parts = explode('-', $key);
        if (count($parts) !== 5 || $parts[0] !== self::PREFIX) {
            return LicenseStatus::invalid('Malformed license key.', $masked);
        }

        [, $tier, $issuedAt, $expiresAt, $hmac] = $parts;

        if (!ctype_alpha($tier) || !ctype_digit($issuedAt) || !ctype_digit($expiresAt)) {
            return LicenseStatus::invalid('Malformed license key.', $masked);
        }
        if (strlen($hmac) !== self::HMAC_LEN || !ctype_xdigit($hmac)) {
            return LicenseStatus::invalid('Malformed license key.', $masked);
        }

        $expected = $this->sign($tier, (int) $issuedAt, (int) $expiresAt);
        if (!hash_equals($expected, $hmac)) {
            return LicenseStatus::invalid('Signature mismatch.', $masked);
        }

        $expiry = (int) $expiresAt;
        if ($expiry !== 0 && $expiry < time()) {
            return LicenseStatus::expired(strtolower($tier), $expiry, $masked);
        }

        return LicenseStatus::active(strtolower($tier), $expiry !== 0 ? $expiry : null, $masked);
    }

    /**
     * Helper used by the vendor's issuance CLI / tests to produce a valid key.
     * Not used at runtime by the plugin itself.
     */
    public function issue(string $tier, int $issuedAt, int $expiresAt): string
    {
        $tier = strtoupper($tier);
        $hmac = $this->sign($tier, $issuedAt, $expiresAt);

        return sprintf('%s-%s-%010d-%010d-%s', self::PREFIX, $tier, $issuedAt, $expiresAt, $hmac);
    }

    private function sign(string $tier, int $issuedAt, int $expiresAt): string
    {
        $payload = strtoupper($tier) . '-' . $issuedAt . '-' . $expiresAt;

        return substr(hash_hmac('sha256', $payload, $this->secret), 0, self::HMAC_LEN);
    }

    private function mask(string $key): string
    {
        if (strlen($key) <= 10) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 6) . '…' . substr($key, -4);
    }
}
