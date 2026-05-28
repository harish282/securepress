<?php

declare(strict_types=1);

namespace NiyiGuard\Auth;

use NiyiGuard\Core\Auth\Lockout\LoginLockoutService;
use NiyiGuard\Core\Auth\Notifications\AuthNotifier;
use NiyiGuard\Core\Auth\Sessions\SessionFingerprinter;
use NiyiGuard\Core\Auth\Sessions\SessionService;
use NiyiGuard\Core\Auth\SuspiciousLogin\LoginContext;
use NiyiGuard\Core\Auth\SuspiciousLogin\SuspicionDetector;
use NiyiGuard\Core\Auth\TwoFactor\TwoFactorService;
use NiyiGuard\Core\Logging\LoggerInterface;
use NiyiGuard\Core\Recovery\SafeMode;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Registers all the WordPress hooks that make up the authentication-hardening flow.
 *
 * Pipeline (in WP login order):
 *  1. **Lockout pre-check** (`authenticate` priority 5). If the IP or username is
 *     currently locked, reject before any password math runs — saves CPU and limits
 *     timing leaks.
 *  2. **2FA gate** (`authenticate` priority 30). Runs after WP's own credential check.
 *     If credentials were valid AND the user has 2FA enabled, mint a pending challenge
 *     and redirect to `wp-login.php?action=sp_2fa&token=…` — never returning the
 *     `WP_User` upstream so WP can't set its auth cookie.
 *  3. **Failed-login bookkeeping** (`wp_login_failed`). Increments the lockout counter;
 *     when the threshold is hit, the next attempt for that key is rejected at step 1.
 *  4. **Successful-login bookkeeping** (`wp_login`). Clears the lockout counter, tracks
 *     the session, runs the suspicion detector, sends the new-device email if
 *     warranted. Note this fires *after* the 2FA challenge in the standard flow,
 *     because we route 2FA-verified users through a callback we hand to the controller.
 *  5. **Logout cleanup** (`wp_logout`). Marks the matching session record as revoked.
 *  6. **2FA challenge route** (`login_form_sp_2fa`). Hands control to the controller
 *     which renders / processes the form.
 */
