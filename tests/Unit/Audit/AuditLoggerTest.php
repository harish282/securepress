<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Audit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SecurePress\Core\Audit\ArrayAuditLogRepository;
use SecurePress\Core\Audit\AuditEvent;
use SecurePress\Core\Audit\AuditEventLevel;
use SecurePress\Core\Audit\AuditLogger;
use SecurePress\Core\Audit\AuditLogRepositoryInterface;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Tests\Stubs\WpStubState;

final class AuditLoggerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->serverBackup = $_SERVER;
        $_SERVER = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'SecurePressTest/1.0',
            'REQUEST_URI' => '/wp-admin/options-general.php',
        ];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WpStubState::reset();
    }

    public function test_record_enriches_with_current_user_and_request(): void
    {
        WpStubState::setCurrentUser(7, 'alice', 'Alice Example');

        $repo = new ArrayAuditLogRepository();
        $logger = new AuditLogger($repo, new NullLogger());

        $stored = $logger->record(AuditEvent::make('options.changed', 'options', 'notice'));

        self::assertNotNull($stored);
        self::assertSame(7, $stored->actorId);
        self::assertSame('Alice Example', $stored->actorName);
        self::assertSame('203.0.113.10', $stored->ip);
        self::assertSame('SecurePressTest/1.0', $stored->userAgent);
        self::assertSame('/wp-admin/options-general.php', $stored->requestUri);
    }

    public function test_record_does_not_overwrite_explicitly_set_actor_or_request(): void
    {
        WpStubState::setCurrentUser(7, 'alice');

        $repo = new ArrayAuditLogRepository();
        $logger = new AuditLogger($repo, new NullLogger());

        $event = AuditEvent::make('plugin.activated', 'plugin', 'warning')
            ->withActor(1, 'system')
            ->withRequest('1.1.1.1', 'WP-CLI/3.0', '/wp-cron.php');

        $stored = $logger->record($event);

        self::assertSame(1, $stored->actorId);
        self::assertSame('system', $stored->actorName);
        self::assertSame('1.1.1.1', $stored->ip);
        self::assertSame('WP-CLI/3.0', $stored->userAgent);
        self::assertSame('/wp-cron.php', $stored->requestUri);
    }

    public function test_min_storage_level_skips_database_but_can_mirror(): void
    {
        $captured = [];
        $fileLogger = new class ($captured) implements LoggerInterface {
            public function __construct(private array &$captured)
            {
            }

            public function log(string $level, string $message, array $context = []): void
            {
                $this->captured[] = $message;
            }

            public function info(string $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function warning(string $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function error(string $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }
        };

        $repo = new ArrayAuditLogRepository();
        $logger = new AuditLogger(
            $repo,
            $fileLogger,
            enabled: true,
            mirrorToFileLogger: true,
            minStorageLevel: AuditEventLevel::WARNING,
        );

        self::assertNull($logger->info('low.noise'));
        self::assertSame(0, $repo->count());
        self::assertCount(1, $captured);

        self::assertNotNull($logger->warning('important.event'));
        self::assertSame(1, $repo->count());
    }

    public function test_disabled_logger_is_a_noop(): void
    {
        $repo = new ArrayAuditLogRepository();
        $logger = new AuditLogger($repo, new NullLogger(), enabled: false);

        $result = $logger->info('user.login.success');

        self::assertNull($result);
        self::assertSame(0, $repo->count());
    }

    public function test_psr3_helpers_route_to_correct_levels(): void
    {
        $repo = new ArrayAuditLogRepository();
        $logger = new AuditLogger($repo, new NullLogger());

        $logger->info('event.info');
        $logger->notice('event.notice');
        $logger->warning('event.warning');
        $logger->error('event.error');
        $logger->critical('event.critical');

        $page = $repo->paginate(new \SecurePress\Core\Audit\AuditLogQuery());

        $byAction = [];
        foreach ($page->items as $event) {
            $byAction[$event->action] = $event->level;
        }
        self::assertSame('info', $byAction['event.info']);
        self::assertSame('notice', $byAction['event.notice']);
        self::assertSame('warning', $byAction['event.warning']);
        self::assertSame('error', $byAction['event.error']);
        self::assertSame('critical', $byAction['event.critical']);
    }

    public function test_repository_failure_is_swallowed_and_logged(): void
    {
        $repo = new class implements AuditLogRepositoryInterface {
            public function record(AuditEvent $event): AuditEvent
            {
                throw new RuntimeException('database is on fire');
            }

            public function findById(int $id): ?AuditEvent
            {
                return null;
            }

            public function paginate(\SecurePress\Core\Audit\AuditLogQuery $query): \SecurePress\Core\Audit\AuditLogPage
            {
                return new \SecurePress\Core\Audit\AuditLogPage([], 0, 1, 25);
            }

            public function count(): int
            {
                return 0;
            }

            public function deleteAll(): int
            {
                return 0;
            }

            public function deleteOlderThan(int $olderThan): int
            {
                return 0;
            }
        };

        $captured = [];
        $fileLogger = new class ($captured) implements LoggerInterface {
            public function __construct(private array &$captured)
            {
            }

            public function log(string $level, string $message, array $context = []): void
            {
                $this->captured[] = ['level' => $level, 'message' => $message];
            }

            public function info(string $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function warning(string $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function error(string $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }
        };

        $logger = new AuditLogger($repo, $fileLogger);
        $result = $logger->info('event.causes.failure');

        self::assertNull($result);
        self::assertCount(1, $captured);
        self::assertSame('warning', $captured[0]['level']);
        self::assertStringContainsString('database is on fire', $captured[0]['message']);
    }

    public function test_mirror_to_file_logger_writes_to_underlying_logger_when_enabled(): void
    {
        $captured = [];
        $fileLogger = new class ($captured) implements LoggerInterface {
            public function __construct(private array &$captured)
            {
            }

            public function log(string $level, string $message, array $context = []): void
            {
                $this->captured[] = compact('level', 'message', 'context');
            }

            public function info(string $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function warning(string $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function error(string $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }
        };

        $repo = new ArrayAuditLogRepository();
        $logger = new AuditLogger($repo, $fileLogger, enabled: true, mirrorToFileLogger: true);

        $logger->critical('plugin.activated', ['slug' => 'evil']);

        self::assertCount(1, $captured);
        self::assertSame('error', $captured[0]['level'], 'critical maps to file logger error');
        self::assertStringContainsString('plugin.activated', $captured[0]['message']);
    }
}
