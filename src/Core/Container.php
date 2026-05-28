<?php

declare(strict_types=1);

namespace NiyiGuard\Core;

use Closure;
use RuntimeException;

final class Container
{
    /** @var array<string, Closure(self):mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function singleton(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    public function set(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || array_key_exists($id, $this->bindings);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!array_key_exists($id, $this->bindings)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- DI container diagnostic, not rendered in HTML.
            throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
        }

        $instance = ($this->bindings[$id])($this);
        $this->instances[$id] = $instance;

        return $instance;
    }
}
