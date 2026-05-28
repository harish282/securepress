<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Auth\Notifications;

use NiyiGuard\Core\Logging\LoggerInterface;

/**
 * Renders and dispatches authentication-related emails.
 *
 * Each public method maps to a single user-visible event ("we just sent you a one-time
 * code", "someone enabled 2FA on your account", "we noticed a login from a new device").
 * Bodies are intentionally plain-text — keeps them readable in any client, escapes none
 * of the user's complexity, and avoids the deliverability headaches that come with
 * inlining HTML/CSS without a templating story.
 *
 * The notifier never throws — every method returns a bool indicating whether the
 * underlying mailer accepted the message. Failures are logged at warning level so that
 * non-critical email outages don't break the login flow.
 */
final class AuthNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $siteName,
        private readonly string $siteUrl,
        /**
         * Master killswitch. When false, every public method short-circuits to `false`
         * without invoking the mailer — used by the admin-side "Send security emails"
         * toggle so staging environments don't fire real OTP / lockout emails. The
         * decision is made at construction time so that call-sites stay declarative
         * (no per-method "is notifications on?" checks).
         */
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Sends the email containing the one-time code that completes 2FA.
     */
    public function sendOtpCode(string $to, string $userDisplayName, string $code, int $ttlSeconds): bool
    {
        $minutes = max(1, (int) ceil($ttlSeconds / 60));
        $subject = sprintf('[%s] Your verification code: %s', $this->siteName, $code);

        $body = sprintf(
            "Hi %s,\n\nYour verification code for %s is:\n\n    %s\n\nThis code is valid for %d minute(s). If you didn't request it, please change your password immediately.\n\n— %s\n%s",
            $userDisplayName,
            $this->siteName,
            $code,
            $minutes,
            $this->siteName,
            $this->siteUrl
        );

        return $this->dispatch($to, $subject, $body, 'otp_code');
    }

    public function sendTwoFactorEnabled(string $to, string $userDisplayName, string $method): bool
    {
        $subject = sprintf('[%s] Two-factor authentication enabled', $this->siteName);
        $methodLabel = $method === 'totp' ? 'authenticator app' : 'email one-time code';

        $body = sprintf(
            "Hi %s,\n\nTwo-factor authentication (%s) was just enabled on your account at %s.\n\nIf this wasn't you, contact a site administrator immediately and reset your password — your account credentials may be compromised.\n\n— %s\n%s",
            $userDisplayName,
            $methodLabel,
            $this->siteName,
            $this->siteName,
            $this->siteUrl
        );

        return $this->dispatch($to, $subject, $body, 'two_factor_enabled');
    }

    public function sendTwoFactorDisabled(string $to, string $userDisplayName): bool
    {
        $subject = sprintf('[%s] Two-factor authentication disabled', $this->siteName);

        $body = sprintf(
            "Hi %s,\n\nTwo-factor authentication was just disabled on your account at %s.\n\nIf this wasn't you, contact a site administrator immediately and reset your password — your account credentials may be compromised.\n\n— %s\n%s",
            $userDisplayName,
            $this->siteName,
            $this->siteName,
            $this->siteUrl
        );

        return $this->dispatch($to, $subject, $body, 'two_factor_disabled');
    }

    public function sendRecoveryCodeUsed(string $to, string $userDisplayName, int $remainingCodes): bool
    {
        $subject = sprintf('[%s] Recovery code used', $this->siteName);

        $body = sprintf(
            "Hi %s,\n\nA recovery code was just used to sign in to your %s account. %d code(s) remain.\n\nIf this wasn't you, change your password and regenerate your recovery codes immediately.\n\n— %s\n%s",
            $userDisplayName,
            $this->siteName,
            $remainingCodes,
            $this->siteName,
            $this->siteUrl
        );

        return $this->dispatch($to, $subject, $body, 'recovery_code_used');
    }

    public function sendNewDeviceLogin(string $to, string $userDisplayName, ?string $ip, ?string $userAgent, int $timestamp): bool
    {
        $subject = sprintf('[%s] New device sign-in', $this->siteName);
        $when = gmdate('Y-m-d H:i', $timestamp) . ' UTC';
        $ipLine = $ip ?? 'unknown';
        $uaLine = $userAgent ?? 'unknown';

        $body = sprintf(
            "Hi %s,\n\nWe noticed a sign-in to your %s account from a device or location we don't recognise:\n\n    Time:       %s\n    IP:         %s\n    Browser:    %s\n\nIf this was you, you can ignore this message. If it wasn't, change your password and review your active sessions immediately.\n\n— %s\n%s",
            $userDisplayName,
            $this->siteName,
            $when,
            $ipLine,
            $uaLine,
            $this->siteName,
            $this->siteUrl
        );

        return $this->dispatch($to, $subject, $body, 'new_device_login');
    }

    public function sendAccountLocked(string $to, string $userDisplayName, int $unlockTimestamp, ?string $ip): bool
    {
        $subject = sprintf('[%s] Your account is temporarily locked', $this->siteName);
        $unlockAt = gmdate('Y-m-d H:i', $unlockTimestamp) . ' UTC';
        $ipLine = $ip ?? 'unknown';

        $body = sprintf(
            "Hi %s,\n\nWe've temporarily locked sign-ins to your %s account because of repeated failed login attempts.\n\n    Locked until:        %s\n    Most recent IP:      %s\n\nIf this was you, please wait until the lockout expires or use the password-reset flow. If it wasn't, your account credentials may be under attack — change your password as soon as the lockout expires.\n\n— %s\n%s",
            $userDisplayName,
            $this->siteName,
            $unlockAt,
            $ipLine,
            $this->siteName,
            $this->siteUrl
        );

        return $this->dispatch($to, $subject, $body, 'account_locked');
    }

    private function dispatch(string $to, string $subject, string $body, string $template): bool
    {
        if (!$this->enabled) {
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning(sprintf('AuthNotifier: refusing to send "%s" to invalid address.', $template));

            return false;
        }

        $sent = $this->mailer->send($to, $subject, $body);
        if (!$sent) {
            $this->logger->warning(sprintf('AuthNotifier: mailer rejected "%s" message for %s.', $template, $to));
        }

        return $sent;
    }
}
