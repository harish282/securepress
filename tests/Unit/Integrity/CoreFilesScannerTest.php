<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Integrity\Checksums\ArrayChecksumProvider;
use SecurePress\Core\Integrity\FindingType;
use SecurePress\Core\Integrity\IntegrityScope;
use SecurePress\Core\Integrity\Scanners\CoreFilesScanner;

/**
 * @see \SecurePress\Core\Integrity\Scanners\CoreFilesScanner
 */
final class CoreFilesScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sp_core_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/wp-admin', 0777, true);
        mkdir($this->root . '/wp-includes', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function test_emits_nothing_when_checksums_are_empty(): void
    {
        file_put_contents($this->root . '/wp-admin/admin.php', 'x');
        $provider = new ArrayChecksumProvider();

        $scanner = new CoreFilesScanner($provider, $this->root, '6.5.0');

        self::assertSame([], $scanner->scan());
    }

    public function test_emits_nothing_when_files_match_expected_hash(): void
    {
        $content = 'clean content';
        file_put_contents($this->root . '/wp-admin/admin.php', $content);
        $provider = new ArrayChecksumProvider();
        $provider->seed('wordpress', '6.5.0', [
            'wp-admin/admin.php' => md5($content),
        ]);

        $scanner = new CoreFilesScanner($provider, $this->root, '6.5.0');

        self::assertSame([], $scanner->scan());
    }

    public function test_emits_core_tampered_when_hash_differs(): void
    {
        file_put_contents($this->root . '/wp-admin/admin.php', 'tampered content');
        $provider = new ArrayChecksumProvider();
        $provider->seed('wordpress', '6.5.0', [
            'wp-admin/admin.php' => md5('original content'),
            'wp-includes/load.php' => md5('does-not-exist-on-disk'),
        ]);

        $scanner = new CoreFilesScanner($provider, $this->root, '6.5.0');

        $findings = $scanner->scan();

        self::assertCount(1, $findings);
        self::assertSame(FindingType::CORE_TAMPERED, $findings[0]->type);
        self::assertSame(IntegrityScope::CORE, $findings[0]->scope);
        self::assertSame('wp-admin/admin.php', $findings[0]->path);
        self::assertSame('6.5.0', $findings[0]->details['wp_version'] ?? '');
        self::assertSame('md5', $findings[0]->details['algorithm'] ?? '');
    }

    public function test_missing_version_returns_empty(): void
    {
        $scanner = new CoreFilesScanner(new ArrayChecksumProvider(), $this->root, '');

        self::assertSame([], $scanner->scan());
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
