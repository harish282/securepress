<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity;

/**
 * Path-indexed collection of {@see ManifestEntry} objects.
 *
 * The class is immutable: callers compose new instances via {@see withEntry()} rather
 * than mutating existing ones. This keeps the diff algorithm trivial — comparing two
 * manifests is a single deterministic walk of their `entries()` arrays without worrying
 * about concurrent modification.
 *
 * Internally the entries are keyed by their (relative) path so:
 *  - lookups in the diff are O(1);
 *  - duplicate entries are impossible — a re-add for the same path is a replacement.
 */
final class Manifest
{
    /**
     * @param array<string, ManifestEntry> $entries
     */
    public function __construct(
        public readonly string $scope,
        public readonly string $root,
        private readonly array $entries,
        public readonly int $capturedAt,
    ) {
    }

    public static function empty(string $scope, string $root): self
    {
        return new self($scope, $root, [], time());
    }

    public function withEntry(ManifestEntry $entry): self
    {
        $entries = $this->entries;
        $entries[$entry->path] = $entry;

        return new self($this->scope, $this->root, $entries, $this->capturedAt);
    }

    /**
     * @return array<string, ManifestEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function get(string $path): ?ManifestEntry
    {
        return $this->entries[$path] ?? null;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
