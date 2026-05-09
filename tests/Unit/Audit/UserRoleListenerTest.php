<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Audit\ArrayAuditLogRepository;
use SecurePress\Core\Audit\AuditLogger;
use SecurePress\Core\Audit\AuditLogQuery;
use SecurePress\Core\Audit\Listeners\UserRoleListener;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Tests\Stubs\WpStubState;

final class UserRoleListenerTest extends TestCase
{
    private ArrayAuditLogRepository $repo;

    private UserRoleListener $listener;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->repo = new ArrayAuditLogRepository();
        $this->listener = new UserRoleListener(new AuditLogger($this->repo, new NullLogger()));
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_user_register_records_at_notice(): void
    {
        $this->listener->onUserRegister(7);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('user.created', $event->action);
        self::assertSame('user', $event->category);
        self::assertSame('notice', $event->level);
        self::assertSame('7', $event->targetId);
    }

    public function test_user_delete_records_at_warning(): void
    {
        $this->listener->onUserDelete(7);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('user.deleted', $event->action);
        self::assertSame('warning', $event->level);
    }

    public function test_role_change_to_administrator_is_warning(): void
    {
        $this->listener->onSetUserRole(5, 'administrator', ['subscriber']);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('user.role.changed', $event->action);
        self::assertSame('role', $event->category);
        self::assertSame('warning', $event->level);
        self::assertSame('administrator', $event->context['new_role']);
        self::assertSame(['subscriber'], $event->context['old_roles']);
    }

    public function test_role_change_from_administrator_is_warning(): void
    {
        $this->listener->onSetUserRole(5, 'subscriber', ['administrator']);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('warning', $event->level, 'demoting an admin must be flagged');
    }

    public function test_role_change_between_low_privilege_roles_is_notice(): void
    {
        $this->listener->onSetUserRole(5, 'editor', ['author']);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('notice', $event->level);
    }

    public function test_super_admin_grant_is_critical(): void
    {
        $this->listener->onGrantSuperAdmin(1);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('user.super_admin.granted', $event->action);
        self::assertSame('critical', $event->level);
    }

    public function test_super_admin_revoke_is_warning(): void
    {
        $this->listener->onRevokeSuperAdmin(1);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('user.super_admin.revoked', $event->action);
        self::assertSame('warning', $event->level);
    }

    public function test_password_reset_records_warning_with_user_target(): void
    {
        $user = (object) ['ID' => 9];

        $this->listener->onPasswordReset($user);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('user.password.reset', $event->action);
        self::assertSame('warning', $event->level);
        self::assertSame('9', $event->targetId);
    }
}
