<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use SecurePress\Core\Container;
use SecurePress\Core\Http\RouteGuardRegistry;
use SecurePress\Core\Middleware\MiddlewareInterface;
use SecurePress\Core\Middleware\MiddlewareRegistry;
use SecurePress\Core\Middleware\MiddlewareStack;
use SecurePress\Facades\Security;

final class SecurityFacadeTest extends TestCase
{
    public function test_middleware_queues_and_registers_when_classes_implement_interface(): void
    {
        $container = $this->createMinimalContainer();

        Security::bootstrap($container);
        Security::middleware([SecurityTestMiddlewareAlpha::class, SecurityTestMiddlewareBeta::class]);

        $stack = $container->get(MiddlewareStack::class);
        self::assertSame([
            SecurityTestMiddlewareAlpha::class,
            SecurityTestMiddlewareBeta::class,
        ], $stack->all());

        $registry = $container->get(MiddlewareRegistry::class);
        self::assertSame(SecurityTestMiddlewareAlpha::class, $registry->resolve(SecurityTestMiddlewareAlpha::class));
        self::assertSame(SecurityTestMiddlewareBeta::class, $registry->resolve(SecurityTestMiddlewareBeta::class));
    }

    public function test_middleware_puts_unknown_class_strings_on_stack_but_skips_registry(): void
    {
        $container = $this->createMinimalContainer();

        Security::bootstrap($container);
        $unknownClass = 'SecurePress\\Tests\\Stub\\NonExistentMiddleware';
        Security::middleware([SecurityTestMiddlewareAlpha::class, $unknownClass]);

        self::assertSame([
            SecurityTestMiddlewareAlpha::class,
            $unknownClass,
        ], $container->get(MiddlewareStack::class)->all());

        self::expectException(\SecurePress\Core\Middleware\MiddlewareException::class);
        $container->get(MiddlewareRegistry::class)->resolve($unknownClass);
    }

    public function test_protectRoute_registers_patterns(): void
    {
        $container = $this->createMinimalContainer();
        Security::bootstrap($container);

        Security::protectRoute('/admin/export');
        Security::protectRoute('');

        /** @var RouteGuardRegistry $routes */
        $routes = $container->get(RouteGuardRegistry::class);
        self::assertSame(['/admin/export'], $routes->all());
    }

    public function test_signed_url_throws_until_implemented(): void
    {
        $container = $this->createMinimalContainer();
        Security::bootstrap($container);

        $this->expectException(LogicException::class);
        Security::signedUrl('/download/123', expires: 3600);
    }

    public function test_verify_csrf_throws_until_implemented(): void
    {
        $container = $this->createMinimalContainer();
        Security::bootstrap($container);

        $this->expectException(LogicException::class);
        Security::verifyCsrf();
    }

    public function test_middleware_throws_when_class_exists_but_does_not_implement_middleware(): void
    {
        $container = $this->createMinimalContainer();
        Security::bootstrap($container);

        $this->expectException(LogicException::class);
        Security::middleware([SecurityTestPlainClass::class]);
    }

    public function test_requires_bootstrap_before_use(): void
    {
        $keep = $this->createMinimalContainer();
        Security::bootstrap($keep);

        $property = (new \ReflectionClass(Security::class))->getProperty('container');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $this->expectException(LogicException::class);

        try {
            Security::middleware([SecurityTestMiddlewareAlpha::class]);
        } finally {
            Security::bootstrap($keep);
        }
    }

    private function createMinimalContainer(): Container
    {
        $container = new Container();
        $container->singleton(MiddlewareRegistry::class, static fn (): MiddlewareRegistry => new MiddlewareRegistry());
        $container->singleton(MiddlewareStack::class, static fn (): MiddlewareStack => new MiddlewareStack());
        $container->singleton(RouteGuardRegistry::class, static fn (): RouteGuardRegistry => new RouteGuardRegistry());

        return $container;
    }
}

final class SecurityTestMiddlewareAlpha implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        return $next($context);
    }
}

final class SecurityTestMiddlewareBeta implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        return $next($context);
    }
}

final class SecurityTestPlainClass
{
}
