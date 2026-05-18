<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Recovery\SafeMode;

final class SafeModeBootstrapTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_env_true_defines_constant_when_not_already_set(): void
    {
        if (!\defined('ABSPATH')) {
            \define('ABSPATH', __DIR__ . '/../../');
        }
        if (!\defined('PRESS_SENTINEL_BOOTSTRAP_PATH')) {
            \define('PRESS_SENTINEL_BOOTSTRAP_PATH', dirname(__DIR__, 3) . '/bootstrap');
        }

        $_ENV['PRESS_SENTINEL_SAFE_MODE'] = 'true';
        $_SERVER['PRESS_SENTINEL_SAFE_MODE'] = 'true';
        putenv('PRESS_SENTINEL_SAFE_MODE=true');

        require PRESS_SENTINEL_BOOTSTRAP_PATH . '/safe-mode.php';

        self::assertTrue(\defined('PRESS_SENTINEL_SAFE_MODE'));
        self::assertTrue(PRESS_SENTINEL_SAFE_MODE);
        self::assertTrue(SafeMode::isActive());
    }
}
