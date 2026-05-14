<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Core\UrlDisguise;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;
use SecurePress\Core\UrlDisguise\UrlDisguiseModule;
use SecurePress\Core\UrlDisguise\UrlDisguiseOptions;
use SecurePress\Tests\Stubs\WpStubState;

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

    public function test_add_rewrite_rules_registers_login_and_admin_when_active(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'admin_slug' => 'my-dash',
            'block_default_wp_login' => true,
        ];
        $options = new UrlDisguiseOptions($config);
        $module = new UrlDisguiseModule($options);
        $module->addRewriteRules();
        self::assertGreaterThanOrEqual(1, count(WpStubState::$rewriteRulesAdded));
        $patterns = array_column(WpStubState::$rewriteRulesAdded, 'pattern');
        $loginQuoted = preg_quote('my-login', '#');
        self::assertContains('^' . $loginQuoted . '/?$', $patterns);
        $adminQuoted = preg_quote('my-dash', '#');
        self::assertContains('^' . $adminQuoted . '/?$', $patterns);
    }

    public function test_maybe_block_default_wp_admin_root_does_not_redirect(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'secret-gate',
            'admin_slug' => 'my-dash',
            'block_default_wp_login' => true,
            'block_default_wp_admin' => true,
        ];
        $_SERVER['REQUEST_URI'] = '/wp-admin/';
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $module->maybeBlockDefaultWpAdmin();
        self::assertSame([], WpStubState::$redirects);
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_is_request_for_default_wp_admin_entry(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'gate',
            'admin_slug' => 'dash',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $m = (new \ReflectionClass($module))->getMethod('isRequestForDefaultWpAdminEntry');
        $m->setAccessible(true);

        foreach (['/wp-admin', '/wp-admin/', '/blog/wp-admin/index.php', '/Wp-Admin/'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            self::assertTrue($m->invoke($module), $uri);
        }
        foreach (['/wp-admin/edit.php', '/wp-admin/admin-ajax.php', '/wp-admin/css/foo.css'] as $uri) {
            $_SERVER['REQUEST_URI'] = $uri;
            self::assertFalse($m->invoke($module), $uri);
        }
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_maybe_block_default_wp_login_does_not_redirect(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'secret-gate',
            'admin_slug' => '',
            'block_default_wp_login' => true,
        ];
        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $module->maybeBlockDefaultWpLogin();
        self::assertSame([], WpStubState::$redirects);
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_load_woocommerce_admin_layer_if_missing_is_no_op_without_wc(): void
    {
        $config = new Config();
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $m = (new \ReflectionClass($module))->getMethod('loadWooCommerceAdminLayerIfMissing');
        $m->setAccessible(true);
        $this->expectNotToPerformAssertions();
        $m->invoke($module);
    }

    public function test_register_wires_template_redirect(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'gate',
            'admin_slug' => '',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $module->register();
        self::assertTrue(WpStubState::hasAction('init'));
        self::assertTrue(WpStubState::hasAction('template_redirect'));
        self::assertTrue(WpStubState::hasFilter('pre_handle_404'));
        self::assertTrue(WpStubState::hasFilter('redirect_canonical'));
        self::assertTrue(WpStubState::hasFilter('auth_redirect_scheme'));
    }

    public function test_filter_auth_redirect_scheme_uses_logged_in_on_disguised_admin_path(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'admin_slug' => 'my-dash',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $_SERVER['REQUEST_URI'] = '/my-dash/index.php';
        self::assertSame('logged_in', $module->filterAuthRedirectScheme(''));
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_filter_auth_redirect_scheme_leaves_explicit_scheme(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'admin_slug' => 'my-dash',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $_SERVER['REQUEST_URI'] = '/my-dash/';
        self::assertSame('auth', $module->filterAuthRedirectScheme('auth'));
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_filter_auth_redirect_scheme_not_for_real_wp_admin_path(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'admin_slug' => 'my-dash',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions($config));
        $_SERVER['REQUEST_URI'] = '/wp-admin/index.php';
        self::assertSame('', $module->filterAuthRedirectScheme(''));
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_request_path_relative_to_home_strips_blog_prefix(): void
    {
        $config = new Config();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'my-login',
            'admin_slug' => '',
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
            'admin_slug' => '',
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
            'admin_slug' => '',
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
            'admin_slug' => '',
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
