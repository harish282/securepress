<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit\Listeners;

use NiyiGuard\Core\Audit\AuditEvent;
use NiyiGuard\Core\Audit\AuditEventCategory;
use NiyiGuard\Core\Audit\AuditEventLevel;
use NiyiGuard\Core\Audit\AuditLoggerInterface;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Records authentication events: successful logins, failed login attempts, and logouts.
 *
 * Failed logins are recorded at `notice` level by default (a few are normal noise), and
 * deliberately do **not** record the password attempt — only the username/email so the
 * audit trail itself doesn't become a credential-leak vector.
 *
 * `wp_login_failed` fires for *every* failed attempt — high-traffic sites with brute-force
 * traffic can generate thousands of entries per hour. Pair this listener with the
 * RateLimitMiddleware on `wp-login.php` to keep the volume manageable.
 */
final class AuthListener implements ListenerInterface
{
    public function __construct(private readonly AuditLoggerInterface $logger)
    {
    }

    public function register(): void
    {
        WpHelper::addAction('wp_login', [$this, 'onLoginSuccess'], 10, 2);
        WpHelper::addAction('wp_login_failed', [$this, 'onLoginFailed'], 10, 2);
        WpHelper::addAction('wp_logout', [$this, 'onLogout'], 10, 1);
    }

    public function onLoginSuccess(string $userLogin, mixed $user = null): void
    {
        $userId = is_object($user) && isset($user->ID) ? (int) $user->ID : null;
        $name = is_object($user) && isset($user->display_name) && is_string($user->display_name) && $user->display_name !== ''
            ? $user->display_name
            : $userLogin;

        $event = AuditEvent::make('user.login.success', AuditEventCategory::AUTH, AuditEventLevel::INFO)
            ->withActor($userId, $name)
            ->withTarget('user', (string) ($userId ?? $userLogin))
            ->withMessage(sprintf('User "%s" signed in.', $name));

        $this->logger->record($event);
    }

    public function onLoginFailed(string $username, mixed $error = null): void
    {
        $errorCode = null;
        if (is_object($error) && method_exists($error, 'get_error_code')) {
            $code = $error->get_error_code();
            if (is_string($code) && $code !== '') {
                $errorCode = $code;
            }
        }

        $event = AuditEvent::make('user.login.failed', AuditEventCategory::AUTH, AuditEventLevel::NOTICE)
            ->withTarget('user', $username)
            ->withMessage(sprintf('Failed login attempt for "%s".', $username))
            ->withContext([
                'username' => $username,
                'error_code' => $errorCode,
            ]);

        $this->logger->record($event);
    }

    public function onLogout(int $userId = 0): void
    {
        $event = AuditEvent::make('user.logout', AuditEventCategory::AUTH, AuditEventLevel::INFO)
            ->withActor($userId > 0 ? $userId : null)
            ->withMessage('User signed out.');

        $this->logger->record($event);
    }
}
