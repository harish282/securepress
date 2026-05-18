<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use PressSentinel\Sdk\Events\EventDispatcher;

/**
 * @see \PressSentinel\Sdk\Events\EventDispatcher
 */
final class EventDispatcherTest extends TestCase
{
    public function test_listeners_run_in_registration_order(): void
    {
        $dispatcher = new EventDispatcher();
        $log = [];

        $dispatcher->listen('login.failed', static function (string $user) use (&$log): void {
            $log[] = 'a:' . $user;
        });
        $dispatcher->listen('login.failed', static function (string $user) use (&$log): void {
            $log[] = 'b:' . $user;
        });

        $dispatcher->fire('login.failed', 'alice');

        self::assertSame(['a:alice', 'b:alice'], $log);
    }

    public function test_listener_receives_variadic_args(): void
    {
        $dispatcher = new EventDispatcher();
        $captured = null;

        $dispatcher->listen('audit.recorded', static function (...$args) use (&$captured): void {
            $captured = $args;
        });

        $dispatcher->fire('audit.recorded', 'user.login', ['ip' => '1.2.3.4'], 200);

        self::assertSame(['user.login', ['ip' => '1.2.3.4'], 200], $captured);
    }

    public function test_unsubscribe_callable_removes_the_listener(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;

        $off = $dispatcher->listen('hit', static function () use (&$calls): void {
            $calls++;
        });

        $dispatcher->fire('hit');
        $off();
        $dispatcher->fire('hit');

        self::assertSame(1, $calls);
    }

    public function test_normalize_strips_invalid_characters_and_lowercases(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;

        $dispatcher->listen('Login.Failed!@#', static function () use (&$calls): void {
            $calls++;
        });

        $dispatcher->fire('login.failed');

        self::assertSame(1, $calls);
    }

    public function test_forget_removes_all_listeners_for_event(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;

        $dispatcher->listen('zap', static function () use (&$calls): void { $calls++; });
        $dispatcher->listen('zap', static function () use (&$calls): void { $calls++; });
        $dispatcher->forget('zap');
        $dispatcher->fire('zap');

        self::assertSame(0, $calls);
    }

    public function test_listenersOf_returns_registered_listeners(): void
    {
        $dispatcher = new EventDispatcher();
        $fn1 = static fn () => null;
        $fn2 = static fn () => null;

        $dispatcher->listen('x', $fn1);
        $dispatcher->listen('x', $fn2);

        self::assertCount(2, $dispatcher->listenersOf('x'));
        self::assertSame([], $dispatcher->listenersOf('unknown'));
    }

    public function test_empty_event_name_is_silently_ignored(): void
    {
        $dispatcher = new EventDispatcher();
        $calls = 0;

        $dispatcher->listen('', static function () use (&$calls): void { $calls++; });
        $dispatcher->fire('');

        self::assertSame(0, $calls);
    }
}
