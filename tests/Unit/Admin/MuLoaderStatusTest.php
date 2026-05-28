<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Admin\MuLoaderStatus;

/**
 * Covers path resolution + presence detection for the MU loader. Both surfaces
 * that report the loader's state (the dashboard callout and the plugins-screen
 * notice) lean on this class, so a bug here would make the entire setup flow
 * misreport.
 */
final class MuLoaderStatusTest extends TestCase
{
    /** @var list<string> directories created by makeFixtureDir() so we can clean up */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempDirs) as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function test_is_installed_returns_false_when_loader_missing(): void
    {
        $template = $this->makeTemplateFile();
        $muDir = $this->makeFixtureDir(); // empty — no loader inside

        $status = new MuLoaderStatus($template, $muDir, 'sp-loader.php');

        self::assertFalse($status->isInstalled());
        self::assertSame($muDir . '/sp-loader.php', $status->expectedPath());
    }

    public function test_is_installed_returns_true_when_loader_present_at_expected_path(): void
    {
        $template = $this->makeTemplateFile();
        $muDir = $this->makeFixtureDir();
        \file_put_contents($muDir . '/00-niyiguard-loader.php', "<?php // installed\n");

        $status = new MuLoaderStatus($template, $muDir);

        self::assertTrue($status->isInstalled());
    }

    public function test_template_path_falls_through_constructor_override(): void
    {
        $template = $this->makeTemplateFile();
        $status = new MuLoaderStatus($template, $this->makeFixtureDir());

        self::assertSame($template, $status->templatePath());
    }

    public function test_expected_directory_strips_trailing_slash(): void
    {
        $status = new MuLoaderStatus($this->makeTemplateFile(), '/tmp/some-mu-plugins/');

        self::assertSame('/tmp/some-mu-plugins', $status->expectedDirectory());
        self::assertStringEndsWith('/00-niyiguard-loader.php', $status->expectedPath());
    }

    public function test_is_installed_safely_returns_false_when_no_mu_directory_can_be_resolved(): void
    {
        // Empty string for muPluginsDir simulates a host that has neither
        // WPMU_PLUGIN_DIR nor NIYIGUARD_PATH available (an edge case during
        // very early bootstrap or in some CLI contexts).
        $status = new MuLoaderStatus($this->makeTemplateFile(), '');

        self::assertFalse($status->isInstalled());
        self::assertSame('', $status->expectedPath());
    }

    private function makeTemplateFile(): string
    {
        $dir = $this->makeFixtureDir();
        $path = $dir . '/00-niyiguard-loader.php';
        \file_put_contents($path, "<?php\n// template\n");

        return $path;
    }

    private function makeFixtureDir(): string
    {
        $dir = \sys_get_temp_dir() . '/niyiguard-mu-status-' . \uniqid('', true);
        \mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) \scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @\unlink($path);
            }
        }
        @\rmdir($dir);
    }
}
