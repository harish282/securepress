<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

/**
 * Pure diff between two manifests.
 *
 * Output is three parallel arrays of {@see ManifestEntry}:
 *  - {@see $added}    — paths present in the new manifest but not the old.
 *  - {@see $modified} — paths present in both but whose hash changed.
 *  - {@see $deleted}  — paths present in the old manifest but not the new.
 *
 * The diff is intentionally hash-only — size / mtime differences without a hash change
 * are NOT reported. Two reasons: (1) `mtime` changes on every `touch`, including legit
 * cache busts, and would generate noise; (2) the hash is the actual integrity signal —
 * if the content is identical, the file is effectively the same as far as security
 * monitoring is concerned.
 */
final class ManifestDiff
{
    /**
     * @param list<ManifestEntry>                                                                  $added
     * @param list<array{path:string,old:ManifestEntry,new:ManifestEntry}>                        $modified
     * @param list<ManifestEntry>                                                                  $deleted
     */
    public function __construct(
        public readonly array $added,
        public readonly array $modified,
        public readonly array $deleted,
    ) {
    }

    public static function between(Manifest $old, Manifest $new): self
    {
        $oldEntries = $old->entries();
        $newEntries = $new->entries();

        $added = [];
        $modified = [];
        $deleted = [];

        foreach ($newEntries as $path => $entry) {
            if (!isset($oldEntries[$path])) {
                $added[] = $entry;
                continue;
            }
            if ($oldEntries[$path]->hash !== $entry->hash) {
                $modified[] = [
                    'path' => $path,
                    'old' => $oldEntries[$path],
                    'new' => $entry,
                ];
            }
        }

        foreach ($oldEntries as $path => $entry) {
            if (!isset($newEntries[$path])) {
                $deleted[] = $entry;
            }
        }

        return new self($added, $modified, $deleted);
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->modified === [] && $this->deleted === [];
    }

    public function totalChanges(): int
    {
        return count($this->added) + count($this->modified) + count($this->deleted);
    }
}
