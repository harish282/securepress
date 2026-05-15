<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Bootstrap;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use SecurePress\Core\Recovery\SafeMode;

final class SafeModeBootstrapTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_env_true_defines_constant_when_not_already_set(): void
    {
        if (!\defined('ABSPATH')) {
            \define('ABSPATH', __DIR__ . '/../../');
        }
        if (!\defined('SECUREPRESS_BOOTSTRAP_PATH')) {
            \define('SECUREPRESS_BOOTSTRAP_PATH', dirname(__DIR__, 3) . '/bootstrap');
        }

        $_ENV['SECUREPRESS_SAFE_MODE'] = 'true';
        $_SERVER['SECUREPRESS_SAFE_MODE'] = 'true';
        putenv('SECUREPRESS_SAFE_MODE=true');

        require SECUREPRESS_BOOTSTRAP_PATH . '/safe-mode.php';

        self::assertTrue(\defined('SECUREPRESS_SAFE_MODE'));
        self::assertTrue(SECUREPRESS_SAFE_MODE);
        self::assertTrue(SafeMode::isActive());
    }
}
