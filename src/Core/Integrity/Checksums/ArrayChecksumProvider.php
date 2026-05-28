<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity\Checksums;

/**
 * Deterministic, in-memory {@see ChecksumProviderInterface} for tests.
 *
 * Callers seed the table via the constructor; unknown identifiers return an empty map,
 * which the {@see \NiyiGuard\Core\Integrity\Scanners\CoreFilesScanner} interprets as
 * "no baseline available — skip silently". That matches production behaviour when
 * WordPress.org is unreachable.
 */
final class ArrayChecksumProvider implements ChecksumProviderInterface
{
    /**
     * @param array<string, array<string, array<string, string>>> $checksums
     *      identifier => version => path => hash
     */
    public function __construct(
        private array $checksums = [],
        private readonly string $algorithm = 'md5',
    ) {
    }

    /**
     * @param array<string, string> $checksums
     */
    public function seed(string $identifier, string $version, array $checksums): void
    {
        $this->checksums[$identifier][$version] = $checksums;
    }

    public function checksums(string $identifier, string $version, string $locale = 'en_US'): array
    {
        unset($locale);

        return $this->checksums[$identifier][$version] ?? [];
    }

    public function algorithm(): string
    {
        return $this->algorithm;
    }
}
