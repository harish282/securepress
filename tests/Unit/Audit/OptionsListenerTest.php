<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Audit\ArrayAuditLogRepository;
use SecurePress\Core\Audit\AuditLogger;
use SecurePress\Core\Audit\AuditLogQuery;
use SecurePress\Core\Audit\Listeners\OptionsListener;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Tests\Stubs\WpStubState;

final class OptionsListenerTest extends TestCase
{
    private ArrayAuditLogRepository $repo;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->repo = new ArrayAuditLogRepository();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_listener_only_records_options_in_allowlist(): void
    {
        $listener = new OptionsListener(
            new AuditLogger($this->repo, new NullLogger()),
            ['siteurl', 'admin_email']
        );

        $listener->onOptionUpdated('siteurl', 'http://old', 'http://new');
        $listener->onOptionUpdated('cron', 'old', 'new');

        $events = $this->repo->paginate(new AuditLogQuery())->items;
        self::assertCount(1, $events);
        self::assertSame('option.updated', $events[0]->action);
        self::assertSame('siteurl', $events[0]->context['option']);
    }

    public function test_critical_options_are_logged_at_warning_level(): void
    {
        $listener = new OptionsListener(
            new AuditLogger($this->repo, new NullLogger()),
            ['siteurl', 'blogname']
        );

        $listener->onOptionUpdated('siteurl', 'http://old', 'http://new');
        $listener->onOptionUpdated('blogname', 'old', 'new');

        $events = $this->repo->paginate(new AuditLogQuery())->items;
        $byOption = [];
        foreach ($events as $event) {
            $byOption[$event->context['option']] = $event->level;
        }

        self::assertSame('warning', $byOption['siteurl']);
        self::assertSame('notice', $byOption['blogname']);
    }

    public function test_long_string_values_are_truncated(): void
    {
        $listener = new OptionsListener(
            new AuditLogger($this->repo, new NullLogger()),
            ['big_option']
        );

        $longValue = str_repeat('x', 1000);
        $listener->onOptionUpdated('big_option', '', $longValue);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertStringContainsString('…[truncated]', $event->context['new']);
        self::assertLessThan(1000, mb_strlen($event->context['new']));
    }

    public function test_array_values_are_json_encoded_and_truncated(): void
    {
        $listener = new OptionsListener(
            new AuditLogger($this->repo, new NullLogger()),
            ['cfg']
        );

        $listener->onOptionUpdated('cfg', [], ['a' => 'b', 'c' => 'd']);

        $event = $this->repo->paginate(new AuditLogQuery())->items[0];
        self::assertSame('{"a":"b","c":"d"}', $event->context['new']);
    }
}
