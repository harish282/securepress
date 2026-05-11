<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Audit\ArrayAuditLogRepository;
use SecurePress\Core\Audit\AuditLogger;
use SecurePress\Core\Audit\AuditLoggerInterface;
use SecurePress\Core\Auth\Lockout\ArrayLockoutStore;
use SecurePress\Core\Auth\Lockout\LockoutStoreInterface;
use SecurePress\Core\Auth\Lockout\LoginLockoutPolicy;
use SecurePress\Core\Auth\Lockout\LoginLockoutService;
use SecurePress\Core\Auth\Notifications\ArrayMailer;
use SecurePress\Core\Auth\Notifications\AuthNotifier;
use SecurePress\Core\Auth\Sessions\ArraySessionRepository;
use SecurePress\Core\Auth\Sessions\SessionFingerprinter;
use SecurePress\Core\Auth\Sessions\SessionRepositoryInterface;
use SecurePress\Core\Auth\Sessions\SessionService;
use SecurePress\Core\Auth\TwoFactor\ArrayChallengeStore;
use SecurePress\Core\Auth\TwoFactor\ArrayTwoFactorRepository;
use SecurePress\Core\Auth\TwoFactor\ChallengeStoreInterface;
use SecurePress\Core\Auth\TwoFactor\EmailOtpProvider;
use SecurePress\Core\Auth\TwoFactor\RecoveryCodeService;
use SecurePress\Core\Auth\TwoFactor\TotpProvider;
use SecurePress\Core\Auth\TwoFactor\TwoFactorService;
use SecurePress\Core\Auth\TwoFactor\TwoFactorUserRepositoryInterface;
use SecurePress\Core\Container;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Facades\Security;
use SecurePress\Sdk\AuditApi;
use SecurePress\Sdk\LockoutApi;
use SecurePress\Sdk\SessionApi;
use SecurePress\Sdk\TwoFactorApi;
use SecurePress\Tests\Stubs\WpStubState;

/**
 * @see \SecurePress\Sdk\TwoFactorApi
 * @see \SecurePress\Sdk\SessionApi
 * @see \SecurePress\Sdk\LockoutApi
 * @see \SecurePress\Sdk\AuditApi
 */
