<?php

declare(strict_types=1);

namespace SecurePress\Core\Auth\Notifications;

use SecurePress\Core\Logging\LoggerInterface;

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

        $body = <<<TXT
Hi {$userDisplayName},

Your verification code for {$this->siteName} is:

    {$code}

This code is valid for {$minutes} minute(s). If you didn't request it, please change your password immediately.

— {$this->siteName}
{$this->siteUrl}
TXT;

        return $this->dispatch($to, $subject, $body, 'otp_code');
    }

    public function sendTwoFactorEnabled(string $to, string $userDisplayName, string $method): bool
    {
        $subject = sprintf('[%s] Two-factor authentication enabled', $this->siteName);
        $methodLabel = $method === 'totp' ? 'authenticator app' : 'email one-time code';

        $body = <<<TXT
Hi {$userDisplayName},

Two-factor authentication ({$methodLabel}) was just enabled on your account at {$this->siteName}.

If this wasn't you, contact a site administrator immediately and reset your password — your account credentials may be compromised.

— {$this->siteName}
{$this->siteUrl}
TXT;

        return $this->dispatch($to, $subject, $body, 'two_factor_enabled');
    }

    public function sendTwoFactorDisabled(string $to, string $userDisplayName): bool
    {
        $subject = sprintf('[%s] Two-factor authentication disabled', $this->siteName);

        $body = <<<TXT
Hi {$userDisplayName},

Two-factor authentication was just disabled on your account at {$this->siteName}.

If this wasn't you, contact a site administrator immediately and reset your password — your account credentials may be compromised.

— {$this->siteName}
{$this->siteUrl}
TXT;

        return $this->dispatch($to, $subject, $body, 'two_factor_disabled');
    }

    public function sendRecoveryCodeUsed(string $to, string $userDisplayName, int $remainingCodes): bool
    {
        $subject = sprintf('[%s] Recovery code used', $this->siteName);

        $body = <<<TXT
Hi {$userDisplayName},

A recovery code was just used to sign in to your {$this->siteName} account. {$remainingCodes} code(s) remain.

If this wasn't you, change your password and regenerate your recovery codes immediately.

— {$this->siteName}
{$this->siteUrl}
TXT;

        return $this->dispatch($to, $subject, $body, 'recovery_code_used');
    }

    public function sendNewDeviceLogin(string $to, string $userDisplayName, ?string $ip, ?string $userAgent, int $timestamp): bool
    {
        $subject = sprintf('[%s] New device sign-in', $this->siteName);
        $when = gmdate('Y-m-d H:i', $timestamp) . ' UTC';
        $ipLine = $ip ?? 'unknown';
        $uaLine = $userAgent ?? 'unknown';

        $body = <<<TXT
Hi {$userDisplayName},

We noticed a sign-in to your {$this->siteName} account from a device or location we don't recognise:

    Time:       {$when}
    IP:         {$ipLine}
    Browser:    {$uaLine}

If this was you, you can ignore this message. If it wasn't, change your password and review your active sessions immediately.

— {$this->siteName}
{$this->siteUrl}
TXT;

        return $this->dispatch($to, $subject, $body, 'new_device_login');
    }

    public function sendAccountLocked(string $to, string $userDisplayName, int $unlockTimestamp, ?string $ip): bool
    {
        $subject = sprintf('[%s] Your account is temporarily locked', $this->siteName);
        $unlockAt = gmdate('Y-m-d H:i', $unlockTimestamp) . ' UTC';
        $ipLine = $ip ?? 'unknown';

        $body = <<<TXT
Hi {$userDisplayName},

We've temporarily locked sign-ins to your {$this->siteName} account because of repeated failed login attempts.

    Locked until:        {$unlockAt}
    Most recent IP:      {$ipLine}

If this was you, please wait until the lockout expires or use the password-reset flow. If it wasn't, your account credentials may be under attack — change your password as soon as the lockout expires.

— {$this->siteName}
{$this->siteUrl}
TXT;

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
