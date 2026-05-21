<?php

declare(strict_types=1);

namespace PressSentinel\Core\Headers;

/**
 * Aggregates configured {@see HeaderInterface} instances and renders them to a wire-ready
 * `[name => value]` map.
 *
 * The registry is intentionally simple: callers register concrete header objects, and
 * {@see emit()} skips any header whose `value()` returns `null` or `''`. Registration order
 * is preserved; duplicate names are emitted last-wins to allow overriding configured headers
 * with per-route variants.
 */
final class HeaderRegistry
{
    /** @var list<HeaderInterface> */
    private array $headers = [];

    public function register(HeaderInterface $header): void
    {
        $this->headers[] = $header;
    }

    /**
     * @return array<string, string>
     */
    public function emit(): array
    {
        $out = [];
        foreach ($this->headers as $header) {
            $value = $header->value();
            if (!is_string($value) || $value === '') {
                continue;
            }
            $out[$header->name()] = $value;
        }

        return $out;
    }

    public function clear(): void
    {
        $this->headers = [];
    }

    public function isEmpty(): bool
    {
        return $this->headers === [];
    }
}
