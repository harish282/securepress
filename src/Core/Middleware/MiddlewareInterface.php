<?php

declare(strict_types=1);

namespace PressSentinel\Core\Middleware;

interface MiddlewareInterface
{
    /**
     * @param array<string, mixed> $context
     * @param callable(array<string, mixed>): array<string, mixed> $next
     * @return array<string, mixed>
     */
    public function handle(array $context, callable $next): array;
}
