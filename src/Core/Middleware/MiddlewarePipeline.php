<?php

declare(strict_types=1);

namespace PressSentinel\Core\Middleware;

final class MiddlewarePipeline
{
    /**
     * @param array<int, MiddlewareInterface> $middlewares
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function process(array $middlewares, array $context = []): array
    {
        $runner = static fn (array $currentContext): array => $currentContext;

        foreach (array_reverse($middlewares) as $middleware) {
            $next = $runner;
            $runner = static fn (array $currentContext): array => $middleware->handle($currentContext, $next);
        }

        return $runner($context);
    }
}
