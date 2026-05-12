<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity\Checksums;

use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Core\Support\WpHelper;

/**
 * Real WP.org checksum provider — queries
 * `https://api.wordpress.org/core/checksums/1.0/?version=...&locale=...`.
 *
 * Why route via {@see WpHelper}: gives the test suite a single seam to stub the HTTP
 * client (`wp_remote_get`) and the transient cache without monkey-patching globals.
 *
 * Responses are cached in a WordPress transient for {@see CACHE_TTL_SECONDS} (12 hours
 * by default) keyed by `(identifier, version, locale)`. The WP.org API rarely changes
 * within a release, so the cache makes the scanner free at steady state and bounds the
 * upstream traffic to one request per core release per locale per site.
 *
 * Failures (network error, malformed JSON, non-200) are returned as an empty map and
 * logged at warning level. The scanner treats "no checksums" as "skip silently",
 * matching the test stub's contract.
 */
final class WpOrgChecksumProvider implements ChecksumProviderInterface
{
    private const ENDPOINT = 'https://api.wordpress.org/core/checksums/1.0/';

    private const CACHE_PREFIX = 'sp_int_chk_';

    private const CACHE_TTL_SECONDS = 12 * 60 * 60;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly string $algorithm = 'md5',
    ) {
    }

    public function checksums(string $identifier, string $version, string $locale = 'en_US'): array
    {
        if ($identifier === '' || $version === '') {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . substr(hash('sha256', $identifier . '|' . $version . '|' . $locale), 0, 32);
        $cached = WpHelper::getTransient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $url = self::ENDPOINT . '?' . http_build_query(['version' => $version, 'locale' => $locale]);
        $response = WpHelper::remoteGet($url);
        if ($response === null) {
            $this->logger->warning('Integrity: WP.org checksums request failed.', [
                'url' => $url,
            ]);
            WpHelper::setTransient($cacheKey, [], 5 * 60);
            return [];
        }

        $decoded = json_decode($response, true);
        $checksums = is_array($decoded) && isset($decoded['checksums']) && is_array($decoded['checksums'])
            ? $decoded['checksums']
            : [];

        $clean = [];
        foreach ($checksums as $path => $hash) {
            if (is_string($path) && is_string($hash) && $path !== '' && $hash !== '') {
                $clean[$path] = $hash;
            }
        }

        WpHelper::setTransient($cacheKey, $clean, self::CACHE_TTL_SECONDS);

        return $clean;
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }
}
