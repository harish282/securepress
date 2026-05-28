<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\TwoFactor;

use NiyiGuard\Core\Auth\Notifications\AuthNotifier;
use NiyiGuard\Core\Logging\LoggerInterface;

/**
 * The single application-level entry point for everything 2FA.
 *
 * Wraps the providers (TOTP, email OTP, recovery), the user-state repository, the
 * pending-challenge store, and the notifier into one cohesive surface that the login
 * controller and admin-UI can call. Keeping the orchestration in one class means that
 * once a flow (e.g., "enable TOTP") works in unit tests we know it works in production
 * too — there's no scattered hook-soup that the tests can't see.
 *
 * Lifecycle of a typical login:
 *  1. Admin / user calls `enableTotp()` or `enableEmailOtp()` once at enrolment.
 *  2. WP authenticate filter calls `requiresChallenge()`; on true it issues
 *     `startChallenge()` and redirects the user to the 2FA form.
 *  3. The form posts back; the controller calls `verifyChallenge()`. On success,
 *     the controller deletes the challenge and sets the auth cookie.
 *  4. If the user ever loses access they call `consumeRecoveryCode()` instead.
 */
final class TwoFactorService
{
    public const CHALLENGE_TTL_SECONDS = 600;

    public function __construct(
        private readonly TwoFactorUserRepositoryInterface $users,
        private readonly ChallengeStoreInterface $challenges,
        private readonly TotpProvider $totp,
        private readonly EmailOtpProvider $emailOtp,
        private readonly RecoveryCodeService $recovery,
        private readonly AuthNotifier $notifier,
        private readonly LoggerInterface $logger,
        private readonly string $issuer = 'NiyiGuard',
        private readonly int $challengeTtlSeconds = self::CHALLENGE_TTL_SECONDS,
    ) {
    }

    public function state(int $userId): TwoFactorState
    {
        return $this->users->find($userId);
    }

    public function requiresChallenge(int $userId): bool
    {
        return $this->users->find($userId)->isEnabled();
    }

    /**
     * Returns a tuple suitable for rendering the TOTP enrolment page.
     *
     * `secret` is the canonical base32 string the user enters into their authenticator
     * (or scans via the `provisioningUri`). The returned secret is stored *temporarily*
     * by the caller (typically in the user's session) and only persisted to the
     * repository when {@see confirmTotp()} verifies their first code.
     *
     * @return array{secret: string, provisioning_uri: string}
     */
    public function beginTotpEnrolment(string $accountLabel): array
    {
        $secret = $this->totp->generateSecret();
        $uri = $this->totp->provisioningUri($this->issuer, $accountLabel, $secret);

        return ['secret' => $secret, 'provisioning_uri' => $uri];
    }

    /**
     * Persists a TOTP secret after the user proves they've enrolled it correctly.
     *
     * Returns the freshly-generated recovery codes (plain text, shown once) on success
     * or `null` when the verification failed. The recovery codes are *also* persisted
     * (as hashes) so the user can fall back to them.
     *
     * @return array{state: TwoFactorState, recovery_codes: list<string>}|null
     */
    public function confirmTotp(int $userId, string $secret, string $code, ?string $email = null, string $userDisplayName = ''): ?array
    {
        if (!$this->totp->verify($secret, $code)) {
            return null;
        }

        $generated = $this->recovery->generate();
        $state = TwoFactorState::enabled(TwoFactorMethod::TOTP, $secret, $generated['hashes'], time());
        $this->users->save($userId, $state);

        if ($email !== null) {
            $this->notifier->sendTwoFactorEnabled($email, $userDisplayName, TwoFactorMethod::TOTP);
        }

        $this->logger->info(sprintf('2FA TOTP enabled for user #%d.', $userId));

        return [
            'state' => $state,
            'recovery_codes' => $generated['plain'],
        ];
    }

    /**
     * Enrols the user into email-OTP 2FA. No code-confirmation step is needed because
     * the very first login challenge sends a fresh OTP to the user's address — if they
     * can't read their inbox we'd block them on first sign-in and the recovery codes
     * are the escape hatch.
     *
     * @return array{state: TwoFactorState, recovery_codes: list<string>}
     */
    public function enableEmailOtp(int $userId, string $email, string $userDisplayName = ''): array
    {
        $generated = $this->recovery->generate();
        $state = TwoFactorState::enabled(TwoFactorMethod::EMAIL_OTP, null, $generated['hashes'], time());
        $this->users->save($userId, $state);

        $this->notifier->sendTwoFactorEnabled($email, $userDisplayName, TwoFactorMethod::EMAIL_OTP);
        $this->logger->info(sprintf('2FA email-OTP enabled for user #%d.', $userId));

        return [
            'state' => $state,
            'recovery_codes' => $generated['plain'],
        ];
    }

    public function disable(int $userId, ?string $email = null, string $userDisplayName = ''): void
    {
        $this->users->save($userId, TwoFactorState::disabled());
        if ($email !== null) {
            $this->notifier->sendTwoFactorDisabled($email, $userDisplayName);
        }
        $this->logger->info(sprintf('2FA disabled for user #%d.', $userId));
    }

