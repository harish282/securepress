<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;

final class SafeModeBootstrapTest extends TestCase
{
    public function test_config_safe_mode_defines_constant_in_fresh_process(): void
    {
        if (\defined('PRESS_SENTINEL_SAFE_MODE')) {
            self::markTestSkipped('PRESS_SENTINEL_SAFE_MODE already defined in parent process');
        }

        $root = dirname(__DIR__, 3);
        $bootstrap = $root . '/bootstrap';
        $fixture = $root . '/tests/fixtures/config-safe-mode';

        $script = <<<PHP
<?php
define('ABSPATH', '/tmp');
define('PRESS_SENTINEL_CONFIG_PATH', '{$fixture}');
define('PRESS_SENTINEL_BOOTSTRAP_PATH', '{$bootstrap}');
require PRESS_SENTINEL_BOOTSTRAP_PATH . '/safe-mode.php';
echo (defined('PRESS_SENTINEL_SAFE_MODE') && PRESS_SENTINEL_SAFE_MODE) ? '1' : '0';
PHP;

        $tmp = $root . '/storage/tmp/safe-mode-bootstrap-test.php';
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0775, true);
        }
        file_put_contents($tmp, $script);

        $output = [];
        $exitCode = 0;
        exec(PHP_BINARY . ' ' . escapeshellarg($tmp), $output, $exitCode);
        @unlink($tmp);

        self::assertSame(0, $exitCode);
        self::assertSame(['1'], $output);
    }
}
