<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\Licensing\LicenseHmacSecretProvisioner;
use PressSentinel\Tests\Stubs\WpStubState;

/**
 * @see \PressSentinel\Core\Config\Config
 */
final class LicensingSecretEnvTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PRESS_SENTINEL_LICENSE_SECRET');
        unset($_ENV['PRESS_SENTINEL_LICENSE_SECRET'], $_SERVER['PRESS_SENTINEL_LICENSE_SECRET']);
        unset(WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME]);
        if (\function_exists('remove_all_filters')) {
            \remove_all_filters('presssentinel_licensing_secret');
        }
    }

    public function test_licensing_secret_database_option_overrides_environment(): void
    {
        $db = 'dddddddddddddddddddddddddddddddd';
        WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] = $db;
        putenv('PRESS_SENTINEL_LICENSE_SECRET=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $_ENV['PRESS_SENTINEL_LICENSE_SECRET'] = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $_SERVER['PRESS_SENTINEL_LICENSE_SECRET'] = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

        $config = new Config();

        self::assertSame($db, $config->get('licensing.secret'));
    }

    public function test_licensing_secret_overridden_by_environment_variable(): void
    {
        $secret = 'unit-test-license-secret-32bytes!!';
        putenv('PRESS_SENTINEL_LICENSE_SECRET=' . $secret);
        $_ENV['PRESS_SENTINEL_LICENSE_SECRET'] = $secret;
        $_SERVER['PRESS_SENTINEL_LICENSE_SECRET'] = $secret;

        $config = new Config();

        self::assertSame($secret, $config->get('licensing.secret'));
    }

    public function test_licensing_secret_filter_overrides_env(): void
    {
        putenv('PRESS_SENTINEL_LICENSE_SECRET=env-secret-32bytes-min-length-ok!');
        $_ENV['PRESS_SENTINEL_LICENSE_SECRET'] = 'env-secret-32bytes-min-length-ok!';
        $_SERVER['PRESS_SENTINEL_LICENSE_SECRET'] = 'env-secret-32bytes-min-length-ok!';

        \add_filter('presssentinel_licensing_secret', static fn (): string => 'filter-secret-32bytes-min-length-ok!');

        $config = new Config();

        self::assertSame('filter-secret-32bytes-min-length-ok!', $config->get('licensing.secret'));
    }

    /**
     * wp-config constants override database option and environment.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_wp_config_constant_overrides_database_option_and_environment(): void
    {
        WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] = 'dddddddddddddddddddddddddddddddd';
        putenv('PRESS_SENTINEL_LICENSE_SECRET=eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $_ENV['PRESS_SENTINEL_LICENSE_SECRET'] = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
        $_SERVER['PRESS_SENTINEL_LICENSE_SECRET'] = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

        if (!\defined('PRESS_SENTINEL_LICENSE_SECRET')) {
            \define('PRESS_SENTINEL_LICENSE_SECRET', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        }

        $config = new Config();

        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $config->get('licensing.secret'));
    }
}
