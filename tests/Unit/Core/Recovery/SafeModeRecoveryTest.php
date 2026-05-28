<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Core\Recovery;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Auth\Lockout\ArrayLockoutStore;
use NiyiGuard\Core\Auth\Lockout\LoginLockoutPolicy;
use NiyiGuard\Core\Auth\Lockout\LoginLockoutService;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\RateLimit\ArrayStore;
use NiyiGuard\Core\RateLimit\RateLimiter;
use NiyiGuard\Core\RateLimit\RateLimitOptions;
use NiyiGuard\Core\Recovery\SafeMode;
use NiyiGuard\Core\UrlDisguise\UrlDisguiseOptions;
use NiyiGuard\Middleware\RateLimitMiddleware;
use NiyiGuard\Tests\Stubs\WpStubState;

final class SafeModeRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
        if (\function_exists('remove_all_filters')) {
            \remove_all_filters('niyiguard_safe_mode');
            \remove_all_filters('niyiguard_safe_mode_bypasses');
        }
    }

    protected function tearDown(): void
    {
        if (\function_exists('remove_all_filters')) {
            \remove_all_filters('niyiguard_safe_mode');
            \remove_all_filters('niyiguard_safe_mode_bypasses');
        }
        WpStubState::reset();
    }

    private function enableSafeMode(): void
    {
        if (!\function_exists('add_filter')) {
            self::markTestSkipped('WordPress filter API not available.');
        }
        \add_filter('niyiguard_safe_mode', static fn (): bool => true);
    }

    public function test_lockout_service_never_locks_in_safe_mode(): void
    {
        $this->enableSafeMode();
        $store = new ArrayLockoutStore();
        $service = new LoginLockoutService($store, new LoginLockoutPolicy(1, 60, 60, true));

        self::assertFalse($service->registerFailure('alice', '203.0.113.1'));
        self::assertFalse($service->isLocked('alice', '203.0.113.1'));
    }

    public function test_url_disguise_is_inactive_in_safe_mode(): void
    {
        $this->enableSafeMode();
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'secret',
            'block_default_wp_login' => true,
        ];
        $options = new UrlDisguiseOptions(new Config());
        self::assertFalse($options->isActive());
    }

    public function test_rate_limit_options_report_disabled_in_safe_mode(): void
    {
        $this->enableSafeMode();
        WpStubState::$options[RateLimitOptions::OPTION_NAME] = [
            'enabled' => true,
            'limit' => 60,
            'window' => 60,
        ];
        self::assertFalse((new RateLimitOptions(new Config()))->isEnabled());
    }

    public function test_rate_limit_middleware_passes_through_in_safe_mode(): void
    {
        $this->enableSafeMode();
        $middleware = new RateLimitMiddleware(
            new RateLimiter(new ArrayStore()),
            enabled: true,
            limit: 1,
            window: 60,
        );
        $reached = false;
        $result = $middleware->handle(
            ['request' => ['ip' => '203.0.113.9']],
            static function (array $ctx) use (&$reached): array {
                $reached = true;

                return $ctx;
            }
        );
        self::assertTrue($reached);
        self::assertTrue($result['rate_limit']['bypassed']);
        self::assertTrue($result['rate_limit']['safe_mode']);
    }

}
