<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\TwoFactor;

/**
 * The "halfway-through-login" record stored after password verification but before 2FA.
 *
 * The lifecycle:
 *  1. WP's `authenticate` filter validates the password and returns a `WP_User`.
 *  2. NiyiGuard's filter at priority 30 sees the user, notices 2FA is enabled, mints
 *     a fresh challenge with a random token, and stores it under {@see ChallengeStoreInterface}.
 *  3. The token is appended to the redirect URL so the user lands on `wp-login.php?action=sp_2fa&token=…`.
 *  4. The 2FA challenge controller looks up the challenge, verifies the submitted code,
 *     and on success deletes the challenge + calls `wp_set_auth_cookie()`.
 *
 * Crucially: at step 2 we do *not* set WP's auth cookie. The user has no logged-in session
 * until 2FA is verified. A stolen password yields nothing without the second factor.
 */
final class PendingChallenge
{
    public function __construct(
        public readonly string $token,
        public readonly int $userId,
        public readonly string $method,
        public readonly int $expiresAt,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $redirectTo = null,
        public readonly bool $remember = false,
        /**
         * Method-specific payload. For email OTP, holds the SHA-256 hash of the issued
         * code; for TOTP, this is null because the secret lives in user_meta and is
         * looked up at verification time.
         */
        public readonly ?string $otpHash = null,
    ) {
    }

    public function withOtp(string $hash, int $expiresAt): self
    {
        return new self(
            $this->token,
            $this->userId,
            $this->method,
            $expiresAt,
            $this->ip,
            $this->userAgent,
            $this->redirectTo,
            $this->remember,
            $hash,
        );
    }

    public function isExpired(?int $now = null): bool
    {
        return $this->expiresAt < ($now ?? time());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'user_id' => $this->userId,
            'method' => $this->method,
            'expires_at' => $this->expiresAt,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'redirect_to' => $this->redirectTo,
            'remember' => $this->remember,
            'otp_hash' => $this->otpHash,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['token'] ?? ''),
            (int) ($row['user_id'] ?? 0),
            (string) ($row['method'] ?? TwoFactorMethod::TOTP),
            (int) ($row['expires_at'] ?? 0),
            isset($row['ip']) && is_string($row['ip']) ? $row['ip'] : null,
            isset($row['user_agent']) && is_string($row['user_agent']) ? $row['user_agent'] : null,
            isset($row['redirect_to']) && is_string($row['redirect_to']) ? $row['redirect_to'] : null,
            (bool) ($row['remember'] ?? false),
            isset($row['otp_hash']) && is_string($row['otp_hash']) && $row['otp_hash'] !== '' ? $row['otp_hash'] : null,
        );
    }
}
