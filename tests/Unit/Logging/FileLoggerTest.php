<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Logging\FileLogger;

/**
 * @see \NiyiGuard\Core\Logging\FileLogger
 */
final class FileLoggerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/niyiguard-filelogger-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function test_log_directory_and_file_are_auto_created(): void
    {
        $path = $this->root . '/logs/niyiguard.log';
        $logger = new FileLogger($path);

        $logger->info('first line', ['ctx' => 1]);

        self::assertDirectoryExists(\dirname($path));
        self::assertFileExists($path);
        self::assertNull($logger->lastError());
    }

    public function test_log_lines_are_appended_as_json(): void
    {
        $path = $this->root . '/logs/niyiguard.log';
        $logger = new FileLogger($path);

        $logger->info('a', ['x' => 1]);
        $logger->warning('b', []);

        $contents = file_get_contents($path);
        self::assertIsString($contents);
        $lines = array_values(array_filter(explode(PHP_EOL, $contents)));
        self::assertCount(2, $lines);

        $first = json_decode($lines[0], true);
        self::assertIsArray($first);
        self::assertSame('info', $first['level']);
        self::assertSame('a', $first['message']);
        self::assertSame(['x' => 1], $first['context']);
    }

    public function test_protection_files_dropped_when_directory_is_created(): void
    {
        $path = $this->root . '/logs/niyiguard.log';
        $logger = new FileLogger($path);

        $logger->info('seed');

        self::assertFileExists(\dirname($path) . '/.htaccess');
        self::assertFileExists(\dirname($path) . '/index.html');
        self::assertStringContainsString('Require all denied', (string) file_get_contents(\dirname($path) . '/.htaccess'));
    }

    public function test_existing_protection_files_are_not_overwritten(): void
    {
        $dir = $this->root . '/logs';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/.htaccess', '# custom rule');
        file_put_contents($dir . '/index.html', 'KEEP');

        $logger = new FileLogger($dir . '/niyiguard.log');
        $logger->info('seed');

        self::assertSame('# custom rule', file_get_contents($dir . '/.htaccess'));
        self::assertSame('KEEP', file_get_contents($dir . '/index.html'));
    }

    public function test_unwritable_directory_records_last_error_without_emitting_warning(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Filesystem permission checks cannot be exercised as root.');
        }

        // Create the parent in two steps so we don't accidentally apply 0500
        // to the test root (PHP's recursive mkdir applies the mode to *every*
        // intermediate directory).
        mkdir($this->root, 0755, true);
        $parent = $this->root . '/locked';
        mkdir($parent, 0500);
        $path = $parent . '/logs/niyiguard.log';

        $logger = new FileLogger($path);
        $logger->info('attempt');

        self::assertNotNull($logger->lastError());
        self::assertStringContainsString('log directory', (string) $logger->lastError());
        self::assertFileDoesNotExist($path);
    }

    public function test_unwritable_file_records_last_error(): void
    {
        if (\function_exists('posix_geteuid') && \posix_geteuid() === 0) {
            self::markTestSkipped('Filesystem permission checks cannot be exercised as root.');
        }

        $dir = $this->root . '/logs';
        mkdir($dir, 0755, true);
        $path = $dir . '/readonly.log';
        file_put_contents($path, ''); // exists
        chmod($path, 0400); // r--

        $logger = new FileLogger($path);
        $logger->info('attempt');

        self::assertNotNull($logger->lastError());
        self::assertStringContainsString('not writable', (string) $logger->lastError());

        // Restore permissions so the tearDown cleanup works.
        chmod($path, 0644);
    }

    public function test_reset_re_probes_writability(): void
    {
        $path = $this->root . '/logs/niyiguard.log';
        $logger = new FileLogger($path);

        $logger->info('first');
        self::assertNull($logger->lastError());

        // Simulate the directory going away mid-request — pre-reset, the
        // logger still thinks it's writable because of its cache. After
        // reset(), it re-probes and recovers (or records the new error).
        $this->removeTree(\dirname($path));
        $logger->reset();
        $logger->info('second');

        // The directory got auto-created again, so logging recovers cleanly.
        self::assertFileExists($path);
        self::assertNull($logger->lastError());
    }

    public function test_repeated_logs_only_probe_filesystem_once(): void
    {
        $path = $this->root . '/logs/niyiguard.log';
        $logger = new FileLogger($path);

        for ($i = 0; $i < 5; $i++) {
            $logger->info('line ' . $i);
        }

        // The protection files exist (proves dropProtectionFiles ran), but
        // we're also implicitly asserting that 5 sequential calls didn't blow
        // up — which they would have on the original implementation if
        // wp_mkdir_p had failed silently.
        self::assertSame(5, count(array_filter(explode(PHP_EOL, (string) file_get_contents($path)))));
    }

    public function test_log_file_path_is_exposed(): void
    {
        $path = $this->root . '/logs/niyiguard.log';
        $logger = new FileLogger($path);

        self::assertSame($path, $logger->logFilePath());
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @chmod($path, 0644);
            @unlink($path);
            return;
        }
        @chmod($path, 0755);
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $item);
        }
        @rmdir($path);
    }
}
