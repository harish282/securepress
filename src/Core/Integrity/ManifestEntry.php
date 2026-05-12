<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity;

/**
 * One row in a {@see Manifest} — represents a single tracked file.
 *
 * `path` is always relative to the manifest's root (`wp-content/plugins/akismet/akismet.php`
 * rather than the absolute path) so a manifest built on staging stays comparable when the
 * same plugin is installed on production under a different absolute prefix.
 *
 * `hash` is SHA-256. We pin the algorithm in the value object (not as a constructor arg)
 * so manifest comparisons cannot accidentally mix hash algorithms across scans.
 *
 * `size` and `mtime` are not strictly required for the diff (the hash alone is sufficient
 * to detect content changes) but they make the admin UI much more useful — operators can
 * tell at a glance which finding represents "the file grew 4KB" vs "two bytes flipped".
 */
final class ManifestEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $hash,
        public readonly int $size,
        public readonly int $mtime,
    ) {
    }

    /**
     * @return array{path:string,hash:string,size:int,mtime:int}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'hash' => $this->hash,
            'size' => $this->size,
            'mtime' => $this->mtime,
        ];
    }
}
