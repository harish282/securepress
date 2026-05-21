<?php

declare(strict_types=1);

namespace PressSentinel\Core\Headers;

/**
 * Content Security Policy.
 *
 * The most powerful — and most fragile — security header. A misconfigured policy can break
 * the site entirely (admin editor, Gutenberg, theme assets). Default to **disabled** and
 * roll out via `reportOnly = true` first to gather violation reports before enforcing.
 *
 * The policy string is emitted verbatim. Use the standard CSP grammar, semicolon-separated:
 *
 *     default-src 'self'; img-src 'self' data: https:; script-src 'self'
 *
 * When `reportOnly = true` the wire name flips to `Content-Security-Policy-Report-Only` so
 * the browser reports violations without enforcing.
 */
final class CspHeader implements HeaderInterface
{
    public const HEADER_ENFORCE = 'Content-Security-Policy';
    public const HEADER_REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $policy = '',
        private readonly bool $reportOnly = false,
    ) {
    }

    public function name(): string
    {
        return $this->reportOnly ? self::HEADER_REPORT_ONLY : self::HEADER_ENFORCE;
    }

    public function value(): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        $trimmed = trim($this->policy);
        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }
}
