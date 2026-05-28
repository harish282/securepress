<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Integrity\ArrayManifestRepository;
use NiyiGuard\Core\Integrity\FindingType;
use NiyiGuard\Core\Integrity\ManifestBuilder;
use NiyiGuard\Core\Integrity\Scanners\ManifestDiffScanner;

/**
 * @see \NiyiGuard\Core\Integrity\Scanners\ManifestDiffScanner
 */
final class ManifestDiffScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sp_mds_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function test_first_run_captures_baseline_and_emits_nothing(): void
    {
        file_put_contents($this->root . '/a.php', '<?php');
        $repo = new ArrayManifestRepository();
        $scanner = new ManifestDiffScanner('plugins', $this->root, new ManifestBuilder(), $repo);

        $findings = $scanner->scan();

        self::assertSame([], $findings);
        self::assertNotNull($repo->load('plugins'));
        self::assertSame(1, $repo->load('plugins')->count());
    }

    public function test_second_run_emits_added_modified_deleted(): void
    {
        file_put_contents($this->root . '/stay.php', '<?php echo 1;');
        file_put_contents($this->root . '/change.php', '<?php echo 1;');
        file_put_contents($this->root . '/gone.php', '<?php echo 1;');

        $repo = new ArrayManifestRepository();
        $scanner = new ManifestDiffScanner('plugins', $this->root, new ManifestBuilder(), $repo);
        $scanner->scan();

        file_put_contents($this->root . '/change.php', '<?php echo 2;');
        file_put_contents($this->root . '/new.php', '<?php echo 3;');
        unlink($this->root . '/gone.php');

        $findings = $scanner->scan();

        $types = array_map(static fn ($f) => $f->type, $findings);
        sort($types);

        self::assertSame([
            FindingType::FILE_ADDED,
            FindingType::FILE_DELETED,
            FindingType::FILE_MODIFIED,
        ], $types);
    }

    public function test_baseline_is_updated_after_diff_when_persistOnDiff_is_true(): void
    {
        file_put_contents($this->root . '/file.php', '<?php // v1');
        $repo = new ArrayManifestRepository();
        $scanner = new ManifestDiffScanner('plugins', $this->root, new ManifestBuilder(), $repo);
        $scanner->scan();

        file_put_contents($this->root . '/file.php', '<?php // v2');
        $scanner->scan();

        // Third scan with no further changes — must produce no findings, proving the
        // baseline was rolled forward to v2.
        $findings = $scanner->scan();
        self::assertSame([], $findings);
    }

    public function test_reset_baseline_forces_fresh_capture_on_next_scan(): void
    {
        file_put_contents($this->root . '/file.php', '<?php');
        $repo = new ArrayManifestRepository();
        $scanner = new ManifestDiffScanner('plugins', $this->root, new ManifestBuilder(), $repo);
        $scanner->scan();
        $scanner->resetBaseline();

        self::assertNull($repo->load('plugins'));

        $findings = $scanner->scan();
        self::assertSame([], $findings);
        self::assertNotNull($repo->load('plugins'));
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
