<?php

declare(strict_types=1);

namespace SecurePress\Core\Middleware;

/**
 * Ordered list of global middleware class names.
 *
 * Aliases/classes from Security::middleware() are queued here before the HTTP/kernel layer runs them.
 */
final class MiddlewareStack
{
    /** @var list<class-string> */
    private array $middleware = [];

    /**
     * @param array<int, class-string> $middleware
     */
    public function push(array $middleware): void
    {
        foreach ($middleware as $class) {
            if (!is_string($class) || $class === '') {
                throw new MiddlewareException('Middleware class name must be a non-empty string.');
            }
            $this->middleware[] = $class;
        }
    }

    /**
     * @return list<class-string>
     */
    public function all(): array
    {
        return $this->middleware;
    }

    /**
     * @return list<class-string>
     */
    public function aliases(): array
    {
        $unique = [];
        foreach ($this->middleware as $class) {
            $unique[$class] = true;
        }

        return array_keys($unique);
    }

    public function clear(): void
    {
        $this->middleware = [];
    }
}