final class SubFacadesTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_twoFactor_isEnabledFor_reflects_repository_state(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        self::assertInstanceOf(TwoFactorApi::class, Security::twoFactor());
        self::assertFalse(Security::twoFactor()->isEnabledFor(99));

        Security::twoFactor()->disable(99);

        self::assertContains('totp', Security::twoFactor()->availableMethods());
        self::assertContains('email_otp', Security::twoFactor()->availableMethods());
    }

    public function test_twoFactor_enable_email_otp_via_service_round_trips_through_sdk(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        $service = $container->get(TwoFactorService::class);
        $service->enableEmailOtp(42, 'alice@example.test', 'Alice');

        self::assertTrue(Security::twoFactor()->isEnabledFor(42));
        self::assertTrue(Security::twoFactor()->requiresChallenge(42));

        $codes = Security::twoFactor()->regenerateRecoveryCodes(42);
        self::assertCount(8, $codes);

        Security::twoFactor()->disable(42);
        self::assertFalse(Security::twoFactor()->isEnabledFor(42));
    }

    public function test_sessions_api_tracks_revokes_and_lists(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        self::assertInstanceOf(SessionApi::class, Security::sessions());

        $session = Security::sessions()->track(5, '203.0.113.10', 'UA/1.0', 'Office');
        $alt = Security::sessions()->track(5, '203.0.113.11', 'UA/2.0', 'Phone');

        self::assertCount(2, Security::sessions()->activeFor(5));

        self::assertTrue(Security::sessions()->revoke(5, (int) $alt->id));
        self::assertCount(1, Security::sessions()->activeFor(5));

        $revoked = Security::sessions()->revokeAllExceptCurrent(5, (int) $session->id);
        self::assertSame(0, $revoked, 'Only the current session was active, nothing to revoke.');
    }

    public function test_lockout_api_drives_failure_counters(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        self::assertInstanceOf(LockoutApi::class, Security::lockout());
        self::assertFalse(Security::lockout()->isLocked('alice', '203.0.113.10'));

        for ($i = 0; $i < 4; $i++) {
            Security::lockout()->registerFailure('alice', '203.0.113.10');
        }
        self::assertFalse(Security::lockout()->isLocked('alice', '203.0.113.10'));

        $locked = Security::lockout()->registerFailure('alice', '203.0.113.10');
        self::assertTrue($locked);
        self::assertTrue(Security::lockout()->isLocked('alice', '203.0.113.10'));

        Security::lockout()->clear('alice', '203.0.113.10');
        self::assertFalse(Security::lockout()->isLocked('alice', '203.0.113.10'));
    }

    public function test_audit_api_records_an_event(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        self::assertInstanceOf(AuditApi::class, Security::audit());

        $event = Security::audit()->info('user.login.success', ['ip' => '203.0.113.10']);
        self::assertNotNull($event);

        /** @var ArrayAuditLogRepository $repo */
        $repo = $container->get(\SecurePress\Core\Audit\AuditLogRepositoryInterface::class);
        self::assertSame(1, $repo->count());
        $page = $repo->paginate(new \SecurePress\Core\Audit\AuditLogQuery());
        self::assertSame('user.login.success', $page->items[0]->action);
    }

    public function test_audit_api_supports_fluent_builder(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        Security::audit()
            ->for((object) ['ID' => 5, 'display_name' => 'Alice'])
            ->category('woocommerce')
            ->action('order.refunded')
            ->record();

        /** @var ArrayAuditLogRepository $repo */
        $repo = $container->get(\SecurePress\Core\Audit\AuditLogRepositoryInterface::class);
        self::assertSame(1, $repo->count());
        $page = $repo->paginate(new \SecurePress\Core\Audit\AuditLogQuery());
        $first = $page->items[0];
        self::assertSame('order.refunded', $first->action);
        self::assertSame('woocommerce', $first->category);
        self::assertSame(5, $first->actorId);
    }

    private function container(): Container
    {
        $container = new Container();

        $container->singleton(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());

        // 2FA.
        $container->singleton(TwoFactorUserRepositoryInterface::class, static fn (): TwoFactorUserRepositoryInterface => new ArrayTwoFactorRepository());
        $container->singleton(ChallengeStoreInterface::class, static fn (): ChallengeStoreInterface => new ArrayChallengeStore());
        $container->singleton(
            TwoFactorService::class,
            static fn (Container $c): TwoFactorService => new TwoFactorService(
                $c->get(TwoFactorUserRepositoryInterface::class),
                $c->get(ChallengeStoreInterface::class),
                new TotpProvider(),
                new EmailOtpProvider(),
                new RecoveryCodeService(),
                new AuthNotifier(new ArrayMailer(), new NullLogger(), 'Tests', 'https://example.test'),
                new NullLogger(),
                'Tests',
                600,
            )
        );

        // Sessions.
        $container->singleton(SessionRepositoryInterface::class, static fn (): SessionRepositoryInterface => new ArraySessionRepository());
        $container->singleton(
            SessionService::class,
            static fn (Container $c): SessionService => new SessionService(
                $c->get(SessionRepositoryInterface::class),
                new SessionFingerprinter(),
                new NullLogger(),
                null
            )
        );

        // Lockout.
        $container->singleton(LockoutStoreInterface::class, static fn (): LockoutStoreInterface => new ArrayLockoutStore());
        $container->singleton(
            LoginLockoutService::class,
            static fn (Container $c): LoginLockoutService => new LoginLockoutService(
                $c->get(LockoutStoreInterface::class),
                new LoginLockoutPolicy(5, 900, 900, true)
            )
        );

        // Audit.
        $container->singleton(
            \SecurePress\Core\Audit\AuditLogRepositoryInterface::class,
            static fn (): \SecurePress\Core\Audit\AuditLogRepositoryInterface => new ArrayAuditLogRepository()
        );
        $container->singleton(
            AuditLoggerInterface::class,
            static fn (Container $c): AuditLoggerInterface => new AuditLogger(
                $c->get(\SecurePress\Core\Audit\AuditLogRepositoryInterface::class),
                new NullLogger(),
                true,
                false
            )
        );

        return $container;
    }
}
