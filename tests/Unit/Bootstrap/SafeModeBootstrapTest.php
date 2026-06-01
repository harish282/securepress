<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

final class SafeModeBootstrapTest extends TestCase
{
    public function test_config_safe_mode_defines_constant_in_fresh_process(): void
    {
        if (\defined('NIYIGUARD_SAFE_MODE')) {
            self::markTestSkipped('NIYIGUARD_SAFE_MODE already defined in parent process');
        }

        $root = dirname(__DIR__, 3);
        $bootstrap = $root . '/bootstrap';
        $fixture = $root . '/tests/fixtures/config-safe-mode';

        $script = <<<PHP
<?php
define('ABSPATH', '/tmp');
define('NIYIGUARD_CONFIG_PATH', '{$fixture}');
define('NIYIGUARD_BOOTSTRAP_PATH', '{$bootstrap}');
require NIYIGUARD_BOOTSTRAP_PATH . '/safe-mode.php';
echo (defined('NIYIGUARD_SAFE_MODE') && NIYIGUARD_SAFE_MODE) ? '1' : '0';
PHP;

        $tmp = tempnam(sys_get_temp_dir(), 'ps-safe-mode-');
        self::assertNotFalse($tmp);
        file_put_contents($tmp, $script);

        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY . ' ' . escapeshellarg($tmp), $output, $exitCode);
        @unlink($tmp);

        self::assertSame(0, $exitCode);
        self::assertSame(['1'], $output);
    }
}
