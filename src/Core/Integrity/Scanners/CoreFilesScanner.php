<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity\Scanners;

use NiyiGuard\Core\Integrity\Checksums\ChecksumProviderInterface;
use NiyiGuard\Core\Integrity\Finding;
use NiyiGuard\Core\Integrity\FindingSeverity;
use NiyiGuard\Core\Integrity\FindingType;
use NiyiGuard\Core\Integrity\IntegrityScope;

/**
 * Diffs the live WordPress core tree against the official WP.org checksums for the
 * running version.
 *
 * Why this scanner exists alongside the manifest-diff one: a manifest diff only catches
 * changes since the **last scan** — installing a hacked WordPress build on day 0 would
 * pin that hacked state as the baseline. The WP.org checksums are an *authoritative*
 * reference, so this scanner catches "your wp-admin/admin.php doesn't match the upstream
 * release for this version", regardless of when the tamper happened.
 *
 * Files unique to the install (`wp-config.php`, custom `.htaccess`, anything under
 * `wp-content/`) are intentionally NOT flagged when missing from the checksum map —
 * the WP.org manifest excludes them anyway. We only fire findings for files that ARE
 * in the official manifest but whose live hash diverges.
 *
 * Behaviour when the network is down / WP.org returns an empty map: the scanner emits
 * zero findings. This is intentional — we cannot tell `wp-load.php` apart from a tamper
 * without a reference, so silently degrading is the only sane option. The retry happens
 * on the next cron tick via the provider's internal cache TTL.
 */
final class CoreFilesScanner implements ScannerInterface
{
    public function __construct(
        private readonly ChecksumProviderInterface $provider,
        private readonly string $coreRootPath,
        private readonly string $version,
        private readonly string $locale = 'en_US',
        private readonly string $severity = FindingSeverity::CRITICAL,
    ) {
    }

    public function name(): string
    {
        return 'core_checksums';
    }

    public function scan(): array
    {
        if ($this->version === '' || $this->coreRootPath === '' || !is_dir($this->coreRootPath)) {
            return [];
        }

        $expected = $this->provider->checksums('wordpress', $this->version, $this->locale);
        if ($expected === []) {
            return [];
        }

        $algorithm = $this->provider->algorithm();
        $root = rtrim(str_replace('\\', '/', $this->coreRootPath), '/');
        $findings = [];

        foreach ($expected as $relative => $expectedHash) {
            $absolute = $root . '/' . ltrim($relative, '/');
            if (!is_file($absolute) || !is_readable($absolute)) {
                continue;
            }
            $actualHash = @hash_file($algorithm, $absolute);
            if (!is_string($actualHash)) {
                continue;
            }
            if (hash_equals($expectedHash, $actualHash)) {
                continue;
            }
            $findings[] = Finding::make(
                scope: IntegrityScope::CORE,
                type: FindingType::CORE_TAMPERED,
                severity: $this->severity,
                path: $relative,
                message: 'Core file content does not match the official WordPress.org checksum.',
                details: [
                    'wp_version' => $this->version,
                    'algorithm' => $algorithm,
                    'expected_hash' => $expectedHash,
                    'actual_hash' => $actualHash,
                ],
            );
        }

        return $findings;
    }
}
