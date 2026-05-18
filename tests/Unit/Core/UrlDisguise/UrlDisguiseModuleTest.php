<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Core\UrlDisguise;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\UrlDisguise\UrlDisguiseModule;
use PressSentinel\Core\UrlDisguise\UrlDisguiseOptions;
use PressSentinel\Tests\Stubs\WpStubState;

final class UrlDisguiseModuleTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_add_rewrite_rules_is_no_op_when_inactive(): void
    {
        $config = new Config();
        $options = new UrlDisguiseOptions($config);
        $module = new UrlDisguiseModule($options);
        $module->addRewriteRules();
        self::assertSame([], WpStubState::$rewriteRulesAdded);
    }

    public function test_add_rewrite_rules_registers_login_when_active(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'block_default_wp_login' => true,
        ];
        $options = new UrlDisguiseOptions($config);
        $module = new UrlDisguiseModule($options);
        $module->addRewriteRules();
        self::assertCount(1, WpStubState::$rewriteRulesAdded);
        $patterns = array_column(WpStubState::$rewriteRulesAdded, 'pattern');
        $loginQuoted = preg_quote('my-login', '#');
        self::assertContains('^' . $loginQuoted . '/?$', $patterns);
    }

    public function test_maybe_block_default_wp_login_does_not_redirect(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'secret-gate',
            'block_default_wp_login' => true,
        ];
        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $module->maybeBlockDefaultWpLogin();
        self::assertSame([], WpStubState::$redirects);
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_register_wires_template_redirect(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'gate',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $module->register();
        self::assertTrue(WpStubState::hasAction('init'));
        self::assertTrue(WpStubState::hasAction('template_redirect'));
        self::assertTrue(WpStubState::hasFilter('pre_handle_404'));
        self::assertTrue(WpStubState::hasFilter('redirect_canonical'));
    }

    public function test_request_path_relative_to_home_strips_blog_prefix(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $ref = new \ReflectionClass($module);
        $m = $ref->getMethod('getRequestPathRelativeToHome');
        $m->setAccessible(true);

        WpStubState::$siteUrl = 'https://example.test/blog';
        $_SERVER['REQUEST_URI'] = '/blog/my-login/?action=logout';
        self::assertSame('my-login', $m->invoke($module));

        WpStubState::$siteUrl = 'https://example.test';
        $_SERVER['REQUEST_URI'] = '/my-login/';
        self::assertSame('my-login', $m->invoke($module));

        $_SERVER['REQUEST_URI'] = '/index.php/my-login/';
        self::assertSame('my-login', $m->invoke($module));

        WpStubState::$homeUrl = 'https://example.test';
        WpStubState::$siteUrl = 'https://example.test/wp';
        $_SERVER['REQUEST_URI'] = '/wp/my-login/';
        self::assertSame('my-login', $m->invoke($module));
        WpStubState::$homeUrl = null;

        unset($_SERVER['REQUEST_URI']);
    }

    public function test_filter_login_url_rewrites_when_active(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'gate',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $out = $module->filterLoginUrl('https://example.test/wp-login.php?reauth=1', '', false);
        self::assertStringContainsString('/gate/', $out);
        self::assertStringContainsString('reauth=1', $out);
    }

    public function test_filter_site_url_rewrites_wp_login_path(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'gate',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $out = $module->filterSiteUrl(
            'https://example.test/wp-login.php?action=logout',
            'wp-login.php?action=logout',
            'login',
            null
        );
        self::assertStringContainsString('/gate/', $out);
        self::assertStringContainsString('action=logout', $out);
    }

    public function test_filter_logout_url_preserves_nonce_from_esc_html_url(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'gate',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        // Same shape as wp_nonce_url( esc_html( add_query_arg( ... ) ) ) — & as &amp;
        $encoded = 'https://example.test/gate/?action=logout&amp;_wpnonce=fakeval';
        $out = $module->filterLogoutUrl($encoded, '');
        self::assertStringContainsString('_wpnonce=fakeval', $out);
        self::assertStringNotContainsString('&amp;', $out);
    }
}
