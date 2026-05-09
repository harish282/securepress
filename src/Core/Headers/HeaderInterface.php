<?php

declare(strict_types=1);

namespace SecurePress\Core\Headers;

/**
 * Contract for a configurable HTTP security header.
 *
 * Implementations are immutable value objects: they receive their configuration in the
 * constructor, return the canonical wire name from {@see name()}, and the rendered value
 * from {@see value()}. Returning `null` from `value()` means "do not emit this header".
 */
interface HeaderInterface
{
    /**
     * The HTTP header name as it should appear on the wire (e.g. `Strict-Transport-Security`).
     */
    public function name(): string;

    /**
     * The header value to emit, or `null` when the header is disabled / has no value.
     */
    public function value(): ?string;
}
