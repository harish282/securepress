<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Headers;

use Closure;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Emits configured security headers on every WordPress response.
 *
 * The dispatcher hooks into the `send_headers` action with priority `1` so it runs before
 * most other plugins / themes that may also want to set headers. The actual emission can be
 * intercepted via the `$emit` callable for tests; in production it shells out to PHP's
 * native {@see header()} guarded by {@see headers_sent()} so we never double-emit or
 * stomp on output that has already been flushed.
 *
 * The registry is rebuilt on each call rather than cached on the instance, so toggling a
 * setting in the admin UI takes effect on the very next request without a cache flush.
 */
final class SecurityHeadersDispatcher
{
    /** @var Closure(string, string): void */
    private readonly Closure $emit;

    /**
     * @param (Closure(string, string): void)|null $emit Header emitter override (tests).
     */
    public function __construct(
        private readonly HeaderRegistryFactory $factory,
        ?Closure $emit = null,
    ) {
        $this->emit = $emit ?? static function (string $name, string $value): void {
            if (\headers_sent()) {
                return;
            }
            \header($name . ': ' . $value);
        };
    }

    public function register(): void
    {
        WpHelper::addAction('send_headers', [$this, 'send'], 1);
    }

    public function send(): void
    {
        foreach ($this->factory->make()->emit() as $name => $value) {
            ($this->emit)($name, $value);
        }
    }
}
