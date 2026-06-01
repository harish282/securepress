<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Licensing;

/**
 * Strategy interface for license-key validation.
 *
 * Multiple implementations ship with the plugin:
 *
 *  - {@see LocalLicenseValidator} — fully-offline HMAC-signed keys, the default. Best
 *    for distributed self-hosted installs that can't always reach a remote server.
 *  - {@see StaticLicenseValidator}  — an allowlist of pre-known keys; used by tests
 *    and for very small private fleets.
 *
 * A future {@see RemoteLicenseValidator} would call back to a vendor server and cache
 * the response. Keeping validation behind an interface means we can swap it without
 * touching the {@see LicenseManager} or any of the feature modules.
 *
 * Implementations MUST be side-effect free: they validate the key, they don't persist
 * or revoke. The {@see LicenseManager} owns persistence.
 */
interface LicenseValidatorInterface
{
    public function validate(string $key): LicenseStatus;
}
