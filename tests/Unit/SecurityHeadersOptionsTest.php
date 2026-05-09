<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Tests\Stubs\WpStubState;

final class SecurityHeadersOptionsTest extends TestCase
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
        $options = new SecurityHeadersOptions(new Config());

        $config = $options->all();

        self::assertFalse($config['hsts']['enabled']);
        self::assertSame(31_536_000, $config['hsts']['max_age']);
        self::assertFalse($config['hsts']['preload']);

        self::assertFalse($config['csp']['enabled']);
        self::assertTrue($config['csp']['report_only']);

        self::assertTrue($config['x_frame_options']['enabled']);
        self::assertSame('SAMEORIGIN', $config['x_frame_options']['value']);

        self::assertTrue($config['referrer_policy']['enabled']);
        self::assertSame('strict-origin-when-cross-origin', $config['referrer_policy']['policy']);

        self::assertTrue($config['x_content_type_options']['enabled']);
    }

    public function test_stored_option_overrides_defaults(): void
    {
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = [
            'hsts' => ['enabled' => true, 'max_age' => 86400, 'include_subdomains' => true],
            'csp' => ['enabled' => true, 'policy' => "default-src 'self'", 'report_only' => false],
            'x_frame_options' => ['enabled' => true, 'value' => 'DENY'],
        ];

        $options = new SecurityHeadersOptions(new Config());
        $config = $options->all();

        self::assertTrue($config['hsts']['enabled']);
        self::assertSame(86400, $config['hsts']['max_age']);
        self::assertTrue($config['hsts']['include_subdomains']);
        self::assertFalse($config['hsts']['preload'], 'unset keys retain defaults');

        self::assertTrue($config['csp']['enabled']);
        self::assertSame("default-src 'self'", $config['csp']['policy']);
        self::assertFalse($config['csp']['report_only']);

        self::assertSame('DENY', $config['x_frame_options']['value']);
    }

    public function test_sanitize_coerces_string_booleans_and_invalid_values(): void
    {
        $options = new SecurityHeadersOptions(new Config());

        $sanitized = $options->sanitize([
            'hsts' => ['enabled' => '1', 'max_age' => '-50', 'include_subdomains' => 'on', 'preload' => '0'],
            'csp' => ['enabled' => 'true', 'policy' => '   default-src  ', 'report_only' => 'no'],
            'x_frame_options' => ['enabled' => 1, 'value' => 'invalid-thing'],
            'referrer_policy' => ['enabled' => true, 'policy' => 'NEVER-HEARD-OF'],
            'permissions_policy' => ['enabled' => 'yes', 'policy' => '  geolocation=()  '],
            'x_content_type_options' => ['enabled' => 0],
        ]);

        self::assertTrue($sanitized['hsts']['enabled']);
        self::assertSame(0, $sanitized['hsts']['max_age'], 'negative max-age clamps to 0');
        self::assertTrue($sanitized['hsts']['include_subdomains']);
        self::assertFalse($sanitized['hsts']['preload']);

        self::assertTrue($sanitized['csp']['enabled']);
        self::assertSame('default-src', $sanitized['csp']['policy']);
        self::assertFalse($sanitized['csp']['report_only']);

        self::assertSame('SAMEORIGIN', $sanitized['x_frame_options']['value'], 'invalid value falls back to default');
        self::assertSame('strict-origin-when-cross-origin', $sanitized['referrer_policy']['policy']);

        self::assertTrue($sanitized['permissions_policy']['enabled']);
        self::assertSame('geolocation=()', $sanitized['permissions_policy']['policy']);

        self::assertFalse($sanitized['x_content_type_options']['enabled']);
    }

    public function test_sanitize_handles_non_array_input(): void
    {
        $options = new SecurityHeadersOptions(new Config());

        $sanitized = $options->sanitize('not-an-array');

        self::assertArrayHasKey('hsts', $sanitized);
        self::assertArrayHasKey('csp', $sanitized);
        self::assertFalse($sanitized['hsts']['enabled']);
    }

    public function test_corrupt_stored_option_falls_back_to_defaults(): void
    {
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = 'definitely-not-an-array';

        $options = new SecurityHeadersOptions(new Config());
        $config = $options->all();

        self::assertSame('SAMEORIGIN', $config['x_frame_options']['value']);
    }
}