    /**
     * Issues a fresh batch of recovery codes for a user who already has 2FA enabled.
     *
     * Returns the plain-text codes — the caller is responsible for showing them once.
     * The previous codes are voided (their hashes are replaced) so an attacker can't
     * use a stolen-and-since-replaced sheet.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(int $userId): array
    {
        $state = $this->users->find($userId);
        if (!$state->isEnabled()) {
            throw new TwoFactorException('Cannot regenerate recovery codes: 2FA is not enabled for this user.');
        }

        $generated = $this->recovery->generate();
        $this->users->save($userId, $state->withRecoveryCodeHashes($generated['hashes']));

        return $generated['plain'];
    }

    /**
     * Starts a pending challenge after a successful password verification.
     *
     * For email_otp this also generates and emails the OTP. The caller embeds the
     * returned token in the redirect URL.
     */
    public function startChallenge(
        int $userId,
        string $email,
        string $userDisplayName = '',
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $redirectTo = null,
        bool $remember = false,
    ): PendingChallenge {
        $state = $this->users->find($userId);
        if (!$state->isEnabled()) {
            throw new TwoFactorException('Cannot start a 2FA challenge for a user who has not enabled it.');
        }

        $token = bin2hex(random_bytes(24));
        $expiresAt = time() + $this->challengeTtlSeconds;
        $challenge = new PendingChallenge(
            $token,
            $userId,
            $state->method ?? TwoFactorMethod::TOTP,
            $expiresAt,
            $ip,
            $userAgent,
            $redirectTo,
            $remember,
        );

        if ($state->method === TwoFactorMethod::EMAIL_OTP) {
            $generated = $this->emailOtp->generate();
            $challenge = $challenge->withOtp($generated['hash'], $generated['expires_at']);
            $this->notifier->sendOtpCode($email, $userDisplayName, $generated['code'], $this->emailOtp->ttlSeconds());
        }

        $this->challenges->put($challenge);

        return $challenge;
    }

    /**
     * Refreshes the OTP for an existing email_otp challenge — used by the "resend"
     * button on the challenge page.
     */
    public function resendChallenge(string $token, string $email, string $userDisplayName = ''): bool
    {
        $challenge = $this->challenges->find($token);
        if ($challenge === null || $challenge->method !== TwoFactorMethod::EMAIL_OTP) {
            return false;
        }

        $generated = $this->emailOtp->generate();
        $refreshed = $challenge->withOtp($generated['hash'], $generated['expires_at']);
        $this->challenges->put($refreshed);
        $this->notifier->sendOtpCode($email, $userDisplayName, $generated['code'], $this->emailOtp->ttlSeconds());

        return true;
    }

    /**
     * Verifies a code submitted on the challenge page.
     *
     * Returns the matching {@see PendingChallenge} on success. The caller MUST then
     * call {@see consumeChallenge()} to delete the challenge before issuing the WP
     * auth cookie — leaving the challenge in place would let an attacker who stole the
     * token replay the same code.
     */
    public function verifyChallenge(string $token, string $code): ?PendingChallenge
    {
        $challenge = $this->challenges->find($token);
        if ($challenge === null) {
            return null;
        }

        $state = $this->users->find($challenge->userId);
        if (!$state->isEnabled()) {
            return null;
        }

        $verified = match ($challenge->method) {
            TwoFactorMethod::TOTP => $state->secret !== null && $this->totp->verify($state->secret, $code),
            TwoFactorMethod::EMAIL_OTP => $challenge->otpHash !== null && $this->emailOtp->verify($code, [
                'hash' => $challenge->otpHash,
                'expires_at' => $challenge->expiresAt,
            ]),
            default => false,
        };

        return $verified ? $challenge : null;
    }

    /**
     * Verifies a recovery code as a fallback for the primary factor.
     *
     * On success the matching hash is removed from the user's stored set, returns
     * `true`. On failure returns `false` and the state is unchanged. The caller is
     * responsible for deleting the challenge and setting the auth cookie.
     */
    public function consumeRecoveryCode(int $userId, string $code, ?string $email = null, string $userDisplayName = ''): bool
    {
        $state = $this->users->find($userId);
        if (!$state->isEnabled() || $state->recoveryCodeHashes === []) {
            return false;
        }

        $remaining = $this->recovery->consume($code, $state->recoveryCodeHashes);
        if ($remaining === null) {
            return false;
        }

        $this->users->save($userId, $state->withRecoveryCodeHashes($remaining));

        if ($email !== null) {
            $this->notifier->sendRecoveryCodeUsed($email, $userDisplayName, count($remaining));
        }

        $this->logger->info(sprintf('Recovery code used for user #%d (%d remaining).', $userId, count($remaining)));

        return true;
    }

    public function consumeChallenge(string $token): void
    {
        $this->challenges->forget($token);
    }

    /**
     * Direct lookup for the controller — distinct from {@see verifyChallenge()}, which
     * also runs code verification. Used by the form-rendering path which needs the
     * challenge metadata before the user has submitted anything.
     */
    public function findChallenge(string $token): ?PendingChallenge
    {
        return $this->challenges->find($token);
    }
}
