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
            'admin_slug' => '',
            'block_default_wp_login' => true,
        ];
        self::assertTrue($o->isActive());
    }

    public function test_sanitize_clears_admin_when_same_as_login(): void
    {
        $config = new Config();
        $o = new UrlDisguiseOptions($config);
        $out = $o->sanitize([
            'enabled' => true,
            'login_slug' => 'same-slug',
            'admin_slug' => 'SAME-SLUG',
            'block_default_wp_login' => true,
        ]);
        self::assertSame('same-slug', $out['login_slug']);
        self::assertSame('', $out['admin_slug']);
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
