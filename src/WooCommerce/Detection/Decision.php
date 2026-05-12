<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Detection;

/**
 * The result of running a context through a feature pipeline.
 *
 * Three possible outcomes, in increasing order of severity:
 *
 *  - **ACCEPT** — pass-through; the request continues to WooCommerce unchanged.
 *  - **CHALLENGE** — soft block; the caller should require an extra step before
 *    completing (a CAPTCHA, an email confirmation, an OTP). Pipelines emit this when
 *    the score is in the "suspicious but not certain" band and shutting the request
 *    out entirely would carry too high a false-positive cost.
 *  - **DENY** — hard block; the caller should reject the request outright with the
 *    `reason` string. This is what happens when the score crosses the configured
 *    `deny` threshold OR a critical short-circuit middleware (honeypot, etc.) fired.
 *
 * Two contextual fields ride along:
 *  - `$reason` — human-readable string suitable for surfacing to admins. NOT shown to
 *    the user — that would tell attackers exactly what tripped the heuristic.
 *  - `$signals` — the accumulated evidence; logged to the audit trail so support can
 *    reconstruct why a particular order was blocked.
 *
 * Kept as a value object with named-constants outcomes rather than an enum to keep
 * persisted log payloads serialisation-stable across PHP versions and to make the
 * `match` exhaustive checks in `WooCommerceModule` straightforward.
 */
final class Decision
{
    public const ACCEPT = 'accept';
    public const CHALLENGE = 'challenge';
    public const DENY = 'deny';

    /**
     * @param list<Signal> $signals
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $reason = '',
        public readonly array $signals = [],
        public readonly int $score = 0,
    ) {
    }

    public static function accept(int $score = 0, array $signals = []): self
    {
        return new self(self::ACCEPT, '', $signals, $score);
    }

    public static function challenge(string $reason, array $signals = [], int $score = 0): self
    {
        return new self(self::CHALLENGE, $reason, $signals, $score);
    }

    public static function deny(string $reason, array $signals = [], int $score = 0): self
    {
        return new self(self::DENY, $reason, $signals, $score);
    }

    public function isBlocked(): bool
    {
        return $this->outcome === self::DENY;
    }

    public function isAccepted(): bool
    {
        return $this->outcome === self::ACCEPT;
    }
}
