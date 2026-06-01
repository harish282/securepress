<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Auth\AuthHardeningOptions;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Tests\Stubs\WpStubState;

/**
 * @see \NiyiGuard\Core\Auth\AuthHardeningOptions
 */
final class AuthHardeningOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_returns_normalized_defaults_when_no_option_stored(): void
    {
        $options = new AuthHardeningOptions(new Config());

        $resolved = $options->all();

        self::assertTrue($resolved['enabled']);
        self::assertTrue($resolved['lockout']['enabled']);
        self::assertSame(5, $resolved['lockout']['max_attempts']);
        self::assertSame(900, $resolved['lockout']['window_seconds']);
        self::assertSame(900, $resolved['lockout']['lock_seconds']);

        self::assertTrue($resolved['sessions']['enabled']);
        self::assertSame(90, $resolved['sessions']['retention_days']);

        self::assertTrue($resolved['suspicion']['enabled']);
        self::assertSame(50, $resolved['suspicion']['alert_threshold']);
        self::assertTrue($resolved['suspicion']['rules']['new_device']);

        self::assertSame('NiyiGuard', $resolved['two_factor']['issuer']);
        self::assertSame(600, $resolved['two_factor']['challenge_ttl_seconds']);

        self::assertTrue($resolved['notifications']['enabled']);
    }

    public function test_is_enabled_reflects_resolved_value(): void
    {
        $options = new AuthHardeningOptions(new Config());
        self::assertTrue($options->isEnabled());

        WpStubState::$options[AuthHardeningOptions::OPTION_NAME] = ['enabled' => false];
        self::assertFalse($options->isEnabled());
    }

    public function test_stored_option_overrides_defaults(): void
    {
        WpStubState::$options[AuthHardeningOptions::OPTION_NAME] = [
            'enabled' => false,
            'lockout' => [
                'enabled' => false,
                'max_attempts' => 12,
                'window_seconds' => 1800,
            ],
            'sessions' => ['retention_days' => 30],
            'suspicion' => [
                'alert_threshold' => 100,
                'rules' => ['new_device' => false],
            ],
            'two_factor' => [
                'issuer' => 'Acme HQ',
                'challenge_ttl_seconds' => 300,
            ],
            'notifications' => ['enabled' => false],
        ];

        $resolved = (new AuthHardeningOptions(new Config()))->all();

        self::assertFalse($resolved['enabled']);
        self::assertFalse($resolved['lockout']['enabled']);
        self::assertSame(12, $resolved['lockout']['max_attempts']);
        self::assertSame(1800, $resolved['lockout']['window_seconds']);
        self::assertSame(900, $resolved['lockout']['lock_seconds'], 'unset keys retain their default');

        self::assertSame(30, $resolved['sessions']['retention_days']);
        self::assertTrue($resolved['sessions']['enabled'], 'unset key inherits default');

        self::assertSame(100, $resolved['suspicion']['alert_threshold']);
        self::assertFalse($resolved['suspicion']['rules']['new_device']);

        self::assertSame('Acme HQ', $resolved['two_factor']['issuer']);
        self::assertSame(300, $resolved['two_factor']['challenge_ttl_seconds']);

        self::assertFalse($resolved['notifications']['enabled']);
    }

    public function test_sanitize_clamps_numeric_inputs(): void
    {
        $options = new AuthHardeningOptions(new Config());

        $sanitized = $options->sanitize([
            'enabled' => 'on',
            'lockout' => [
                'enabled' => '1',
                'max_attempts' => '0',          // below floor
                'window_seconds' => '10',       // below floor (60)
                'lock_seconds' => '1000000',    // above ceiling
            ],
            'sessions' => ['retention_days' => '99999'],
            'suspicion' => [
                'enabled' => 'no',
                'alert_threshold' => '-50',
                'rules' => ['new_device' => 'true'],
            ],
            'two_factor' => [
                'issuer' => "  My : App  \x00",
                'challenge_ttl_seconds' => '30',
            ],
            'notifications' => ['enabled' => '0'],
        ]);

        self::assertTrue($sanitized['enabled']);
        self::assertSame(1, $sanitized['lockout']['max_attempts']);
        self::assertSame(60, $sanitized['lockout']['window_seconds']);
        self::assertSame(86_400, $sanitized['lockout']['lock_seconds']);

        self::assertSame(3650, $sanitized['sessions']['retention_days']);

        self::assertFalse($sanitized['suspicion']['enabled']);
        self::assertSame(0, $sanitized['suspicion']['alert_threshold']);
        self::assertTrue($sanitized['suspicion']['rules']['new_device']);

        self::assertSame('My  App', $sanitized['two_factor']['issuer'], 'control + colon stripped, trimmed');
        self::assertSame(60, $sanitized['two_factor']['challenge_ttl_seconds']);

        self::assertFalse($sanitized['notifications']['enabled']);
    }

    public function test_sanitize_handles_non_array_input(): void
    {
        $options = new AuthHardeningOptions(new Config());

        $sanitized = $options->sanitize('not-an-array');

        self::assertArrayHasKey('lockout', $sanitized);
        self::assertSame(5, $sanitized['lockout']['max_attempts']);
        self::assertTrue($sanitized['enabled']);
    }

    public function test_corrupt_stored_option_falls_back_to_defaults(): void
    {
        WpStubState::$options[AuthHardeningOptions::OPTION_NAME] = 'definitely-not-an-array';

        $resolved = (new AuthHardeningOptions(new Config()))->all();

        self::assertTrue($resolved['enabled']);
        self::assertSame(900, $resolved['lockout']['lock_seconds']);
    }

    public function test_empty_or_invalid_issuer_falls_back_to_default(): void
    {
        $options = new AuthHardeningOptions(new Config());

        $sanitized = $options->sanitize(['two_factor' => ['issuer' => '   ']]);

        self::assertSame('NiyiGuard', $sanitized['two_factor']['issuer']);
    }
}
