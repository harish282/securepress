<?php

declare(strict_types=1);

namespace SecurePress\Core\Licensing;

/**
 * Validator backed by a static allowlist of known-good keys.
 *
 * Used by:
 *  - the test suite, where we want deterministic, secret-free Pro/Free toggling;
 *  - private deployments that don't want HMAC at all (single fixed Pro key per install).
 *
 * Tier and optional expiry are configured per key. Mirrors the {@see LicenseStatus}
 * vocabulary so the calling code can swap validators without conditionals.
 */
final class StaticLicenseValidator implements LicenseValidatorInterface
{
    /**
     * @param array<string, array{tier:string, expires_at?:int|null}> $allowed
     */
    public function __construct(private readonly array $allowed)
    {
    }

    public function validate(string $key): LicenseStatus
    {
        $key = trim($key);
        if ($key === '') {
            return LicenseStatus::none();
        }

        if (!isset($this->allowed[$key])) {
            return LicenseStatus::invalid('Key is not on the allowlist.', $this->mask($key));
        }

        $tier = $this->allowed[$key]['tier'] ?? 'pro';
        $expiresAt = $this->allowed[$key]['expires_at'] ?? null;

        if ($expiresAt !== null && $expiresAt < time()) {
            return LicenseStatus::expired($tier, $expiresAt, $this->mask($key));
        }

        return LicenseStatus::active($tier, $expiresAt, $this->mask($key));
    }

    private function mask(string $key): string
    {
        if (strlen($key) <= 10) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 6) . '…' . substr($key, -4);
    }
}
