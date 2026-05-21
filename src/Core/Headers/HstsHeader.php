<?php

declare(strict_types=1);

namespace PressSentinel\Core\Headers;

/**
 * HTTP Strict Transport Security.
 *
 * Tells browsers to only ever load this origin over HTTPS for `max-age` seconds.
 *
 * **Caution.** Once a browser has cached an HSTS policy it will refuse to load HTTP for
 * `max-age` seconds even if you remove the header. Enabling `preload` opts the domain into
 * the browser-shipped HSTS preload list; that is *permanent and effectively irreversible*.
 *
 * Sensible defaults:
 *  - `enabled = false` (opt-in — you must verify HTTPS works site-wide first)
 *  - `maxAge = 31536000` (1 year) when enabled
 *  - `includeSubDomains = false` (turn on only after auditing every subdomain)
 *  - `preload = false` (only after submitting to https://hstspreload.org)
 */
final class HstsHeader implements HeaderInterface
{
    public function __construct(
        private readonly bool $enabled,
        private readonly int $maxAge = 31_536_000,
        private readonly bool $includeSubDomains = false,
        private readonly bool $preload = false,
    ) {
    }

    public function name(): string
    {
        return 'Strict-Transport-Security';
    }

    public function value(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $parts = ['max-age=' . max(0, $this->maxAge)];
        if ($this->includeSubDomains) {
            $parts[] = 'includeSubDomains';
        }
        if ($this->preload) {
            $parts[] = 'preload';
        }

        return implode('; ', $parts);
    }
}
