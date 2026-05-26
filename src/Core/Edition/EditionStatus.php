<?php

declare(strict_types=1);

namespace PressSentinel\Core\Edition;

/**
 * Edition state for the free, fully unlocked PressSentinel distribution.
 *
 * Third-party code that still calls {@see \PressSentinel\Facades\Security::licenseStatus()}
 * receives this shape. Paid licensing lives in the optional
 * `packages/press-sentinel-licensing` add-on.
 */
final class EditionStatus
{
    public const STATE_FREE = 'free';

    public function __construct(
        public readonly string $state = self::STATE_FREE,
        public readonly string $tier = 'free',
        public readonly ?int $expiresAt = null,
        public readonly string $reason = '',
    ) {
    }

    public static function free(): self
    {
        return new self(
            self::STATE_FREE,
            'free',
            null,
            'PressSentinel is distributed as a free plugin. All features are included.',
        );
    }

    public function isActive(): bool
    {
        return true;
    }

    public function hasProAccess(): bool
    {
        return true;
    }

    public function daysRemaining(?int $now = null): ?int
    {
        return null;
    }
}
