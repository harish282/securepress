<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;

/**
 * @see \SecurePress\Core\Config\Config
 */
final class LicensingSecretEnvTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SECUREPRESS_LICENSE_SECRET');
        unset($_ENV['SECUREPRESS_LICENSE_SECRET'], $_SERVER['SECUREPRESS_LICENSE_SECRET']);
    }

    public function test_licensing_secret_overridden_by_environment_variable(): void
    {
        $secret = 'unit-test-license-secret-32bytes!!';
        putenv('SECUREPRESS_LICENSE_SECRET=' . $secret);
        $_ENV['SECUREPRESS_LICENSE_SECRET'] = $secret;
        $_SERVER['SECUREPRESS_LICENSE_SECRET'] = $secret;

        $config = new Config();

        self::assertSame($secret, $config->get('licensing.secret'));
    }
}
