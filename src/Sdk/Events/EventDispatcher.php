<?php

declare(strict_types=1);

namespace NiyiGuard\Sdk\Events;

use NiyiGuard\Core\Support\WpHelper;

/**
 * Tiny in-process event bus for the NiyiGuard SDK.
 *
 * Two reasons we ship our own bus instead of fully delegating to WordPress hooks:
 *
 *  1. **Deterministic tests.** Unit tests run without the WP runtime, so we cannot
 *     rely on `add_action()` / `do_action()` to round-trip listeners. The internal
 *     bus is plain PHP — listeners registered via {@see listen()} are guaranteed to
 *     fire on the matching {@see fire()}.
 *  2. **Namespaced events.** Application code calls e.g. `Security::on('login.failed', …)`
 *     without having to know that the WordPress equivalent would be `niyiguard.login.failed`.
 *     The bus owns the namespace and applies the `niyiguard.` prefix when it bridges
 *     into the WP hook system.
 *
 * Each {@see fire()} also issues a `do_action('niyiguard.<event>', …)` so WordPress
 * plugins that prefer the native hook API can hook into the same stream. The bridge is
 * one-way — listeners registered via WP's `add_action()` are NOT visible to internal
 * fires that happen while the WP runtime is absent (e.g., during unit tests).
 *
 * Listener exceptions are intentionally NOT caught — the dispatcher is dumb. Application
 * code that wants resilient listeners should wrap its own callback in a try/catch. This
 * matches what plugins expect from WordPress's own `do_action`.
 */
final class EventDispatcher
{
    public const HOOK_PREFIX = 'niyiguard.';

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /**
     * Registers a listener for an event.
     *
     * Returns a callable that, when invoked, removes the listener — handy for tests:
     *
     * ```php
     * $off = $dispatcher->listen('login.failed', $fn);
     * // ... assertions ...
     * $off();
     * ```
     *
     * @return callable():void
     */
    public function listen(string $event, callable $listener): callable
    {
        $event = $this->normalize($event);
        if ($event === '') {
            return static function (): void {
            };
        }

        $this->listeners[$event] ??= [];
        $this->listeners[$event][] = $listener;

        $index = array_key_last($this->listeners[$event]);

        return function () use ($event, $index): void {
            if (isset($this->listeners[$event][$index])) {
                unset($this->listeners[$event][$index]);
            }
        };
    }

    public function fire(string $event, mixed ...$args): void
    {
        $event = $this->normalize($event);
        if ($event === '') {
            return;
        }

        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$args);
        }

        WpHelper::doAction(self::HOOK_PREFIX . $event, ...$args);
    }

    public function forget(string $event): void
    {
        unset($this->listeners[$this->normalize($event)]);
    }

    public function forgetAll(): void
    {
        $this->listeners = [];
    }

    /**
     * @return list<callable>
     */
    public function listenersOf(string $event): array
    {
        return array_values($this->listeners[$this->normalize($event)] ?? []);
    }

    /**
     * @return array<string, list<callable>>
     */
    public function all(): array
    {
        return array_map(static fn (array $list): array => array_values($list), $this->listeners);
    }

    private function normalize(string $event): string
    {
        $event = strtolower(trim($event));
        return preg_replace('/[^a-z0-9_.\-]/', '', $event) ?? '';
    }
}
