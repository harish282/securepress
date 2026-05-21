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
final class LicensingSecretConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME]);
        if (\function_exists('remove_all_filters')) {
            \remove_all_filters('presssentinel_licensing_secret');
            \remove_all_filters('presssentinel_config');
        }
    }

    public function test_licensing_secret_database_option_overrides_config_default(): void
    {
        $db = 'dddddddddddddddddddddddddddddddd';
        WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] = $db;

        $config = new Config();

        self::assertSame($db, $config->get('licensing.secret'));
    }

    public function test_licensing_secret_config_file_default_when_database_empty(): void
    {
        $config = new Config();

        self::assertSame('change-me-in-production', $config->get('licensing.secret'));
    }

    public function test_licensing_secret_filter_overrides_config_default(): void
    {
        \add_filter('presssentinel_licensing_secret', static fn (): string => 'filter-secret-32bytes-min-length-ok!');

        $config = new Config();

        self::assertSame('filter-secret-32bytes-min-length-ok!', $config->get('licensing.secret'));
    }

    /**
     * wp-config constants override database option and config file default.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_wp_config_constant_overrides_database_option_and_config(): void
    {
        WpStubState::$options[LicenseHmacSecretProvisioner::OPTION_NAME] = 'dddddddddddddddddddddddddddddddd';

        if (!\defined('PRESS_SENTINEL_LICENSE_SECRET')) {
            \define('PRESS_SENTINEL_LICENSE_SECRET', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        }

        $config = new Config();

        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $config->get('licensing.secret'));
    }
}
