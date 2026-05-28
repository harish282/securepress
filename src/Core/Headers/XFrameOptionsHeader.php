<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Headers;

/**
 * Clickjacking protection via `X-Frame-Options`.
 *
 * `DENY` blocks framing entirely; `SAMEORIGIN` permits framing only from same origin.
 * `ALLOW-FROM` is deprecated and intentionally not supported here — use a CSP
 * `frame-ancestors` directive instead.
 *
 * NiyiGuard defaults to `SAMEORIGIN` because some WordPress core flows (preview pages,
 * the customizer) embed admin URLs in iframes from the same origin.
 */
final class XFrameOptionsHeader implements HeaderInterface
{
    public const VALID_VALUES = ['DENY', 'SAMEORIGIN'];

    public const DEFAULT_VALUE = 'SAMEORIGIN';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $value = self::DEFAULT_VALUE,
    ) {
    }

    public function name(): string
    {
        return 'X-Frame-Options';
    }

    public function value(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $normalized = strtoupper(trim($this->value));

        return in_array($normalized, self::VALID_VALUES, true) ? $normalized : self::DEFAULT_VALUE;
    }
}
