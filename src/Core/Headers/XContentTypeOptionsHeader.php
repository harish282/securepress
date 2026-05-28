<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Headers;

/**
 * Disables MIME sniffing — browsers honor the declared `Content-Type` instead of guessing.
 *
 * The only valid value is `nosniff`. Safe to enable site-wide; it has essentially no risk
 * of breaking anything provided your server sends correct content types.
 */
final class XContentTypeOptionsHeader implements HeaderInterface
{
    public function __construct(private readonly bool $enabled = true)
    {
    }

    public function name(): string
    {
        return 'X-Content-Type-Options';
    }

    public function value(): ?string
    {
        return $this->enabled ? 'nosniff' : null;
    }
}
