<?php

declare(strict_types=1);

namespace PressSentinel\Core\Middleware;

use PressSentinel\Core\Container;

final class MiddlewareManager
{
    public function __construct(
        private readonly MiddlewareRegistry $registry,
        private readonly MiddlewarePipeline $pipeline,
        private readonly Container $container
    ) {
    }

    /**
     * @param array<int, string> $aliases
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function handle(array $aliases, array $context = []): array
    {
        $middlewares = [];

        foreach ($aliases as $alias) {
            $class = $this->registry->resolve($alias);

            if ($this->container->has($class)) {
                $instance = $this->container->get($class);
            } else {
                $instance = new $class();
            }

            if (!$instance instanceof MiddlewareInterface) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal middleware resolution error.
                throw new MiddlewareException(sprintf('Resolved middleware "%s" is invalid.', $class));
            }

            $middlewares[] = $instance;
        }

        return $this->pipeline->process($middlewares, $context);
    }
}
