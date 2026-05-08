<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Container;
use SecurePress\Core\Middleware\MiddlewareException;
use SecurePress\Core\Middleware\MiddlewareInterface;
use SecurePress\Core\Middleware\MiddlewareManager;
use SecurePress\Core\Middleware\MiddlewarePipeline;
use SecurePress\Core\Middleware\MiddlewareRegistry;

final class MiddlewareManagerTest extends TestCase
{
    public function test_it_resolves_aliases_and_processes_pipeline(): void
    {
        $registry = new MiddlewareRegistry();
        $pipeline = new MiddlewarePipeline();
        $container = new Container();

        $registry->register('append-one', AppendOneMiddleware::class);
        $registry->register('append-two', AppendTwoMiddleware::class);

        $manager = new MiddlewareManager($registry, $pipeline, $container);
        $result = $manager->handle(['append-one', 'append-two'], ['trace' => []]);

        self::assertSame(['one', 'two'], $result['trace']);
    }

    public function test_it_throws_for_unknown_alias(): void
    {
        $manager = new MiddlewareManager(
            new MiddlewareRegistry(),
            new MiddlewarePipeline(),
            new Container()
        );

        $this->expectException(MiddlewareException::class);
        $this->expectExceptionMessage('not registered');

        $manager->handle(['unknown'], []);
    }
}

final class AppendOneMiddleware implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        $context['trace'][] = 'one';

        return $next($context);
    }
}

final class AppendTwoMiddleware implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        $context['trace'][] = 'two';

        return $next($context);
    }
}
