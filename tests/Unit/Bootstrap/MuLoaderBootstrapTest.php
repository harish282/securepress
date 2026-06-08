<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

final class MuLoaderBootstrapTest extends TestCase
{
    public function test_does_not_boot_when_plugin_is_not_active(): void
    {
        self::assertSame('0', $this->runLoaderScenario([
            'secure-press/niyiguard.php',
        ], []));
    }

    public function test_boots_when_plugin_is_site_active(): void
    {
        self::assertSame('1', $this->runLoaderScenario(
            ['secure-press/niyiguard.php'],
            ['secure-press/niyiguard.php']
        ));
    }

    public function test_boots_when_plugin_is_network_active(): void
    {
        self::assertSame('1', $this->runLoaderScenario(
            ['secure-press/niyiguard.php'],
            [],
            ['secure-press/niyiguard.php' => time()]
        ));
    }

    /**
     * @param list<string> $pluginFolders
     * @param list<string> $activePlugins
     * @param array<string, int> $networkActivePlugins
     */
    private function runLoaderScenario(
        array $pluginFolders,
        array $activePlugins,
        array $networkActivePlugins = [],
    ): string {
        $root = dirname(__DIR__, 3);
        $sandbox = sys_get_temp_dir() . '/niyiguard-mu-loader-' . bin2hex(random_bytes(8));
        $pluginsDir = $sandbox . '/wp-content/plugins';
        $muLoader = $root . '/mu-loader/00-niyiguard-loader.php';

        self::assertTrue(mkdir($sandbox . '/wp-content/mu-plugins', 0777, true));
        self::assertTrue(mkdir($pluginsDir, 0777, true));

        foreach ($pluginFolders as $folder) {
            $pluginDir = $pluginsDir . '/' . dirname($folder);
            self::assertTrue(mkdir($pluginDir, 0777, true));
            file_put_contents($pluginsDir . '/' . $folder, "<?php\ndefine('NIYIGUARD_BOOTED', true);\n");
        }

        $activePluginsExport = var_export($activePlugins, true);
        $networkActivePluginsExport = var_export($networkActivePlugins, true);

        $script = <<<PHP
<?php
define('ABSPATH', '{$sandbox}/');
define('WP_PLUGIN_DIR', '{$pluginsDir}');
function get_option(string \$name, mixed \$default = false): mixed
{
    if (\$name === 'active_plugins') {
        return {$activePluginsExport};
    }

    return \$default;
}
function get_site_option(string \$name, mixed \$default = false): mixed
{
    if (\$name === 'active_sitewide_plugins') {
        return {$networkActivePluginsExport};
    }

    return \$default;
}
function is_multisite(): bool
{
    return true;
}
function plugin_basename(string \$file): string
{
    \$pluginsDir = WP_PLUGIN_DIR;
    \$normalized = str_replace('\\\\', '/', \$file);
    \$prefix = rtrim(str_replace('\\\\', '/', \$pluginsDir), '/') . '/';

    return str_starts_with(\$normalized, \$prefix)
        ? substr(\$normalized, strlen(\$prefix))
        : basename(\$normalized);
}
require '{$muLoader}';
echo defined('NIYIGUARD_BOOTED') ? '1' : '0';
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'ps-mu-loader-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY . ' ' . escapeshellarg($tmp), $output, $exitCode);
        @unlink($tmp);
        $this->removeDirectory($sandbox);

        self::assertSame(0, $exitCode);

        return $output[0] ?? '';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
