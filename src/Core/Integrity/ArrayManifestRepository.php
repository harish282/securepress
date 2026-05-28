<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Integrity;

/**
 * In-memory baseline store for unit tests.
 *
 * Keyed by `$scope` so a single instance can hold baselines for `core`, `plugins`, … in
 * isolation. Mirrors the WPDB implementation's contract exactly — replace one with the
 * other in DI and the scanner behaviour is identical.
 */
final class ArrayManifestRepository implements ManifestRepositoryInterface
{
    /** @var array<string, Manifest> */
    private array $manifests = [];

    public function save(Manifest $manifest): void
    {
        $this->manifests[$manifest->scope] = $manifest;
    }

    public function load(string $scope): ?Manifest
    {
        return $this->manifests[$scope] ?? null;
    }

    public function delete(string $scope): void
    {
        unset($this->manifests[$scope]);
    }

    public function scopes(): array
    {
        return array_keys($this->manifests);
    }
}