final class AuthenticationHardeningKernel
{
    /**
     * @param array{enabled?:bool,lockout_enabled?:bool,sessions_enabled?:bool,suspicion_enabled?:bool} $flags
     */
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly LoginLockoutService $lockout,
        private readonly SessionService $sessions,
        private readonly SuspicionDetector $suspicion,
        private readonly SessionFingerprinter $fingerprinter,
        private readonly AuthNotifier $notifier,
        private readonly TwoFactorChallengeController $controller,
        private readonly LoggerInterface $logger,
        private readonly array $flags = [],
    ) {
    }

    public function register(): void
    {
        if (!($this->flags['enabled'] ?? true)) {
            return;
        }

        WpHelper::addFilter('authenticate', [$this, 'preCheckLockout'], 5, 3);
        WpHelper::addFilter('authenticate', [$this, 'enforceTwoFactor'], 30, 3);
        WpHelper::addAction('wp_login_failed', [$this, 'onLoginFailed'], 10, 1);
        WpHelper::addAction('wp_login', [$this, 'onLoginSuccess'], 10, 2);
        WpHelper::addAction('wp_logout', [$this, 'onLogout'], 10, 1);
        WpHelper::addAction('login_form_sp_2fa', [$this, 'handleChallengeRoute'], 10, 0);
    }

    /**
     * @param mixed $user
     * @return mixed
     */
    public function preCheckLockout(mixed $user, string $username = '', string $password = ''): mixed
    {
        unset($password);

        if (SafeMode::bypasses(SafeMode::BYPASS_LOCKOUT)) {
            return $user;
        }
        if (!($this->flags['lockout_enabled'] ?? true)) {
            return $user;
        }
        if ($username === '') {
            return $user;
        }

        $ip = WpHelper::getClientIp();
        if ($this->lockout->isLocked($username, $ip)) {
            $unlock = $this->lockout->lockExpiresAt($username, $ip) ?? (time() + $this->lockout->policy()->lockSeconds);
            $remaining = max(1, (int) ceil(($unlock - time()) / 60));

            $this->logger->info(sprintf(
                'AuthHardening: rejecting login attempt for "%s" — locked for %d more minute(s).',
                $username,
                $remaining
            ));

            return $this->wpError('sp_login_locked', sprintf(
                'Too many failed attempts. Try again in about %d minute(s).',
                $remaining
            ));
        }

        return $user;
    }

    /**
     * @param mixed $user
     * @return mixed
     */
    public function enforceTwoFactor(mixed $user, string $username = '', string $password = ''): mixed
    {
        unset($username, $password);

        if (!is_object($user) || !isset($user->ID)) {
            return $user;
        }
        $userId = (int) $user->ID;
        if ($userId <= 0) {
            return $user;
        }
        if (!$this->twoFactor->requiresChallenge($userId)) {
            return $user;
        }

        $email = isset($user->user_email) && is_string($user->user_email) ? $user->user_email : '';
        $name = isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';
        $remember = WpHelper::getPostString('rememberme') === 'forever';
        $redirectRaw = WpHelper::getRequestString('redirect_to', '');
        $redirectTo = $redirectRaw !== '' ? $redirectRaw : null;

        $challenge = $this->twoFactor->startChallenge(
            $userId,
            $email,
            $name,
            WpHelper::getClientIp(),
            WpHelper::userAgent(),
            $redirectTo,
            $remember,
        );

        $url = WpHelper::loginUrl();
        $separator = str_contains($url, '?') ? '&' : '?';
        $target = $url . $separator . 'action=sp_2fa&token=' . rawurlencode($challenge->token);

        WpHelper::safeRedirect($target);
        exit;
    }

    public function onLoginFailed(string $username): void
    {
        if (SafeMode::bypasses(SafeMode::BYPASS_LOCKOUT)) {
            return;
        }
        if (!($this->flags['lockout_enabled'] ?? true)) {
            return;
        }

        $ip = WpHelper::getClientIp();
        $nowLocked = $this->lockout->registerFailure($username, $ip);

        if ($nowLocked) {
            $unlock = $this->lockout->lockExpiresAt($username, $ip) ?? (time() + $this->lockout->policy()->lockSeconds);
            $user = WpHelper::getUserBy('login', $username);
            if (is_object($user) && isset($user->user_email) && is_string($user->user_email)) {
                $name = isset($user->display_name) && is_string($user->display_name) ? $user->display_name : $username;
                $this->notifier->sendAccountLocked($user->user_email, $name, $unlock, $ip);
            }
            $this->logger->warning(sprintf('AuthHardening: locked "%s" until %s (ip=%s).', $username, gmdate('c', $unlock), $ip ?? 'unknown'));
        }
    }

    /**
     * @param mixed $user
     */
    public function onLoginSuccess(string $userLogin, mixed $user = null): void
    {
        unset($userLogin);

        if (!is_object($user) || !isset($user->ID)) {
            return;
        }

        $userId = (int) $user->ID;
        if ($userId <= 0) {
            return;
        }

        $ip = WpHelper::getClientIp();
        $userAgent = WpHelper::userAgent();
        $email = isset($user->user_email) && is_string($user->user_email) ? $user->user_email : '';
        $name = isset($user->display_name) && is_string($user->display_name) ? $user->display_name : '';

        $this->lockout->clear((string) $user->user_login, $ip);

        if (!($this->flags['sessions_enabled'] ?? true)) {
            return;
        }

        $this->finishSuccessfulLogin($userId, $ip, $userAgent, $email, $name);
    }

    public function onLogout(int $userId = 0): void
    {
        if ($userId <= 0) {
            return;
        }
        // When WP logs a user out it doesn't tell us *which* session token; we revoke
        // the most-recently-seen session for that user as a best-effort signal that
        // they walked away. This keeps session UX clean even though it isn't perfect.
        $active = $this->sessions->listActive($userId);
        $latest = $active[0] ?? null;
        if ($latest !== null && $latest->id !== null) {
            $this->sessions->revoke($userId, $latest->id);
        }
    }

    public function handleChallengeRoute(): void
    {
        $this->controller->dispatch();
    }

    /**
     * Performs the post-login bookkeeping shared by both flow paths (vanilla password
     * login and 2FA-completed login). Public so the controller's `wp_login`-trigger
     * route reaches the same code via the standard hook.
     */
    private function finishSuccessfulLogin(int $userId, ?string $ip, ?string $userAgent, string $email = '', string $userDisplayName = ''): void
    {
        $session = $this->sessions->track($userId, $ip, $userAgent);

        if (!($this->flags['suspicion_enabled'] ?? true)) {
            return;
        }

        $context = new LoginContext(
            $userId,
            $this->fingerprinter->fingerprint($ip, $userAgent),
            $ip,
            $userAgent,
            time(),
        );
        $result = $this->suspicion->evaluate($context);

        if (!$result->isSuspicious()) {
            return;
        }

        $this->logger->info(sprintf(
            'AuthHardening: suspicious login for user #%d, score=%d, reasons=%s',
            $userId,
            $result->score,
            implode(',', $result->reasons)
        ));

        if ($email !== '') {
            $this->notifier->sendNewDeviceLogin($email, $userDisplayName, $ip, $userAgent, $session->createdAt);
        }
    }

    /**
     * Build a real `WP_Error` if the class is available, else a duck-typed shim that
     * fulfils WP's expectations (an object whose `is_wp_error` companion returns true).
     * Tests run in the latter mode.
     */
    private function wpError(string $code, string $message): object
    {
        if (\class_exists('WP_Error')) {
            return new \WP_Error($code, $message);
        }

        return (object) ['code' => $code, 'message' => $message, 'is_wp_error' => true];
    }
}
