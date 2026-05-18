<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Container;
use PressSentinel\Core\Http\RouteGuardRegistry;
use PressSentinel\Core\Logging\LoggerInterface;
use PressSentinel\Core\Logging\NullLogger;
use PressSentinel\Core\Middleware\MiddlewareInterface;
use PressSentinel\Core\Middleware\MiddlewarePipeline;
use PressSentinel\Core\RateLimit\ArrayStore;
use PressSentinel\Core\RateLimit\RateLimitStoreInterface;
use PressSentinel\Core\RateLimit\RateLimiter;
use PressSentinel\Core\Url\ArrayNonceStore;
use PressSentinel\Core\Url\ArraySecretProvider;
use PressSentinel\Core\Url\NonceStoreInterface;
use PressSentinel\Core\Url\UrlSigner;
use PressSentinel\Facades\Security;
use PressSentinel\Sdk\Exceptions\RouteGuardException;
use PressSentinel\Sdk\Routing\RouteBuilder;
use PressSentinel\Tests\Stubs\WpStubState;

/**
 * @see \PressSentinel\Sdk\Routing\RouteBuilder
 * @see \PressSentinel\Facades\Security::route
 */
final class RouteBuilderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->serverBackup = $_SERVER;
        $_SERVER = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WpStubState::reset();
    }

    public function test_run_invokes_callback_when_no_guards_registered(): void
    {
        Security::bootstrap($this->container());

        $calls = 0;
        $result = Security::route('/anywhere')->run(static function () use (&$calls): string {
            $calls++;

            return 'ok';
        });

        self::assertSame(1, $calls);
        self::assertSame('ok', $result);
    }

    public function test_route_registers_path_on_RouteGuardRegistry(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        Security::route('/admin/export')->run(static fn (): bool => true);

        /** @var RouteGuardRegistry $registry */
        $registry = $container->get(RouteGuardRegistry::class);
        self::assertSame(['/admin/export'], $registry->all());
    }

    public function test_capability_guard_rejects_unauthorized_user(): void
    {
        Security::bootstrap($this->container());

        $this->expectException(RouteGuardException::class);

        try {
            Security::route('/admin/export')
                ->capability('manage_options')
                ->run(static fn (): bool => true);
        } catch (RouteGuardException $exception) {
            self::assertSame('capability', $exception->guard);
            self::assertSame(403, $exception->statusCode);
            throw $exception;
        }
    }

    public function test_capability_guard_passes_when_user_has_the_capability(): void
    {
        WpStubState::$currentUserCapabilities['manage_options'] = true;
        Security::bootstrap($this->container());

        $result = Security::route('/admin/export')
            ->capability('manage_options')
            ->run(static fn (): string => 'allowed');

        self::assertSame('allowed', $result);
    }

    public function test_rateLimit_guard_short_circuits_after_budget(): void
    {
        Security::bootstrap($this->container());

        Security::route('/api/burn')
            ->rateLimit(1, 60, key: 'shared')
            ->run(static fn (): bool => true);

        try {
            Security::route('/api/burn')
                ->rateLimit(1, 60, key: 'shared')
                ->run(static fn (): bool => true);
            self::fail('Expected RouteGuardException after exhausting rate-limit budget.');
        } catch (RouteGuardException $exception) {
            self::assertSame('rate_limit', $exception->guard);
            self::assertSame(429, $exception->statusCode);
            self::assertArrayHasKey('Retry-After', $exception->headers);
        }
    }

    public function test_csrf_guard_rejects_unsafe_request_without_token(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Security::bootstrap($this->container());

        try {
            Security::route('/forms/save')
                ->csrf()
                ->run(static fn (): bool => true);
            self::fail('Expected RouteGuardException for missing CSRF token.');
        } catch (RouteGuardException $exception) {
            self::assertSame('csrf', $exception->guard);
            self::assertSame(403, $exception->statusCode);
        }
    }

    public function test_csrf_guard_accepts_valid_token_in_context(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $token = WpStubState::nextNonce('my_form');

        Security::bootstrap($this->container());

        $result = Security::route('/forms/save')
            ->csrf('my_form')
            ->run(
                static fn (): string => 'saved',
                context: [
                    'request' => [
                        'method' => 'POST',
                        'body' => ['_wpnonce' => $token],
                    ],
                ]
            );

        self::assertSame('saved', $result);
    }

    public function test_check_returns_pipeline_context_without_invoking_callback(): void
    {
        Security::bootstrap($this->container());

        $context = Security::route('/api/probe')
            ->rateLimit(5, 60, key: 'probe')
            ->check();

        self::assertTrue($context['rate_limit']['allowed']);
        self::assertSame(5, $context['rate_limit']['limit']);
        self::assertSame(4, $context['rate_limit']['remaining']);
    }

    public function test_withMiddleware_runs_user_supplied_middleware(): void
    {
        Security::bootstrap($this->container());

        $custom = new class implements MiddlewareInterface {
            public bool $ran = false;

            public function handle(array $context, callable $next): array
            {
                $this->ran = true;

                return $next($context);
            }
        };

        Security::route('/custom')->withMiddleware($custom)->run(static fn (): bool => true);

        self::assertTrue($custom->ran);
    }

    public function test_signedUrl_guard_rejects_unsigned_request(): void
    {
        $_SERVER['REQUEST_URI'] = '/share?id=1';
        Security::bootstrap($this->container());

        try {
            Security::route('/share')
                ->signedUrl()
                ->run(static fn (): bool => true);
            self::fail('Expected RouteGuardException for missing signature.');
        } catch (RouteGuardException $exception) {
            self::assertSame('signed_url', $exception->guard);
        }
    }

    public function test_signedUrl_guard_accepts_valid_signed_url_via_context(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        /** @var UrlSigner $signer */
        $signer = $container->get(UrlSigner::class);
        $url = $signer->sign('/share', ['id' => 1], 600);

        $result = Security::route('/share')
            ->signedUrl()
            ->run(static fn (): string => 'served', context: ['request' => ['url' => $url]]);

        self::assertSame('served', $result);
    }

    public function test_builder_collects_middlewares_and_path(): void
    {
        Security::bootstrap($this->container());

        $builder = Security::route('/multi')
            ->rateLimit(10, 30, key: 'k')
            ->csrf();

        self::assertInstanceOf(RouteBuilder::class, $builder);
        self::assertSame('/multi', $builder->path());
        self::assertCount(2, $builder->middlewares());
    }

    private function container(): Container
    {
        $container = new Container();
        $container->singleton(LoggerInterface::class, static fn (): LoggerInterface => new NullLogger());
        $container->singleton(MiddlewarePipeline::class, static fn (): MiddlewarePipeline => new MiddlewarePipeline());
        $container->singleton(RouteGuardRegistry::class, static fn (): RouteGuardRegistry => new RouteGuardRegistry());
        $container->singleton(RateLimitStoreInterface::class, static fn (): RateLimitStoreInterface => new ArrayStore());
        $container->singleton(
            RateLimiter::class,
            static fn (Container $c): RateLimiter => new RateLimiter($c->get(RateLimitStoreInterface::class))
        );
        $container->singleton(
            UrlSigner::class,
            static fn (): UrlSigner => new UrlSigner(new ArraySecretProvider('test-secret-32-bytes-of-entropy-XX'))
        );
        $container->singleton(NonceStoreInterface::class, static fn (): NonceStoreInterface => new ArrayNonceStore());

        return $container;
    }
}
