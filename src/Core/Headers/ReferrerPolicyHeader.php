<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Headers;

/**
 * Controls how much referrer information browsers send on outbound navigations / requests.
 *
 * Default: `strict-origin-when-cross-origin` — sends full URL on same-origin, only the
 * origin when downgrading to less-secure, and nothing on HTTPS→HTTP. This matches the
 * modern browser default and is a good baseline for most WordPress sites.
 */
final class ReferrerPolicyHeader implements HeaderInterface
{
    public const VALID_POLICIES = [
        'no-referrer',
        'no-referrer-when-downgrade',
        'origin',
        'origin-when-cross-origin',
        'same-origin',
        'strict-origin',
        'strict-origin-when-cross-origin',
        'unsafe-url',
    ];

    public const DEFAULT_POLICY = 'strict-origin-when-cross-origin';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $policy = self::DEFAULT_POLICY,
    ) {
    }

    public function name(): string
    {
        return 'Referrer-Policy';
    }

    public function value(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $normalized = strtolower(trim($this->policy));

        return in_array($normalized, self::VALID_POLICIES, true) ? $normalized : self::DEFAULT_POLICY;
    }
}
