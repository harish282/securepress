<?php

declare(strict_types=1);

namespace PressSentinel\Core\Audit\Listeners;

use PressSentinel\Core\Audit\AuditEvent;
use PressSentinel\Core\Audit\AuditEventCategory;
use PressSentinel\Core\Audit\AuditEventLevel;
use PressSentinel\Core\Audit\AuditLoggerInterface;
use PressSentinel\Core\Support\WpHelper;

/**
 * Records user lifecycle events and role changes.
 *
 * Privilege escalation is the most security-relevant signal in this category: any change
 * involving the `administrator` role is recorded at `warning` level and other role changes
 * at `notice`. New user registrations and deletions are always logged so account churn
 * is auditable.
 */
final class UserRoleListener implements ListenerInterface
{
    private const HIGH_PRIVILEGE_ROLES = ['administrator', 'super_admin'];

    public function __construct(private readonly AuditLoggerInterface $logger)
    {
    }

    public function register(): void
    {
        WpHelper::addAction('user_register', [$this, 'onUserRegister'], 10, 1);
        WpHelper::addAction('delete_user', [$this, 'onUserDelete'], 10, 1);
        WpHelper::addAction('set_user_role', [$this, 'onSetUserRole'], 10, 3);
        WpHelper::addAction('add_user_role', [$this, 'onAddUserRole'], 10, 2);
        WpHelper::addAction('remove_user_role', [$this, 'onRemoveUserRole'], 10, 2);
        WpHelper::addAction('grant_super_admin', [$this, 'onGrantSuperAdmin'], 10, 1);
        WpHelper::addAction('revoke_super_admin', [$this, 'onRevokeSuperAdmin'], 10, 1);
        WpHelper::addAction('password_reset', [$this, 'onPasswordReset'], 10, 1);
    }

    public function onUserRegister(int $userId): void
    {
        $event = AuditEvent::make('user.created', AuditEventCategory::USER, AuditEventLevel::NOTICE)
            ->withTarget('user', (string) $userId)
            ->withMessage(sprintf('New user registered (#%d).', $userId));

        $this->logger->record($event);
    }

    public function onUserDelete(int $userId): void
    {
        $event = AuditEvent::make('user.deleted', AuditEventCategory::USER, AuditEventLevel::WARNING)
            ->withTarget('user', (string) $userId)
            ->withMessage(sprintf('User deleted (#%d).', $userId));

        $this->logger->record($event);
    }

    /**
     * @param array<int, string>|null $oldRoles
     */
    public function onSetUserRole(int $userId, string $role, ?array $oldRoles = null): void
    {
        $level = $this->roleChangeLevel($role, $oldRoles);

        $event = AuditEvent::make('user.role.changed', AuditEventCategory::ROLE, $level)
            ->withTarget('user', (string) $userId)
            ->withMessage(sprintf(
                'User #%d role set to "%s" (was: %s).',
                $userId,
                $role,
                $oldRoles === null || $oldRoles === [] ? 'none' : implode(',', $oldRoles)
            ))
            ->withContext([
                'new_role' => $role,
                'old_roles' => $oldRoles ?? [],
            ]);

        $this->logger->record($event);
    }

    public function onAddUserRole(int $userId, string $role): void
    {
        $level = $this->roleChangeLevel($role, []);

        $event = AuditEvent::make('user.role.added', AuditEventCategory::ROLE, $level)
            ->withTarget('user', (string) $userId)
            ->withMessage(sprintf('Role "%s" added to user #%d.', $role, $userId))
            ->withContext(['role' => $role]);

        $this->logger->record($event);
    }

    public function onRemoveUserRole(int $userId, string $role): void
    {
        $event = AuditEvent::make('user.role.removed', AuditEventCategory::ROLE, AuditEventLevel::NOTICE)
            ->withTarget('user', (string) $userId)
            ->withMessage(sprintf('Role "%s" removed from user #%d.', $role, $userId))
            ->withContext(['role' => $role]);

        $this->logger->record($event);
    }

    public function onGrantSuperAdmin(int $userId): void
    {
        $event = AuditEvent::make('user.super_admin.granted', AuditEventCategory::ROLE, AuditEventLevel::CRITICAL)
            ->withTarget('user', (string) $userId)
            ->withMessage(sprintf('Super admin privileges granted to user #%d.', $userId));

        $this->logger->record($event);
    }

    public function onRevokeSuperAdmin(int $userId): void
    {
        $event = AuditEvent::make('user.super_admin.revoked', AuditEventCategory::ROLE, AuditEventLevel::WARNING)
            ->withTarget('user', (string) $userId)
            ->withMessage(sprintf('Super admin privileges revoked from user #%d.', $userId));

        $this->logger->record($event);
    }

    public function onPasswordReset(mixed $user = null): void
    {
        $userId = is_object($user) && isset($user->ID) ? (int) $user->ID : null;

        $event = AuditEvent::make('user.password.reset', AuditEventCategory::USER, AuditEventLevel::WARNING)
            ->withTarget('user', $userId === null ? 'unknown' : (string) $userId)
            ->withMessage('User password was reset.');

        $this->logger->record($event);
    }

    /**
     * @param array<int, string>|null $oldRoles
     */
    private function roleChangeLevel(string $newRole, ?array $oldRoles): string
    {
        $oldRoles ??= [];

        $touchesPrivileged = in_array($newRole, self::HIGH_PRIVILEGE_ROLES, true)
            || array_intersect($oldRoles, self::HIGH_PRIVILEGE_ROLES) !== [];

        return $touchesPrivileged ? AuditEventLevel::WARNING : AuditEventLevel::NOTICE;
    }
}
