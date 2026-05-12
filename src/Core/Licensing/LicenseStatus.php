<?php

declare(strict_types=1);

namespace SecurePress\Core\Licensing;

/**
 * Outcome of a single license-key validation attempt.
 *
 * Returned by {@see LicenseValidatorInterface::validate()} and surfaced to the rest of
 * the plugin through {@see LicenseManager::status()}. The value is intentionally small
 * (state + tier + expiry + reason) — it stays cheap to cache and to serialise into the
 * admin UI without any further transformation.
 *
 * `state` follows a small fixed vocabulary so callers can `match` on it rather than
 * stringly-typed comparisons. The vocabulary is deliberately conservative — anything
 * not actively "active" falls into one of the inactive states and is treated as
 * "Pro disabled" by the boot logic. We don't ship a "grace period" state to keep the
 * security promises simple: either the install is licensed right now or it is not.
 */
final class LicenseStatus
{
    public const STATE_ACTIVE = 'active';
    public const STATE_EXPIRED = 'expired';
    public const STATE_INVALID = 'invalid';
    public const STATE_NONE = 'none';

    public function __construct(
        public readonly string $state,
        public readonly string $tier = 'free',
        public readonly ?int $expiresAt = null,
        public readonly string $reason = '',
        public readonly ?string $maskedKey = null,
    ) {
    }

    public static function none(): self
    {
        return new self(self::STATE_NONE, 'free', null, 'No license key configured.');
    }

    public static function invalid(string $reason, ?string $maskedKey = null): self
    {
        return new self(self::STATE_INVALID, 'free', null, $reason, $maskedKey);
    }

    public static function expired(string $tier, int $expiresAt, ?string $maskedKey = null): self
    {
        return new self(self::STATE_EXPIRED, $tier, $expiresAt, 'License has expired.', $maskedKey);
    }

    public static function active(string $tier, ?int $expiresAt, ?string $maskedKey = null): self
    {
        return new self(self::STATE_ACTIVE, $tier, $expiresAt, '', $maskedKey);
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function daysRemaining(?int $now = null): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }
        $now ??= time();

        return (int) floor(max(0, $this->expiresAt - $now) / 86400);
    }
}
