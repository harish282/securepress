<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Auth\AuthHardeningOptions;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Container;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\Http\RouteGuardRegistry;
use NiyiGuard\Core\Middleware\MiddlewareRegistry;
use NiyiGuard\Core\Middleware\MiddlewareStack;
use NiyiGuard\Facades\Security;
use NiyiGuard\Tests\Stubs\WpStubState;

/**
 * @see \NiyiGuard\Facades\Security::version
 * @see \NiyiGuard\Facades\Security::isFeatureEnabled
 */
final class SecurityIntrospectionTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_version_returns_semver_string(): void
    {
        self::assertNotSame('', Security::version());
        self::assertSame(Security::VERSION, Security::version());
    }

    public function test_isFeatureEnabled_returns_false_for_unknown_features(): void
    {
        $container = $this->minimalContainer();
        Security::bootstrap($container);

        self::assertFalse(Security::isFeatureEnabled(''));
        self::assertFalse(Security::isFeatureEnabled('nonsense'));
    }

    public function test_isFeatureEnabled_for_auth_hardening_master_switch(): void
    {
        $container = $this->minimalContainer();
        $container->singleton(Config::class, static fn (): Config => new Config());
        $container->singleton(
            AuthHardeningOptions::class,
            static fn (Container $c): AuthHardeningOptions => new AuthHardeningOptions($c->get(Config::class))
        );

        Security::bootstrap($container);

        self::assertTrue(Security::isFeatureEnabled('auth_hardening'));
        self::assertTrue(Security::isFeatureEnabled('auth_hardening.enabled'));
    }

    public function test_isFeatureEnabled_drills_into_auth_hardening_subkeys(): void
    {
        $container = $this->minimalContainer();
        $container->singleton(Config::class, static fn (): Config => new Config());
        $container->singleton(
            AuthHardeningOptions::class,
            static fn (Container $c): AuthHardeningOptions => new AuthHardeningOptions($c->get(Config::class))
        );

        Security::bootstrap($container);

        self::assertTrue(Security::isFeatureEnabled('auth_hardening.lockout'));
        self::assertTrue(Security::isFeatureEnabled('auth_hardening.sessions'));
        self::assertTrue(Security::isFeatureEnabled('auth_hardening.two_factor'));
        self::assertFalse(Security::isFeatureEnabled('auth_hardening.no_such_subkey'));
    }

    public function test_isFeatureEnabled_returns_false_when_master_switch_off(): void
    {
        WpStubState::$options[AuthHardeningOptions::OPTION_NAME] = [
            'enabled' => false,
        ];

        $container = $this->minimalContainer();
        $container->singleton(Config::class, static fn (): Config => new Config());
        $container->singleton(
            AuthHardeningOptions::class,
            static fn (Container $c): AuthHardeningOptions => new AuthHardeningOptions($c->get(Config::class))
        );

        Security::bootstrap($container);

        self::assertFalse(Security::isFeatureEnabled('auth_hardening'));
        self::assertFalse(Security::isFeatureEnabled('auth_hardening.lockout'));
    }

    public function test_isFeatureEnabled_security_headers_reflects_at_least_one_enabled_header(): void
    {
        $container = $this->minimalContainer();
        $container->singleton(Config::class, static fn (): Config => new Config());
        $container->singleton(
            SecurityHeadersOptions::class,
            static fn (Container $c): SecurityHeadersOptions => new SecurityHeadersOptions($c->get(Config::class))
        );

        Security::bootstrap($container);

        self::assertTrue(Security::isFeatureEnabled('security_headers'));
    }

    public function test_isFeatureEnabled_audit_logging_reads_config(): void
    {
        $container = $this->minimalContainer();
        $container->singleton(Config::class, static fn (): Config => new Config());

        Security::bootstrap($container);

        // Default config sets audit_log.enabled, so check the actual config to assert correctness.
        $configValue = (bool) $container->get(Config::class)->get('audit_log.enabled', false);
        self::assertSame($configValue, Security::isFeatureEnabled('audit_logging'));
    }

    private function minimalContainer(): Container
    {
        $container = new Container();
        $container->singleton(MiddlewareRegistry::class, static fn (): MiddlewareRegistry => new MiddlewareRegistry());
        $container->singleton(MiddlewareStack::class, static fn (): MiddlewareStack => new MiddlewareStack());
        $container->singleton(RouteGuardRegistry::class, static fn (): RouteGuardRegistry => new RouteGuardRegistry());

        return $container;
    }
}
