<?php

declare(strict_types=1);

namespace SecurePress\Core\Integrity;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Walks a directory tree and builds a {@see Manifest}.
 *
 * Configuration choices that materially affect runtime / signal quality:
 *
 *  - **`fileExtensions`**: limit hashing to file types worth monitoring. Defaults to PHP
 *    only — binary assets in `wp-content/uploads` change constantly and are not the
 *    integrity signal we care about. The admin can broaden this for high-paranoia sites.
 *  - **`skipDirectories`**: a list of substrings (NOT regexes) matched against the
 *    relative path. Defaults skip `node_modules`, `vendor/bin`, `.git`, and obvious
 *    build/cache directories.
 *  - **`maxFileSize`**: any file larger than this is skipped, both for hashing speed and
 *    because integrity scanners are not malware scanners — gigabyte log files trigger no
 *    actionable signal. Default 2 MB, matching the suspicious-PHP scanner.
 *
 * The builder uses `RecursiveDirectoryIterator` directly rather than `glob('**')` because
 * the iterator is the only API that handles symlinks, hidden files, and unreadable
 * directories without surprises (it silently skips entries it can't access).
 */
final class ManifestBuilder
{
    /** @var list<string> */
    private const DEFAULT_EXTENSIONS = ['php'];

    /** @var list<string> */
    private const DEFAULT_SKIP_DIRECTORIES = [
        '/node_modules/',
        '/.git/',
        '/.svn/',
        '/vendor/bin/',
        '/storage/cache/',
        '/cache/',
        '/wp-content/cache/',
        '/wp-content/upgrade/',
    ];

    /** @var list<string> */
    private array $fileExtensions;

    /** @var list<string> */
    private array $skipDirectories;

    private int $maxFileSize;

    /**
     * @param list<string>|null $fileExtensions Lower-case extensions without the dot.
     * @param list<string>|null $skipDirectories Substrings to exclude from the walk.
     */
    public function __construct(
        ?array $fileExtensions = null,
        ?array $skipDirectories = null,
        int $maxFileSize = 2 * 1024 * 1024,
    ) {
        $this->fileExtensions = $this->normalizeExtensions($fileExtensions);
        $this->skipDirectories = $this->normalizeSkipDirectories($skipDirectories);
        $this->maxFileSize = max(1024, $maxFileSize);
    }

    public function build(string $scope, string $root): Manifest
    {
        $root = $this->normalizeRoot($root);
        if ($root === '' || !is_dir($root)) {
            return Manifest::empty($scope, $root);
        }

        $manifest = Manifest::empty($scope, $root);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $entry = $this->makeEntry($root, $file);
            if ($entry !== null) {
                $manifest = $manifest->withEntry($entry);
            }
        }

        return $manifest;
    }

    private function makeEntry(string $root, SplFileInfo $file): ?ManifestEntry
    {
        $absolute = $file->getPathname();
        $relative = $this->relative($root, $absolute);
        if ($relative === null) {
            return null;
        }
        if ($this->shouldSkipPath($relative)) {
            return null;
        }
        if (!$this->extensionMatches($file)) {
            return null;
        }
        $size = $file->getSize();
        if ($size === false || $size > $this->maxFileSize) {
            return null;
        }
        $hash = @hash_file('sha256', $absolute);
        if ($hash === false) {
            return null;
        }

        return new ManifestEntry(
            path: $relative,
            hash: $hash,
            size: (int) $size,
            mtime: (int) $file->getMTime(),
        );
    }

    private function extensionMatches(SplFileInfo $file): bool
    {
        if ($this->fileExtensions === []) {
            return true;
        }
        $extension = strtolower($file->getExtension());
        if ($extension === '') {
            return false;
        }

        return in_array($extension, $this->fileExtensions, true);
    }

    private function shouldSkipPath(string $relative): bool
    {
        $candidate = '/' . str_replace('\\', '/', $relative) . '/';
        foreach ($this->skipDirectories as $skip) {
            if ($skip !== '' && str_contains($candidate, $skip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>|null $extensions
     * @return list<string>
     */
    private function normalizeExtensions(?array $extensions): array
    {
        $source = $extensions === null ? self::DEFAULT_EXTENSIONS : $extensions;
        $normalized = [];
        foreach ($source as $extension) {
            if (!is_string($extension)) {
                continue;
            }
            $extension = strtolower(ltrim(trim($extension), '.'));
            if ($extension !== '' && !in_array($extension, $normalized, true)) {
                $normalized[] = $extension;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string>|null $skipDirectories
     * @return list<string>
     */
    private function normalizeSkipDirectories(?array $skipDirectories): array
    {
        $source = $skipDirectories === null ? self::DEFAULT_SKIP_DIRECTORIES : $skipDirectories;
        $normalized = [];
        foreach ($source as $skip) {
            if (!is_string($skip) || $skip === '') {
                continue;
            }
            $normalized[] = '/' . trim(str_replace('\\', '/', $skip), '/') . '/';
        }

        return $normalized;
    }

    private function normalizeRoot(string $root): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($root === '') {
            return '';
        }
        $real = realpath($root);

        return $real === false ? $root : str_replace('\\', '/', $real);
    }

    private function relative(string $root, string $absolute): ?string
    {
        $absolute = str_replace('\\', '/', $absolute);
        if (!str_starts_with($absolute, $root)) {
            return null;
        }

        return ltrim(substr($absolute, strlen($root)), '/');
    }
}
