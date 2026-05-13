<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;
use SecurePress\Core\RateLimit\RateLimitOptions;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * @see RateLimitOptions
 *
 * Verifies the config-defaults + wp_option overlay contract, the canonical
 * shape returned by {@see RateLimitOptions::all()}, and the safety
 * invariants enforced by {@see RateLimitOptions::sanitize()} (bounds
 * clamping, type coercion, partial-update merge).
 */
final class RateLimitOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_returns_canonical_defaults_when_no_option_stored(): void
    {
        $options = new RateLimitOptions(new Config());

        self::assertSame(
            [
                'enabled' => true,
                'limit' => 60,
                'window' => 60,
            ],
            $options->all()
        );
        self::assertTrue($options->isEnabled());
        self::assertSame(60, $options->limit());
        self::assertSame(60, $options->window());
    }

    public function test_wp_option_overrides_config_defaults(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => false,
            'limit' => 200,
            'window' => 30,
        ];

        $options = new RateLimitOptions(new Config());

        self::assertFalse($options->isEnabled());
        self::assertSame(200, $options->limit());
        self::assertSame(30, $options->window());
    }

    public function test_set_enabled_flips_master_flag_without_touching_limit_or_window(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => true,
            'limit' => 250,
            'window' => 45,
        ];

        $options = new RateLimitOptions(new Config());
        $options->setEnabled(false);

        $stored = WpStubState::$options[RateLimitOptions::OPTION_NAME];
        self::assertIsArray($stored);
        self::assertFalse($stored['enabled']);
        self::assertSame(250, $stored['limit'], 'setEnabled() must never reset the tuned limit');
        self::assertSame(45, $stored['window'], 'setEnabled() must never reset the tuned window');
    }

    public function test_sanitize_clamps_out_of_range_values(): void
    {
        $options = new RateLimitOptions(new Config());

        $sanitized = $options->sanitize([
            'enabled' => '1',
            'limit' => -50,
            'window' => 99_999_999, // way above the 24h cap
        ]);

        self::assertTrue($sanitized['enabled']);
        self::assertSame(RateLimitOptions::LIMIT_MIN, $sanitized['limit']);
        self::assertSame(RateLimitOptions::WINDOW_MAX, $sanitized['window']);
    }

    public function test_sanitize_coerces_string_booleans_for_the_master_flag(): void
    {
        $options = new RateLimitOptions(new Config());

        self::assertTrue($options->sanitize(['enabled' => 'on'])['enabled']);
        self::assertTrue($options->sanitize(['enabled' => 'YES'])['enabled']);
        self::assertTrue($options->sanitize(['enabled' => '1'])['enabled']);
        self::assertFalse($options->sanitize(['enabled' => '0'])['enabled']);
        self::assertFalse($options->sanitize(['enabled' => 'off'])['enabled']);
    }

    public function test_sanitize_treats_non_array_input_as_empty(): void
    {
        $options = new RateLimitOptions(new Config());

        $sanitized = $options->sanitize('not-an-array');

        self::assertSame(
            ['enabled' => true, 'limit' => 60, 'window' => 60],
            $sanitized
        );
    }

    /**
     * Partial submissions (e.g. a future UI that posts only `limit` or only
     * `enabled`) must never silently reset the OTHER fields to config
     * defaults. This is the same guarantee SecurityHeadersOptions enforces.
     */
    public function test_sanitize_merges_with_currently_stored_values(): void
    {
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => false,
            'limit' => 500,
            'window' => 120,
        ];

        $options = new RateLimitOptions(new Config());

        $sanitized = $options->sanitize(['limit' => 1000]);

        self::assertFalse($sanitized['enabled'], 'master enabled must NOT be reset by a partial save');
        self::assertSame(1000, $sanitized['limit']);
        self::assertSame(120, $sanitized['window'], 'window must NOT be reset by a partial save');
    }
}
