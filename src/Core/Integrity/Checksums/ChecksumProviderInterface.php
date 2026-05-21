<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Checksums;

/**
 * Provides "expected" file checksums for a given software bundle.
 *
 * The interface is decoupled from "WordPress core" specifically so the same abstraction
 * can later back **plugin** checksums from `api.wordpress.org/plugins/checksums/1.0/`
 * — the request shape is the same: identifier + version → { relative-path => md5 }.
 *
 * Implementations:
 *  - {@see WpOrgChecksumProvider} — queries the official WordPress.org REST API and
 *    caches responses in a transient.
 *  - {@see ArrayChecksumProvider}  — test stub.
 *
 * The hash algorithm intentionally stays as a free-form string ("md5", "sha256", …) so
 * the abstraction outlives WP.org's eventual algorithm migration. Today every entry is
 * MD5; the {@see CoreFilesScanner} compares using whatever the provider reports.
 */
interface ChecksumProviderInterface
{
    /**
     * @return array<string, string> Map of relative file path → expected hex digest.
     */
    public function checksums(string $identifier, string $version, string $locale = 'en_US'): array;

    public function algorithm(): string;
}
