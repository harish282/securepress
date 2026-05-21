<?php

declare(strict_types=1);

namespace PressSentinel\Core\Middleware;

final class MiddlewareRegistry
{
    /** @var array<string, class-string<MiddlewareInterface>> */
    private array $aliases = [];

    /**
     * @param class-string<MiddlewareInterface> $middlewareClass
     */
    public function register(string $alias, string $middlewareClass): void
    {
        if (!is_subclass_of($middlewareClass, MiddlewareInterface::class)) {
            throw new MiddlewareException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal middleware registration error.
                sprintf('Middleware "%s" must implement %s.', $middlewareClass, MiddlewareInterface::class)
            );
        }

        $this->aliases[$alias] = $middlewareClass;
    }

    /**
     * @return class-string<MiddlewareInterface>
     */
    public function resolve(string $alias): string
    {
        if (!isset($this->aliases[$alias])) {
            throw new MiddlewareException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal middleware registration error.
                sprintf('Middleware alias "%s" is not registered.', $alias)
            );
        }

        return $this->aliases[$alias];
    }
}
