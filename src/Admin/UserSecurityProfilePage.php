<?php

declare(strict_types=1);

namespace NiyiGuard\Admin;

use NiyiGuard\Core\Auth\Sessions\SessionService;
use NiyiGuard\Core\Auth\TwoFactor\TwoFactorMethod;
use NiyiGuard\Core\Auth\TwoFactor\TwoFactorService;
use NiyiGuard\Core\Logging\LoggerInterface;
use NiyiGuard\Core\Support\WpHelper;
use NiyiGuard\Core\View\View;

/**
 * "Account Security" admin page — the user-facing surface for everything in
 * Authentication Hardening.
 *
 * Why a top-level menu (rather than embedding in `profile.php`):
 *  - The page is meaningful to *every* logged-in user, not just admins, and the
 *    `read` capability is enough to access it. profile.php integration would couple
 *    rendering to capability juggling around `edit_user`/`current_user_can`.
 *  - Separates NiyiGuard UI from WP core profile updates so a 2FA change can't get
 *    interleaved with a password reset in the same form submission.
 *
 * Submission flow: each form posts back to `?page=sp-account-security&action=…&_wpnonce=…`.
 * `register()` declares the menu and admin_post-style handlers; `dispatch()` routes
 * the submitted action to the matching handler.
 */
final class UserSecurityProfilePage
{
    public const SLUG = 'sp-account-security';
    public const NONCE_ACTION = 'sp_account_security';
    public const ENROLMENT_META_KEY = '_niyiguard_2fa_pending_secret';

    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly SessionService $sessions,
        private readonly View $view,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(): void
    {
        if (!\function_exists('add_menu_page')) {
            return;
        }

        \call_user_func(
            'add_menu_page',
            'Account Security',
            'Account Security',
            'read',
            self::SLUG,
            [$this, 'renderPage'],
            'dashicons-shield-alt',
            71
        );
    }

    public function renderPage(): void
    {
        $userId = WpHelper::currentUserId();
        if ($userId <= 0) {
            echo '<div class="wrap"><h1>Account Security</h1><p>Please sign in to manage your account security.</p></div>';

            return;
        }

        $flash = $this->dispatch($userId);
        $state = $this->twoFactor->state($userId);
        $sessions = $this->sessions->listActive($userId);
        $newCodes = $this->popFlash('sp_2fa_recovery_codes');
        $enrolment = $this->popEnrolmentBundle($userId);
        $currentSessionId = $sessions[0]->id ?? null;

        $this->view->render('admin.auth.account-security', [
            'state' => $state,
            'method_label' => $state->method !== null ? TwoFactorMethod::label($state->method) : 'Disabled',
            'sessions' => $sessions,
            'recovery_codes' => $newCodes,
            'enrolment' => $enrolment,
            'flash' => $flash,
            'page_url' => WpHelper::adminUrl('admin.php?page=' . self::SLUG),
            'nonce_action' => self::NONCE_ACTION,
            'nonce' => WpHelper::createNonce(self::NONCE_ACTION),
            'user_id' => $userId,
            'current_session_id' => $currentSessionId,
        ]);
    }

    /**
     * Routes the request's `action` parameter to a handler, returns a flash message
     * tuple `[type, message]` or null when no action ran.
     *
     * @return array{type:string,message:string}|null
     */
    private function dispatch(int $userId): ?array
    {
        $action = WpHelper::getRequestString('action');
        if ($action === '') {
            return null;
        }

        if (!WpHelper::verifyAdminNonce(self::NONCE_ACTION)) {
            return ['type' => 'error', 'message' => 'Security check failed. Please try again.'];
        }

        return match ($action) {
            'sp_2fa_start_totp' => $this->startTotpEnrolment($userId),
            'sp_2fa_confirm_totp' => $this->confirmTotpEnrolment($userId),
            'sp_2fa_enable_email' => $this->enableEmailOtp($userId),
            'sp_2fa_disable' => $this->disableTwoFactor($userId),
            'sp_2fa_regen' => $this->regenerateRecoveryCodes($userId),
            'sp_2fa_session_revoke' => $this->revokeSession($userId),
            'sp_2fa_session_revoke_others' => $this->revokeAllOtherSessions($userId),
            default => null,
        };
    }

