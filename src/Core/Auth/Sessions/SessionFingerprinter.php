<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Sessions;

/**
 * Computes a {@see DeviceFingerprint} from raw request inputs.
 *
 * Pure / stateless / no I/O — kept as its own class so suspicion rules and the session
 * service share a single canonicalisation path. If we ever want to refine the fingerprint
 * (e.g., include `Sec-CH-UA` headers or browser locale), this is the only place to change.
 */
final class SessionFingerprinter
{
    public function fingerprint(?string $ip, ?string $userAgent): DeviceFingerprint
    {
        $prefix = $this->normaliseIpPrefix($ip ?? '');
        $uaDigest = substr(hash('sha256', $userAgent ?? ''), 0, 16);

        $combined = hash('sha256', $prefix . '|' . $uaDigest);

        return new DeviceFingerprint($combined, $prefix, $uaDigest);
    }

    /**
     * IPv4: keep first three octets (`192.0.2.x`). IPv6: keep first three groups (~/48).
     *
     * Anything that doesn't parse as an IP is reduced to the empty string so two
     * unparseable inputs collapse onto each other instead of producing distinct
     * "fingerprints" off random data.
     */
    private function normaliseIpPrefix(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return implode('.', array_slice($parts, 0, 3)) . '.0/24';
        }

        // IPv6: keep first three hextets, treat remainder as zeros, mask to /48.
        $parts = explode(':', $ip);
        $first = array_slice($parts, 0, 3);
        $first = array_pad($first, 3, '0');

        return implode(':', $first) . '::/48';
    }
}
