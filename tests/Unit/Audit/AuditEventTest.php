<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Audit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Audit\AuditEvent;
use NiyiGuard\Core\Audit\AuditEventCategory;
use NiyiGuard\Core\Audit\AuditEventLevel;

final class AuditEventTest extends TestCase
{
    public function test_make_creates_event_with_normalized_defaults(): void
    {
        $event = AuditEvent::make('  user.login.success  ', '  AUTH  ', '  WARNING  ');

        self::assertSame('user.login.success', $event->action);
        self::assertSame('auth', $event->category);
        self::assertSame('warning', $event->level);
        self::assertNull($event->actorId);
        self::assertNull($event->message);
        self::assertSame([], $event->context);
    }

    public function test_make_rejects_blank_action(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AuditEvent::make('   ');
    }

    public function test_with_methods_return_new_instances(): void
    {
        $event = AuditEvent::make('user.login');
        $modified = $event
            ->withActor(42, 'Alice')
            ->withTarget('user', '42')
            ->withMessage('signed in')
            ->withContext(['ip' => '203.0.113.10'])
            ->withLevel(AuditEventLevel::WARNING)
            ->withCategory(AuditEventCategory::AUTH);

        self::assertNotSame($event, $modified);
        self::assertNull($event->actorId, 'original is unchanged');
        self::assertSame(42, $modified->actorId);
        self::assertSame('Alice', $modified->actorName);
        self::assertSame('user', $modified->targetType);
        self::assertSame('42', $modified->targetId);
        self::assertSame('signed in', $modified->message);
        self::assertSame(['ip' => '203.0.113.10'], $modified->context);
        self::assertSame('warning', $modified->level);
        self::assertSame('auth', $modified->category);
    }

    public function test_merge_context_does_not_replace_unrelated_keys(): void
    {
        $event = AuditEvent::make('user.profile.updated')->withContext(['ip' => '1.2.3.4']);
        $merged = $event->mergeContext(['ua' => 'CurlBot/1.0']);

        self::assertSame(['ip' => '1.2.3.4', 'ua' => 'CurlBot/1.0'], $merged->context);
    }

    public function test_to_row_emits_iso_utc_timestamp_and_truncates_long_strings(): void
    {
        $event = AuditEvent::make('big.event')
            ->withOccurredAt(1700000000)
            ->withContext(['a' => 'b']);

        $row = $event->toRow();

        self::assertSame(gmdate('Y-m-d H:i:s', 1700000000), $row['occurred_at']);
        self::assertSame('{"a":"b"}', $row['context']);

        $eventLong = AuditEvent::make('big.event')->withRequest(
            str_repeat('1', 60),
            str_repeat('U', 400),
            str_repeat('/', 400),
        );
        $rowLong = $eventLong->toRow();

        self::assertSame(45, strlen((string) $rowLong['ip']));
        self::assertSame(255, strlen((string) $rowLong['user_agent']));
        self::assertSame(255, strlen((string) $rowLong['request_uri']));
    }

    public function test_from_row_decodes_json_context_and_normalizes_nullable_columns(): void
    {
        $event = AuditEvent::fromRow([
            'id' => '7',
            'occurred_at' => '2026-05-09 10:00:00',
            'level' => 'warning',
            'category' => 'auth',
            'action' => 'user.login.failed',
            'actor_id' => '0', // 0 means anonymous
            'actor_name' => '',
            'target_type' => 'user',
            'target_id' => 'admin',
            'ip' => '203.0.113.10',
            'user_agent' => null,
            'request_uri' => null,
            'message' => 'attempt blocked',
            'context' => '{"username":"admin","error_code":"invalid_credentials"}',
        ]);

        self::assertSame(7, $event->id);
        self::assertSame('warning', $event->level);
        self::assertSame('user.login.failed', $event->action);
        self::assertNull($event->actorName, 'empty string column reads as null');
        self::assertSame(['username' => 'admin', 'error_code' => 'invalid_credentials'], $event->context);
        self::assertGreaterThan(0, $event->occurredAt);
    }

    public function test_to_row_returns_null_context_when_empty(): void
    {
        $event = AuditEvent::make('foo');
        self::assertNull($event->toRow()['context']);
    }

    public function test_unknown_level_falls_back_to_info(): void
    {
        $event = AuditEvent::make('foo', 'auth', 'totally-made-up');
        self::assertSame(AuditEventLevel::INFO, $event->level);
    }
}
