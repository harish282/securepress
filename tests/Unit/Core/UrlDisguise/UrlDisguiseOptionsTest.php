<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Core\UrlDisguise;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;
use SecurePress\Core\UrlDisguise\UrlDisguiseOptions;
use SecurePress\Tests\Stubs\WpStubState;

final class UrlDisguiseOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_is_active_requires_enabled_and_login_slug(): void
    {
        $config = new Config();
        $o = new UrlDisguiseOptions($config);
        self::assertFalse($o->isActive());

        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'block_default_wp_login' => true,
        ];
        self::assertTrue($o->isActive());
    }

    public function test_sanitize_ignores_legacy_admin_keys(): void
    {
        $config = new Config();
        $o = new UrlDisguiseOptions($config);
        $out = $o->sanitize([
            'enabled' => true,
            'login_slug' => 'my-login',
            'admin_slug' => 'ignored',
            'block_default_wp_login' => true,
            'block_default_wp_admin' => true,
        ]);
        self::assertSame([
            'enabled' => true,
            'login_slug' => 'my-login',
            'block_default_wp_login' => true,
        ], $out);
    }

    public function test_normalize_slug_rejects_reserved_and_short(): void
    {
        $config = new Config();
        $o = new UrlDisguiseOptions($config);
        self::assertSame('', $o->normalizeSlug('wp-admin'));
        self::assertSame('', $o->normalizeSlug('ab'));
        self::assertSame('valid-slug', $o->normalizeSlug('Valid-Slug'));
    }
}
