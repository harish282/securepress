<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Audit\ArrayAuditLogRepository;
use PressSentinel\Core\Audit\AuditLogger;
use PressSentinel\Core\Audit\AuditLogQuery;
use PressSentinel\Core\Audit\Listeners\PluginListener;
use PressSentinel\Core\Logging\NullLogger;
use PressSentinel\Tests\Stubs\WpStubState;

final class PluginListenerTest extends TestCase
{
    private ArrayAuditLogRepository $repo;

    private PluginListener $listener;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->repo = new ArrayAuditLogRepository();
        $this->listener = new PluginListener(new AuditLogger($this->repo, new NullLogger()));
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_activated_records_warning_with_target(): void
    {
        $this->listener->onActivated('akismet/akismet.php');

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('plugin.activated', $event->action);
        self::assertSame('plugin', $event->category);
        self::assertSame('warning', $event->level);
        self::assertSame('plugin', $event->targetType);
        self::assertSame('akismet/akismet.php', $event->targetId);
        self::assertSame(['network_wide' => false], $event->context);
    }

    public function test_deactivated_records_at_notice(): void
    {
        $this->listener->onDeactivated('jetpack/jetpack.php');

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('plugin.deactivated', $event->action);
        self::assertSame('notice', $event->level);
    }

    public function test_deleted_records_only_when_succeeded(): void
    {
        $this->listener->onDeleted('foo/foo.php', false);
        self::assertSame(0, $this->repo->count());

        $this->listener->onDeleted('foo/foo.php', true);
        self::assertSame(1, $this->repo->count());
    }

    public function test_upgrade_complete_records_per_item(): void
    {
        $this->listener->onUpgradeComplete(null, [
            'type' => 'plugin',
            'action' => 'install',
            'plugins' => ['hello-dolly/hello.php', 'akismet/akismet.php'],
        ]);

        $events = $this->repo->paginate(new AuditLogQuery())->items;
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame('plugin.installed', $event->action);
            self::assertSame('warning', $event->level);
            self::assertSame('install', $event->context['action']);
        }
    }

    public function test_upgrade_complete_handles_theme_type(): void
    {
        $this->listener->onUpgradeComplete(null, [
            'type' => 'theme',
            'action' => 'update',
            'themes' => ['twentytwentyfive'],
        ]);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('theme', $event->category);
        self::assertSame('theme.updated', $event->action);
        self::assertSame('twentytwentyfive', $event->targetId);
    }

    public function test_upgrade_complete_ignores_unknown_type(): void
    {
        $this->listener->onUpgradeComplete(null, [
            'type' => 'language',
            'action' => 'install',
            'languages' => ['fr_FR'],
        ]);

        self::assertSame(0, $this->repo->count());
    }

    public function test_theme_switched_records_with_old_and_new_names(): void
    {
        $oldTheme = new class {
            public function get(string $key): string
            {
                return $key === 'Name' ? 'Old Theme' : '';
            }
        };

        $this->listener->onThemeSwitched('twentytwentyfive', null, $oldTheme);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('theme.switched', $event->action);
        self::assertSame('warning', $event->level);
        self::assertSame('Old Theme', $event->context['old_theme']);
        self::assertSame('twentytwentyfive', $event->context['new_theme']);
    }
}
