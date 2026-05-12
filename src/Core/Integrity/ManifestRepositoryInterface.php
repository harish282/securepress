<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity;

/**
 * Persists scoped baselines (manifests) so each scan compares the live filesystem
 * against the previously-saved snapshot.
 *
 * Implementations:
 *  - {@see ArrayManifestRepository} — in-memory, used by tests.
 *  - {@see WpdbManifestRepository}  — production, backed by `wp_securepress_integrity_baselines`.
 *
 * The contract is intentionally narrow: save / load / delete by scope. The scope string
 * is the only key — there is never more than one baseline per scope. Re-running the
 * scanner overwrites the previous baseline, and the resulting diff is what populates
 * the findings table.
 */
interface ManifestRepositoryInterface
{
    public function save(Manifest $manifest): void;

    public function load(string $scope): ?Manifest;

    public function delete(string $scope): void;

    /**
     * @return list<string>
     */
    public function scopes(): array;
}
