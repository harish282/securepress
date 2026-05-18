<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Middleware\MiddlewareInterface;
use PressSentinel\Core\Middleware\MiddlewarePipeline;

final class MiddlewarePipelineTest extends TestCase
{
    public function test_it_executes_middlewares_in_defined_order(): void
    {
        $pipeline = new MiddlewarePipeline();

        $first = new class implements MiddlewareInterface {
            public function handle(array $context, callable $next): array
            {
                $context['trace'][] = 'first:before';
                $response = $next($context);
                $response['trace'][] = 'first:after';

                return $response;
            }
        };

        $second = new class implements MiddlewareInterface {
            public function handle(array $context, callable $next): array
            {
                $context['trace'][] = 'second:before';
                $response = $next($context);
                $response['trace'][] = 'second:after';

                return $response;
            }
        };

        $result = $pipeline->process([$first, $second], ['trace' => []]);

        self::assertSame(
            ['first:before', 'second:before', 'second:after', 'first:after'],
            $result['trace']
        );
    }

    public function test_it_allows_short_circuiting_without_calling_next(): void
    {
        $pipeline = new MiddlewarePipeline();

        $shortCircuit = new class implements MiddlewareInterface {
            public function handle(array $context, callable $next): array
            {
                $context['stopped'] = true;
                $context['trace'][] = 'short-circuit';

                return $context;
            }
        };

        $neverCalled = new class implements MiddlewareInterface {
            public function handle(array $context, callable $next): array
            {
                $context['trace'][] = 'should-not-run';

                return $next($context);
            }
        };

        $result = $pipeline->process([$shortCircuit, $neverCalled], ['trace' => []]);

        self::assertTrue($result['stopped']);
        self::assertSame(['short-circuit'], $result['trace']);
    }
}
