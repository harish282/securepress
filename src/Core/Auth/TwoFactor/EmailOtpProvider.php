<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\TwoFactor;

/**
 * Generator / verifier for email-delivered one-time codes.
 *
 * In contrast to TOTP, email OTPs aren't time-derived — they're random codes stashed in a
 * short-lived store keyed on the user, and consumed on first match. The store interface is
 * supplied by the caller (typically {@see TransientChallengeStore} in production); this
 * class is just the cipher / formatter and verifier glue.
 *
 * Code length defaults to 6 digits to match the UX of TOTP. TTL defaults to 10 minutes:
 * long enough for a user to switch to their inbox, short enough that a leaked code isn't
 * worth much to an attacker.
 */
final class EmailOtpProvider
{
    public const DEFAULT_LENGTH = 6;
    public const DEFAULT_TTL = 600; // 10 minutes

    public function __construct(
        private readonly int $codeLength = self::DEFAULT_LENGTH,
        private readonly int $ttlSeconds = self::DEFAULT_TTL,
    ) {
    }

    /**
     * Generates a random numeric code and its hash. The hash is what gets stored; the
     * plain code is what gets emailed to the user.
     *
     * Returned shape: `['code' => 'XXXXXX', 'hash' => 'sha256...', 'expires_at' => unix_ts]`.
     *
     * @return array{code: string, hash: string, expires_at: int}
     */
    public function generate(?int $now = null): array
    {
        $now ??= time();
        $code = $this->randomCode();

        return [
            'code' => $code,
            'hash' => $this->hash($code),
            'expires_at' => $now + $this->ttlSeconds,
        ];
    }

    /**
     * Verifies a user-submitted code against a stored entry.
     *
     * The stored entry is the raw array returned by {@see generate()}. The check is
     * timing-safe; expired entries are rejected even if the code matches so that callers
     * can use the same store for both fresh and stale entries without worrying about race
     * conditions in their cleanup logic.
     */
    public function verify(string $submitted, array $stored, ?int $now = null): bool
    {
        $now ??= time();
        if (!isset($stored['hash'], $stored['expires_at'])) {
            return false;
        }
        if ((int) $stored['expires_at'] < $now) {
            return false;
        }

        $clean = preg_replace('/\s+/', '', $submitted) ?? '';

        return hash_equals((string) $stored['hash'], $this->hash($clean));
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    private function randomCode(): string
    {
        $max = (10 ** $this->codeLength) - 1;
        $value = random_int(0, $max);

        return str_pad((string) $value, $this->codeLength, '0', STR_PAD_LEFT);
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