    /**
     * @return array{type:string,message:string}
     */
    private function startTotpEnrolment(int $userId): array
    {
        $user = WpHelper::getUserBy('id', $userId);
        $label = is_object($user) && isset($user->user_email) && is_string($user->user_email)
            ? $user->user_email
            : ('user-' . $userId);

        $bundle = $this->twoFactor->beginTotpEnrolment($label);
        WpHelper::updateUserMeta($userId, self::ENROLMENT_META_KEY, $bundle);

        return ['type' => 'info', 'message' => 'Scan the QR code with your authenticator app, then enter a code below.'];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function confirmTotpEnrolment(int $userId): array
    {
        $bundle = WpHelper::getUserMeta($userId, self::ENROLMENT_META_KEY, '');
        if (!is_array($bundle) || !isset($bundle['secret']) || !is_string($bundle['secret'])) {
            return ['type' => 'error', 'message' => 'No pending enrolment found. Please start over.'];
        }

        $code = trim(WpHelper::getPostString('sp_2fa_code'));
        if ($code === '') {
            return ['type' => 'error', 'message' => 'Please enter the code shown in your authenticator app.'];
        }

        $user = WpHelper::getUserBy('id', $userId);
        $email = is_object($user) && isset($user->user_email) && is_string($user->user_email) ? $user->user_email : null;
        $name = is_object($user) && isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';

        $confirmed = $this->twoFactor->confirmTotp($userId, $bundle['secret'], $code, $email, $name);
        if ($confirmed === null) {
            return ['type' => 'error', 'message' => 'That code did not match. Try the next one your app shows.'];
        }

        WpHelper::deleteUserMeta($userId, self::ENROLMENT_META_KEY);
        $this->stashFlash('sp_2fa_recovery_codes', $confirmed['recovery_codes']);

        return ['type' => 'success', 'message' => 'Authenticator-app 2FA is now enabled. Save your recovery codes!'];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function enableEmailOtp(int $userId): array
    {
        $user = WpHelper::getUserBy('id', $userId);
        $email = is_object($user) && isset($user->user_email) && is_string($user->user_email) ? $user->user_email : null;
        if ($email === null) {
            return ['type' => 'error', 'message' => 'We could not find an email address for your account.'];
        }
        $name = is_object($user) && isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';

        $bundle = $this->twoFactor->enableEmailOtp($userId, $email, $name);
        $this->stashFlash('sp_2fa_recovery_codes', $bundle['recovery_codes']);

        return ['type' => 'success', 'message' => 'Email-OTP 2FA is now enabled. Save your recovery codes!'];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function disableTwoFactor(int $userId): array
    {
        $user = WpHelper::getUserBy('id', $userId);
        $email = is_object($user) && isset($user->user_email) && is_string($user->user_email) ? $user->user_email : null;
        $name = is_object($user) && isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';

        $this->twoFactor->disable($userId, $email, $name);
        WpHelper::deleteUserMeta($userId, self::ENROLMENT_META_KEY);

        return ['type' => 'success', 'message' => 'Two-factor authentication is now disabled.'];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function regenerateRecoveryCodes(int $userId): array
    {
        try {
            $codes = $this->twoFactor->regenerateRecoveryCodes($userId);
        } catch (\Throwable $exception) {
            $this->logger->warning('UserSecurityProfilePage: ' . $exception->getMessage());

            return ['type' => 'error', 'message' => $exception->getMessage()];
        }

        $this->stashFlash('sp_2fa_recovery_codes', $codes);

        return ['type' => 'success', 'message' => 'Fresh recovery codes generated. Save them below.'];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function revokeSession(int $userId): array
    {
        $sessionId = max(0, (int) WpHelper::getPostString('session_id', '0'));
        if ($sessionId <= 0) {
            return ['type' => 'error', 'message' => 'Invalid session reference.'];
        }
        $revoked = $this->sessions->revoke($userId, $sessionId);

        return $revoked
            ? ['type' => 'success', 'message' => 'Session revoked.']
            : ['type' => 'error', 'message' => 'That session is no longer active.'];
    }

    /**
     * @return array{type:string,message:string}
     */
    private function revokeAllOtherSessions(int $userId): array
    {
        $excludeRaw = WpHelper::getPostString('current_session_id', '');
        $excludeId = is_numeric($excludeRaw) ? (int) $excludeRaw : null;
        $count = $this->sessions->revokeAllExceptCurrent($userId, $excludeId);

        return ['type' => 'success', 'message' => sprintf('%d other session(s) revoked.', $count)];
    }

    /**
     * Reads the pending TOTP enrolment bundle (if any) from user_meta.
     *
     * @return array{secret:string, provisioning_uri:string}|null
     */
    private function popEnrolmentBundle(int $userId): ?array
    {
        $bundle = WpHelper::getUserMeta($userId, self::ENROLMENT_META_KEY, '');
        if (!is_array($bundle) || !isset($bundle['secret'], $bundle['provisioning_uri'])) {
            return null;
        }

        return [
            'secret' => (string) $bundle['secret'],
            'provisioning_uri' => (string) $bundle['provisioning_uri'],
        ];
    }

    /**
     * @param list<string> $codes
     */
    private function stashFlash(string $key, array $codes): void
    {
        WpHelper::setTransient($this->flashKey($key), $codes, 300);
    }

    /**
     * @return list<string>|null
     */
    private function popFlash(string $key): ?array
    {
        $name = $this->flashKey($key);
        $codes = WpHelper::getTransient($name);
        if (!is_array($codes)) {
            return null;
        }
        WpHelper::deleteTransient($name);

        return array_values(array_filter($codes, 'is_string'));
    }

    private function flashKey(string $key): string
    {
        return $key . '_' . WpHelper::currentUserId();
    }
}
