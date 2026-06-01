<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Audit\ArrayAuditLogRepository;
use NiyiGuard\Core\Audit\AuditLogger;
use NiyiGuard\Core\Audit\AuditLogQuery;
use NiyiGuard\Core\Audit\Listeners\AuthListener;
use NiyiGuard\Core\Logging\NullLogger;
use NiyiGuard\Tests\Stubs\WpStubState;

final class AuthListenerTest extends TestCase
{
    private ArrayAuditLogRepository $repo;

    private AuthListener $listener;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->repo = new ArrayAuditLogRepository();
        $this->listener = new AuthListener(new AuditLogger($this->repo, new NullLogger()));
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_login_success_records_at_info_with_actor(): void
    {
        $user = (object) ['ID' => 12, 'display_name' => 'Bob Builder'];

        $this->listener->onLoginSuccess('bob', $user);

        $events = $this->repo->paginate(new AuditLogQuery())->items;
        self::assertCount(1, $events);
        self::assertSame('user.login.success', $events[0]->action);
        self::assertSame('auth', $events[0]->category);
        self::assertSame('info', $events[0]->level);
        self::assertSame(12, $events[0]->actorId);
        self::assertSame('Bob Builder', $events[0]->actorName);
        self::assertSame('user', $events[0]->targetType);
        self::assertSame('12', $events[0]->targetId);
    }

    public function test_login_success_falls_back_to_login_when_display_name_missing(): void
    {
        $user = (object) ['ID' => 1, 'display_name' => ''];

        $this->listener->onLoginSuccess('alice', $user);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('alice', $event->actorName);
    }

    public function test_login_failed_records_at_notice_without_actor(): void
    {
        $error = new class {
            public function get_error_code(): string
            {
                return 'invalid_username';
            }
        };

        $this->listener->onLoginFailed('hacker', $error);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('user.login.failed', $event->action);
        self::assertSame('notice', $event->level);
        self::assertNull($event->actorId);
        self::assertSame('hacker', $event->targetId);
        self::assertSame('hacker', $event->context['username']);
        self::assertSame('invalid_username', $event->context['error_code']);
    }

    public function test_login_failed_does_not_store_password_attempt(): void
    {
        // Even if a malicious caller passes the password as the 'username' arg, we only
        // capture the value passed by WordPress core which is `$username`. We ensure no
        // other source of the credential leaks into context.
        $this->listener->onLoginFailed('admin@example.com', null);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];

        self::assertSame(
            ['username', 'error_code'],
            array_keys($event->context),
            'context must only contain username + error_code, never password fields'
        );
    }

    public function test_logout_records_with_actor(): void
    {
        $this->listener->onLogout(42);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];

        self::assertSame('user.logout', $event->action);
        self::assertSame(42, $event->actorId);
    }
}
