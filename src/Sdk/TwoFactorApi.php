<?php

declare(strict_types=1);

namespace NiyiGuard\Sdk;

use NiyiGuard\Core\Auth\TwoFactor\PendingChallenge;
use NiyiGuard\Core\Auth\TwoFactor\TwoFactorMethod;
use NiyiGuard\Core\Auth\TwoFactor\TwoFactorService;
use NiyiGuard\Core\Auth\TwoFactor\TwoFactorState;
use NiyiGuard\Core\Container;

/**
 * Developer-facing surface for everything 2FA.
 *
 * Exposes the otherwise-internal {@see TwoFactorService} through a stable API that other
 * plugins can rely on. Each method is a one-liner pass-through; the value is in the
 * naming and the type stability — third-party code wires against `Security::twoFactor()`
 * and we get to refactor the underlying service freely as long as this surface holds.
 *
 * Typical use:
 *
 * ```php
 * $api = Security::twoFactor();
 * if (!$api->isEnabledFor($user->ID)) {
 *     // Show a "you should enable 2FA" admin notice.
 * }
 * ```
 */
final class TwoFactorApi
{
    public function __construct(private readonly Container $container)
    {
    }

    public function isEnabledFor(int $userId): bool
    {
        return $this->service()->state($userId)->isEnabled();
    }

    public function stateFor(int $userId): TwoFactorState
    {
        return $this->service()->state($userId);
    }

    /**
     * Alias for {@see isEnabledFor()} — preserved because the login kernel and tests speak
     * about *requiring* a challenge while application code asks whether 2FA is *enabled*.
     */
    public function requiresChallenge(int $userId): bool
    {
        return $this->service()->requiresChallenge($userId);
    }

    /**
     * Mints a challenge token for a user who has 2FA enabled — useful when wiring
     * NiyiGuard 2FA into a non-WordPress login flow (e.g., a headless app calling a
     * REST endpoint).
     *
     * For email_otp this also sends the OTP email; for TOTP the user just enters the
     * current code from their authenticator.
     */
    public function challenge(
        int $userId,
        string $email,
        string $displayName = '',
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $redirectTo = null,
        bool $remember = false,
    ): PendingChallenge {
        return $this->service()->startChallenge($userId, $email, $displayName, $ip, $userAgent, $redirectTo, $remember);
    }

    /**
     * Verifies a code against a pending challenge. Returns the verified challenge on
     * success (caller MUST follow up with {@see consume()}), or `null` on mismatch.
     */
    public function verify(string $token, string $code): ?PendingChallenge
    {
        return $this->service()->verifyChallenge($token, $code);
    }

    public function consume(string $token): void
    {
        $this->service()->consumeChallenge($token);
    }

    /**
     * Validates a recovery code as a fallback. Removes the matching hash from the
     * user's stored set on success.
     */
    public function consumeRecoveryCode(int $userId, string $code, ?string $email = null, string $displayName = ''): bool
    {
        return $this->service()->consumeRecoveryCode($userId, $code, $email, $displayName);
    }

    /**
     * @return list<string> Freshly-generated plain recovery codes (shown to the user once).
     */
    public function regenerateRecoveryCodes(int $userId): array
    {
        return $this->service()->regenerateRecoveryCodes($userId);
    }

    public function disable(int $userId, ?string $email = null, string $displayName = ''): void
    {
        $this->service()->disable($userId, $email, $displayName);
    }

    /**
     * @return list<string>
     */
    public function availableMethods(): array
    {
        return TwoFactorMethod::primaryMethods();
    }

    private function service(): TwoFactorService
    {
        return $this->container->get(TwoFactorService::class);
    }
}
