<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Integrity\IntegrityScope;
use PressSentinel\Core\Integrity\ManifestBuilder;

/**
 * @see \PressSentinel\Core\Integrity\ManifestBuilder
 */
final class ManifestBuilderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sp_int_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    public function test_builds_manifest_for_php_files_only_by_default(): void
    {
        file_put_contents($this->root . '/a.php', '<?php echo "ok";');
        file_put_contents($this->root . '/b.txt', 'plain text');
        mkdir($this->root . '/inc', 0777, true);
        file_put_contents($this->root . '/inc/c.php', '<?php echo "c";');

        $builder = new ManifestBuilder();
        $manifest = $builder->build(IntegrityScope::PLUGINS, $this->root);

        $paths = array_keys($manifest->entries());
        sort($paths);

        self::assertSame(['a.php', 'inc/c.php'], $paths);
        self::assertSame(IntegrityScope::PLUGINS, $manifest->scope);
        self::assertNotSame('', $manifest->entries()['a.php']->hash);
    }

    public function test_skips_excluded_directories(): void
    {
        mkdir($this->root . '/node_modules', 0777, true);
        file_put_contents($this->root . '/node_modules/x.php', '<?php');
        file_put_contents($this->root . '/keep.php', '<?php');

        $builder = new ManifestBuilder();
        $manifest = $builder->build('custom', $this->root);

        self::assertArrayHasKey('keep.php', $manifest->entries());
        self::assertArrayNotHasKey('node_modules/x.php', $manifest->entries());
    }

    public function test_skips_files_above_max_size(): void
    {
        file_put_contents($this->root . '/small.php', '<?php');
        file_put_contents($this->root . '/big.php', str_repeat('a', 1500));

        $builder = new ManifestBuilder(null, null, 1000);
        $manifest = $builder->build('custom', $this->root);

        self::assertArrayHasKey('small.php', $manifest->entries());
        self::assertArrayNotHasKey('big.php', $manifest->entries());
    }

    public function test_custom_extensions_take_effect(): void
    {
        file_put_contents($this->root . '/a.php', '<?php');
        file_put_contents($this->root . '/a.txt', 'plain');

        $builder = new ManifestBuilder(['php', 'txt']);
        $manifest = $builder->build('custom', $this->root);

        self::assertArrayHasKey('a.php', $manifest->entries());
        self::assertArrayHasKey('a.txt', $manifest->entries());
    }

    public function test_missing_root_returns_empty_manifest(): void
    {
        $builder = new ManifestBuilder();
        $manifest = $builder->build('custom', '/this/does/not/exist');

        self::assertSame(0, $manifest->count());
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
