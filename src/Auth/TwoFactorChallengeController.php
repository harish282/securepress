<?php

declare(strict_types=1);

namespace PressSentinel\Auth;

use PressSentinel\Core\Auth\TwoFactor\PendingChallenge;
use PressSentinel\Core\Auth\TwoFactor\TwoFactorMethod;
use PressSentinel\Core\Auth\TwoFactor\TwoFactorService;
use PressSentinel\Core\Logging\LoggerInterface;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * Handles the `wp-login.php?action=sp_2fa` route — both the GET (form) and POST (verify).
 *
 * Mounted by {@see AuthenticationHardeningKernel} on the `login_form_sp_2fa` action.
 * On a successful verification this controller is the *one place* that calls
 * `wp_set_auth_cookie()`; the rest of the system avoids creating WP sessions until 2FA
 * has cleared, so a stolen password is genuinely useless without the second factor.
 *
 * On every render we also re-check the pending challenge for expiry — a user who left
 * the form open past the TTL is gracefully bounced back to the login page rather than
 * fed a stale challenge.
 */
final class TwoFactorChallengeController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly View $view,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function dispatch(): void
    {
        $method = WpHelper::getRequestMethod();
        $token = $this->stringFromRequest('token');

        if ($token === '') {
            $this->bounceToLogin('Missing 2FA token. Please sign in again.');

            return;
        }

        $challenge = $this->loadChallenge($token);
        if ($challenge === null) {
            $this->bounceToLogin('Your verification window expired. Please sign in again.');

            return;
        }

        if ($method === 'POST') {
            $this->processSubmission($challenge);

            return;
        }

        if ($this->stringFromRequest('resend') === '1') {
            $this->resendOtp($challenge);

            return;
        }

        $this->renderForm($challenge);
    }

    private function processSubmission(PendingChallenge $challenge): void
    {
        $code = trim($this->stringFromRequest('sp_2fa_code', 'POST'));
        $useRecovery = $this->stringFromRequest('sp_2fa_recovery', 'POST') === '1';

        if ($code === '') {
            $this->renderForm($challenge, 'Please enter your verification code.');

            return;
        }

        $verified = false;
        $method = $challenge->method;

        if ($useRecovery) {
            $user = WpHelper::getUserBy('id', $challenge->userId);
            $email = is_object($user) && isset($user->user_email) && is_string($user->user_email) ? $user->user_email : null;
            $name = is_object($user) && isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';
            $verified = $this->twoFactor->consumeRecoveryCode($challenge->userId, $code, $email, $name);
            $method = TwoFactorMethod::RECOVERY;
        } else {
            $verified = $this->twoFactor->verifyChallenge($challenge->token, $code) !== null;
        }

        if (!$verified) {
            $this->logger->warning(sprintf(
                'TwoFactorChallengeController: invalid code for user #%d (method=%s).',
                $challenge->userId,
                $method
            ));
            $this->renderForm($challenge, 'That code was not correct. Please try again.');

            return;
        }

        $this->twoFactor->consumeChallenge($challenge->token);
        WpHelper::setAuthCookie($challenge->userId, $challenge->remember);

        // Synthesise the standard `wp_login` action so every other listener (audit
        // log, kernel session tracking, third-party plugins) sees the same hook they
        // would on a vanilla password-only login. Without this, anything hooked to
        // `wp_login` would silently miss 2FA-completed logins.
        $userObject = WpHelper::getUserBy('id', $challenge->userId);
        if (is_object($userObject)) {
            $login = isset($userObject->user_login) && is_string($userObject->user_login) ? $userObject->user_login : '';
            WpHelper::doAction('wp_login', $login, $userObject);
        }

        $redirect = $challenge->redirectTo !== null && $challenge->redirectTo !== ''
            ? $challenge->redirectTo
            : WpHelper::adminUrl();

        WpHelper::safeRedirect($redirect);
        exit;
    }

    private function resendOtp(PendingChallenge $challenge): void
    {
        if ($challenge->method !== TwoFactorMethod::EMAIL_OTP) {
            $this->renderForm($challenge);

            return;
        }

        $user = WpHelper::getUserBy('id', $challenge->userId);
        $email = is_object($user) && isset($user->user_email) && is_string($user->user_email) ? $user->user_email : '';
        $name = is_object($user) && isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';

        if ($email === '') {
            $this->renderForm($challenge, 'Could not find an email address to resend the code to.');

            return;
        }

        $this->twoFactor->resendChallenge($challenge->token, $email, $name);
        $refreshed = $this->loadChallenge($challenge->token) ?? $challenge;
        $this->renderForm($refreshed, null, 'A new code is on its way to your inbox.');
    }

    private function renderForm(PendingChallenge $challenge, ?string $error = null, ?string $info = null): void
    {
        $loginUrl = WpHelper::loginUrl();

        $context = [
            'token' => $challenge->token,
            'method' => $challenge->method,
            'method_label' => TwoFactorMethod::label($challenge->method),
            'expires_at' => $challenge->expiresAt,
            'remaining_seconds' => max(0, $challenge->expiresAt - time()),
            'redirect_to' => $challenge->redirectTo ?? '',
            'login_url' => $loginUrl,
            'error' => $error,
            'info' => $info,
            'submit_action' => $loginUrl . (str_contains($loginUrl, '?') ? '&' : '?') . 'action=sp_2fa',
        ];

        if (\function_exists('login_header')) {
            \call_user_func('login_header', 'Two-factor authentication');
        }

        $this->view->render('admin.auth.two-factor-challenge', $context);

        if (\function_exists('login_footer')) {
            \call_user_func('login_footer');
        }

        exit;
    }

    private function loadChallenge(string $token): ?PendingChallenge
    {
        return $this->twoFactor->findChallenge($token);
    }

    private function bounceToLogin(string $message): void
    {
        $url = WpHelper::loginUrl() . (str_contains(WpHelper::loginUrl(), '?') ? '&' : '?')
            . 'sp_2fa_error=' . rawurlencode($message);
        WpHelper::safeRedirect($url);
        exit;
    }

    /**
     * @param string $source 'GET'|'POST'|'REQUEST'
     */
    private function stringFromRequest(string $key, string $source = 'REQUEST'): string
    {
        $bag = match ($source) {
            'GET' => $_GET,
            'POST' => $_POST,
            default => $_REQUEST,
        };

        $value = $bag[$key] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return WpHelper::unslash($value);
    }
}
