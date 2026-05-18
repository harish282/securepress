<?php

declare(strict_types=1);

namespace PressSentinel\Core\Auth\TwoFactor;

/**
 * The set of 2FA methods the plugin can enrol a user into.
 *
 * Recovery codes are intentionally NOT a primary method — they're a one-shot fallback for
 * when the primary factor is unavailable (lost phone, no email access). The pending-challenge
 * flow always offers them as a secondary input regardless of which primary method the user
 * enrolled with.
 */
final class TwoFactorMethod
{
    public const TOTP = 'totp';
    public const EMAIL_OTP = 'email_otp';

    public const RECOVERY = 'recovery';

    /**
     * @return list<string>
     */
    public static function primaryMethods(): array
    {
        return [self::TOTP, self::EMAIL_OTP];
    }

    public static function isValid(string $method): bool
    {
        return in_array($method, [self::TOTP, self::EMAIL_OTP, self::RECOVERY], true);
    }

    public static function label(string $method): string
    {
        return match ($method) {
            self::TOTP => 'Authenticator app (TOTP)',
            self::EMAIL_OTP => 'Email one-time code',
            self::RECOVERY => 'Recovery code',
            default => $method,
        };
    }
}
