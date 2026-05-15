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
 * stringly-typed comparisons. Anything that is not {@see LicenseStatus::hasProAccess()}
 * is treated as "Pro disabled" by feature gates. Paid keys use {@see STATE_ACTIVE};
 * early-access builds use {@see STATE_EARLY_ACCESS}; public beta uses {@see STATE_BETA_TRIAL}.
 */
final class LicenseStatus
{
    public const STATE_ACTIVE = 'active';
    public const STATE_EXPIRED = 'expired';
    public const STATE_INVALID = 'invalid';
    public const STATE_NONE = 'none';

    /** Pro unlocked without a paid key or trial clock (pre-commercial beta). */
    public const STATE_EARLY_ACCESS = 'early_access';

    /** Time-boxed Pro access without a paid key (public beta programme). */
    public const STATE_BETA_TRIAL = 'beta_trial';

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

    /**
     * Pro feature gates should use this instead of {@see isActive()} alone.
     * Beta trial grants the same product capabilities as an active paid Pro
     * license until {@see $expiresAt} (trial window end, UTC unix).
     */
    public static function betaTrial(int $expiresAtUnix): self
    {
        return new self(
            self::STATE_BETA_TRIAL,
            'pro',
            $expiresAtUnix,
            'Pro features are unlocked during the beta trial. Add a license key before the trial ends to keep access.',
            null,
        );
    }

    public static function earlyAccess(): self
    {
        return new self(
            self::STATE_EARLY_ACCESS,
            'pro',
            null,
            'Early access: Pro features are unlocked without a commercial license key.',
            null,
        );
    }

    /**
     * True only for a cryptographically validated paid (or perpetual) license.
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    /**
     * True when this install should behave as Pro: paid active license, early
     * access, or an in-window public beta trial.
     */
    public function hasProAccess(): bool
    {
        return $this->state === self::STATE_ACTIVE
            || $this->state === self::STATE_EARLY_ACCESS
            || $this->state === self::STATE_BETA_TRIAL;
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
