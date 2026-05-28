<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Audit;

use LogicException;
use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Audit\ArrayAuditLogRepository;
use NiyiGuard\Core\Audit\AuditLogger;
use NiyiGuard\Core\Audit\AuditLoggerInterface;
use NiyiGuard\Core\Audit\AuditLogQuery;
use NiyiGuard\Core\Container;
use NiyiGuard\Core\Logging\NullLogger;
use NiyiGuard\Facades\AuditLog;
use NiyiGuard\Tests\Stubs\WpStubState;

final class AuditLogFacadeTest extends TestCase
{
    private ArrayAuditLogRepository $repo;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->repo = new ArrayAuditLogRepository();

        $container = new Container();
        $container->set(AuditLoggerInterface::class, new AuditLogger($this->repo, new NullLogger()));

        AuditLog::bootstrap($container);
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
        // Reset the static facade to avoid leaking the test container into later test classes.
        $reflection = new \ReflectionClass(AuditLog::class);
        $property = $reflection->getProperty('container');
        $property->setValue(null, null);
    }

    public function test_psr3_helpers_record_through_container_logger(): void
    {
        AuditLog::info('event.info');
        AuditLog::warning('event.warning');
        AuditLog::critical('event.critical');

        self::assertSame(3, $this->repo->count());

        $events = $this->repo->paginate(new AuditLogQuery())->items;
        $byAction = [];
        foreach ($events as $event) {
            $byAction[$event->action] = $event->level;
        }
        self::assertSame('info', $byAction['event.info']);
        self::assertSame('warning', $byAction['event.warning']);
        self::assertSame('critical', $byAction['event.critical']);
    }

    public function test_builder_for_user_records_event_with_actor_and_target(): void
    {
        $user = (object) ['ID' => 9, 'display_name' => 'Charlie'];

        AuditLog::for($user)
            ->category('woocommerce')
            ->action('order.refunded')
            ->target('order', '123')
            ->message('partial refund')
            ->context(['amount' => 12.50])
            ->warning();

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('order.refunded', $event->action);
        self::assertSame('woocommerce', $event->category);
        self::assertSame('warning', $event->level);
        self::assertSame(9, $event->actorId);
        self::assertSame('Charlie', $event->actorName);
        self::assertSame('order', $event->targetType);
        self::assertSame('123', $event->targetId);
        self::assertSame('partial refund', $event->message);
        self::assertSame(['amount' => 12.50], $event->context);
    }

    public function test_builder_accepts_numeric_actor(): void
    {
        AuditLog::for(42)
            ->action('user.profile.updated')
            ->record();

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame(42, $event->actorId);
        self::assertNull($event->actorName);
    }

    public function test_builder_throws_when_action_not_set(): void
    {
        $this->expectException(LogicException::class);
        AuditLog::for(1)->record();
    }

    public function test_builder_for_null_clears_actor(): void
    {
        AuditLog::for(null)
            ->action('cron.fired')
            ->record();

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertNull($event->actorId);
        self::assertNull($event->actorName);
    }

    public function test_unbootstrapped_facade_throws(): void
    {
        $reflection = new \ReflectionClass(AuditLog::class);
        $property = $reflection->getProperty('container');
        $property->setValue(null, null);

        $this->expectException(LogicException::class);
        AuditLog::info('whatever');
    }
}
