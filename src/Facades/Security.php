<?php

declare(strict_types=1);

namespace SecurePress\Facades;

use LogicException;
use SecurePress\Core\Container;
use SecurePress\Core\Http\RouteGuardRegistry;
use SecurePress\Core\Middleware\MiddlewareInterface;
use SecurePress\Core\Middleware\MiddlewareRegistry;
use SecurePress\Core\Middleware\MiddlewareStack;

/**
 * Facade for security-related APIs. Bootstrapped from the plugin container.
 */
final class Security
{
    private static ?Container $container = null;

    public static function bootstrap(Container $container): void
    {
        self::$container = $container;
    }

    /**
     * @param array<int, class-string> $middleware
     */
    public static function middleware(array $middleware): void
    {
        $stack = self::stack();
        $stack->push($middleware);

        $registry = self::registry();
        foreach ($middleware as $class) {
            if (!class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, MiddlewareInterface::class)) {
                throw new LogicException(
                    sprintf('Middleware "%s" must implement %s.', $class, MiddlewareInterface::class)
                );
            }
            $registry->register($class, $class);
        }
    }

    /**
     * @param int|null $expires Seconds until expiry (named argument: expires: 3600).
     */
    public static function signedUrl(string $path, ?int $expires = null): string
    {
        unset($path, $expires);
        throw new LogicException('SecurePress::signedUrl() is not implemented yet.');
    }

    public static function protectRoute(string $route): void
    {
        $route = trim($route);
        if ($route === '') {
            return;
        }
        self::routeGuards()->register($route);
    }

    public static function verifyCsrf(): bool
    {
        throw new LogicException('SecurePress::verifyCsrf() is not implemented yet.');
    }

    /**
     * @return list<class-string>
     */
    public static function middlewareStack(): array
    {
        return self::stack()->all();
    }

    private static function container(): Container
    {
        if (self::$container === null) {
            throw new LogicException('SecurePress has not been bootstrapped. Call Security::bootstrap() from the plugin.');
        }

        return self::$container;
    }

    private static function stack(): MiddlewareStack
    {
        return self::container()->get(MiddlewareStack::class);
    }

    private static function registry(): MiddlewareRegistry
    {
        return self::container()->get(MiddlewareRegistry::class);
    }

    private static function routeGuards(): RouteGuardRegistry
    {
        return self::container()->get(RouteGuardRegistry::class);
    }
}
