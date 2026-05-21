<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity\Scanners;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PressSentinel\Core\Integrity\Finding;
use PressSentinel\Core\Integrity\FindingSeverity;
use PressSentinel\Core\Integrity\FindingType;
use PressSentinel\Core\Integrity\Heuristics\HeuristicInterface;
use PressSentinel\Core\Integrity\Heuristics\HeuristicMatch;
use SplFileInfo;

/**
 * Heuristic scanner for PHP malware patterns.
 *
 * Given a directory and a collection of {@see HeuristicInterface}s, the scanner walks
 * every PHP file under the root and asks each heuristic for matches. Heuristic matches
 * become {@see Finding} rows with the heuristic's reported severity.
 *
 * Beyond heuristics, the scanner emits two file-shape findings on its own:
 *  - **PHP_IN_UPLOADS** — any `.php` file under `wp-content/uploads/` (uploads should
 *    never contain executable PHP; legitimate uploads are media files);
 *  - **DOUBLE_EXTENSION** — files like `image.php.jpg` or `payload.jpg.php`, which are
 *    a classic webshell-disguise trick aimed at servers that route by the trailing
 *    extension.
 *
 * The scanner respects {@see $maxFileSize} (skips files larger than the limit — typical
 * malware is small, hundreds of MB log files are not what we're hunting). Skip globs
 * mirror the manifest builder so plugin development artefacts (`node_modules`, `vendor/bin`,
 * cache directories) don't bury the real signal in noise.
 */
final class SuspiciousPhpScanner implements ScannerInterface
{
    /** @var list<HeuristicInterface> */
    private readonly array $heuristics;

    /** @var list<string> */
    private readonly array $skipDirectories;

    /**
     * @param list<HeuristicInterface> $heuristics
     * @param list<string>|null        $skipDirectories Substrings to skip; null uses defaults.
     */
    public function __construct(
        private readonly string $scope,
        private readonly string $rootPath,
        array $heuristics,
        ?array $skipDirectories = null,
        private readonly int $maxFileSize = 2 * 1024 * 1024,
        private readonly bool $isUploadsScope = false,
    ) {
        $this->heuristics = array_values($heuristics);
        $this->skipDirectories = $this->normalizeSkipDirectories($skipDirectories);
    }

    public function name(): string
    {
        return 'suspicious_php:' . $this->scope;
    }

    public function scan(): array
    {
        $root = $this->normalizeRoot($this->rootPath);
        if ($root === '' || !is_dir($root)) {
            return [];
        }

        $findings = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            if (!str_starts_with($absolute, $root)) {
                continue;
            }
            $relative = ltrim(substr($absolute, strlen($root)), '/');

            if ($this->shouldSkipPath($relative)) {
                continue;
            }

            $shapeFindings = $this->fileShapeFindings($relative, $file);
            foreach ($shapeFindings as $f) {
                $findings[] = $f;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $size = $file->getSize();
            if ($size === false || $size > $this->maxFileSize) {
                continue;
            }

            $content = @file_get_contents($absolute);
            if (!is_string($content) || $content === '') {
                continue;
            }

            foreach ($this->heuristics as $heuristic) {
                foreach ($heuristic->scan($content) as $match) {
                    $findings[] = $this->matchToFinding($relative, $match);
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function fileShapeFindings(string $relative, SplFileInfo $file): array
    {
        $findings = [];
        $extension = strtolower($file->getExtension());

        if ($this->isUploadsScope && $extension === 'php') {
            $findings[] = Finding::make(
                scope: $this->scope,
                type: FindingType::PHP_IN_UPLOADS,
                severity: FindingSeverity::CRITICAL,
                path: $relative,
                message: 'A PHP file was found inside the uploads directory.',
                details: [
                    'size' => (int) $file->getSize(),
                    'mtime' => (int) $file->getMTime(),
                ],
            );
        }

        if ($this->hasDoubleExtension($file->getFilename())) {
            $findings[] = Finding::make(
                scope: $this->scope,
                type: FindingType::DOUBLE_EXTENSION,
                severity: FindingSeverity::HIGH,
                path: $relative,
                message: 'File uses a double-extension pattern (e.g., `payload.php.jpg`).',
                details: [
                    'filename' => $file->getFilename(),
                    'extension' => $extension,
                ],
            );
        }

        return $findings;
    }

    private function hasDoubleExtension(string $filename): bool
    {
        $parts = explode('.', $filename);
        if (count($parts) < 3) {
            return false;
        }
        $last = strtolower(end($parts));
        $secondLast = strtolower(prev($parts));
        // The dangerous shapes are: anything.php.xxx, anything.phtml.xxx,
        // and anything.xxx.php (where xxx is normally an image extension).
        $executable = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8'];
        $imagey = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];

        if (in_array($secondLast, $executable, true) && in_array($last, $imagey, true)) {
            return true;
        }
        if (in_array($secondLast, $imagey, true) && in_array($last, $executable, true)) {
            return true;
        }

        return false;
    }

    private function matchToFinding(string $relative, HeuristicMatch $match): Finding
    {
        $type = $match->heuristic === 'webshell_signature'
            ? FindingType::WEBSHELL_SIGNATURE
            : FindingType::SUSPICIOUS_PHP;

        return Finding::make(
            scope: $this->scope,
            type: $type,
            severity: $match->severity,
            path: $relative,
            message: $match->message,
            details: [
                'heuristic' => $match->heuristic,
                'pattern' => $match->pattern,
                'line' => $match->line,
                'snippet' => $match->snippet,
            ],
        );
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

    /**
     * @param list<string>|null $skipDirectories
     * @return list<string>
     */
    private function normalizeSkipDirectories(?array $skipDirectories): array
    {
        $defaults = [
            '/node_modules/',
            '/.git/',
            '/.svn/',
            '/vendor/bin/',
            '/cache/',
            '/wp-content/cache/',
            '/wp-content/upgrade/',
        ];
        $source = $skipDirectories === null ? $defaults : $skipDirectories;
        $normalized = [];
        foreach ($source as $skip) {
            if (!is_string($skip) || $skip === '') {
                continue;
            }
            $normalized[] = '/' . trim(str_replace('\\', '/', $skip), '/') . '/';
        }

        return $normalized;
    }

    private function shouldSkipPath(string $relative): bool
    {
        $candidate = '/' . str_replace('\\', '/', $relative) . '/';
        foreach ($this->skipDirectories as $skip) {
            if (str_contains($candidate, $skip)) {
                return true;
            }
        }

        return false;
    }
}
