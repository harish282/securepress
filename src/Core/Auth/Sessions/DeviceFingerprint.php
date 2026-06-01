<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Sessions;

/**
 * Stable identifier for "this is the same device/browser combination as before".
 *
 * Computed by {@see SessionFingerprinter} from the request's User-Agent and the major-octet
 * portion of the IP. The hash is intentionally coarse:
 *  - **UA**: full string is hashed (browser updates change it, but day-to-day reuse is fine).
 *  - **IP**: only the /24 (IPv4) or /48 (IPv6) prefix participates so users on dynamic ISPs
 *    don't trip "new device" detection every time their NAT translation rotates.
 *
 * False negatives (treating two different devices as same) are tolerable here because the
 * fingerprint is one signal among several — {@see Suspicion\NewDeviceRule} uses it to *raise*
 * suspicion, not to authorize.
 */
final class DeviceFingerprint
{
    public function __construct(public readonly string $hash, public readonly string $ipPrefix, public readonly string $userAgentDigest)
    {
    }

    public function equals(DeviceFingerprint $other): bool
    {
        return hash_equals($this->hash, $other->hash);
    }
}
