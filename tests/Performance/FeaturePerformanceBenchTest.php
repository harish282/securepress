<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Performance;

use Closure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Audit\ArrayAuditLogRepository;
use NiyiGuard\Core\Audit\AuditEvent;
use NiyiGuard\Core\Audit\AuditLogger;
use NiyiGuard\Core\Auth\Lockout\ArrayLockoutStore;
use NiyiGuard\Core\Auth\Lockout\LoginLockoutPolicy;
use NiyiGuard\Core\Auth\Lockout\LoginLockoutService;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Headers\HeaderRegistryFactory;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\Integrity\Heuristics\EvalBase64Heuristic;
use NiyiGuard\Core\Logging\NullLogger;
use NiyiGuard\Core\RateLimit\ArrayStore;
use NiyiGuard\Core\RateLimit\RateLimiter;
use NiyiGuard\Core\UrlDisguise\UrlDisguiseModule;
use NiyiGuard\Core\UrlDisguise\UrlDisguiseOptions;
use NiyiGuard\Middleware\RateLimitMiddleware;
use NiyiGuard\Middleware\SecurityHeadersMiddleware;
use NiyiGuard\Tests\Stubs\WpStubState;
use NiyiGuard\WooCommerce\Detection\DetectionContext;
use NiyiGuard\WooCommerce\Services\FraudScoreService;

/**
 * Micro-benchmarks for code paths that correspond to dashboard features in
 * {@see \NiyiGuard\Admin\FeatureRegistry}. These are not load tests; they
 * measure repeated in-process work with in-memory stubs only.
 *
 * Thresholds are intentionally loose so CI and slower machines stay green;
 * large regressions (e.g. accidental quadratic behaviour) should still fail.
 *
 * Run only this group: ./vendor/bin/phpunit --group performance
 * Exclude from a run:  ./vendor/bin/phpunit --exclude-group performance
 *
 */
#[Group('performance')]
final class FeaturePerformanceBenchTest extends TestCase
{
    private const ITERATIONS = 5000;

    /** Wall-clock ceiling for {@see self::ITERATIONS} iterations (seconds). */
    private const MAX_SECONDS = 3.0;

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    private int $rateClock = 1_700_000_000;

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->serverBackup = $_SERVER;
        $_SERVER = [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'NiyiGuardPerf/1.0',
            'REQUEST_URI' => '/',
        ];
        WpStubState::$homeUrl = 'https://example.test';
        WpStubState::$siteUrl = 'https://example.test';
        $this->rateClock = 1_700_000_000;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        WpStubState::reset();
    }

    public function test_auth_hardening_login_lockout_is_locked_benchmark(): void
    {
        $store = new ArrayLockoutStore(static fn (): int => 1_000_000);
        $policy = new LoginLockoutPolicy(10, 3600, 600, true);
        $service = new LoginLockoutService($store, $policy);

        $seconds = $this->bench(static function () use ($service): void {
            $service->isLocked('perf_user', '198.51.100.1');
        });

        $this->assertMaxSeconds($seconds, 'auth_hardening (LoginLockoutService::isLocked)');
    }

    public function test_security_headers_middleware_benchmark(): void
    {
        $middleware = new SecurityHeadersMiddleware(
            new HeaderRegistryFactory(new SecurityHeadersOptions(new Config()))
        );
        $next = static fn (array $context): array => $context;

        $seconds = $this->bench(static function () use ($middleware, $next): void {
            $middleware->handle([], $next);
        });

        $this->assertMaxSeconds($seconds, 'security_headers (SecurityHeadersMiddleware::handle)');
    }

    public function test_rate_limit_middleware_allowed_request_benchmark(): void
    {
        $clock = fn (): int => $this->rateClock;
        $limiter = new RateLimiter(new ArrayStore($clock));
        $middleware = new RateLimitMiddleware(
            limiter: $limiter,
            logger: null,
            limit: 10_000,
            window: 60,
            keyResolver: null,
            enabled: true,
        );
        $ctx = ['request' => ['ip' => '203.0.113.77']];
        $next = static fn (array $c): array => $c;

        $seconds = $this->bench(static function () use ($middleware, $ctx, $next): void {
            $middleware->handle($ctx, $next);
        });

        $this->assertMaxSeconds($seconds, 'rate_limit (RateLimitMiddleware::handle, allowed)');
    }

    public function test_url_disguise_filter_login_url_benchmark(): void
    {
        WpStubState::$options[UrlDisguiseOptions::OPTION_NAME] = [
            'enabled' => true,
            'login_slug' => 'secret-gate',
            'block_default_wp_login' => true,
        ];
        $module = new UrlDisguiseModule(new UrlDisguiseOptions(new Config()));

        $seconds = $this->bench(static function () use ($module): void {
            $module->filterLoginUrl('https://example.test/wp-login.php?redirect_to=%2F', '/foo/', false);
        });

        $this->assertMaxSeconds($seconds, 'url_disguise (UrlDisguiseModule::filterLoginUrl)');
    }

    public function test_file_integrity_heuristic_scan_clean_file_benchmark(): void
    {
        $heuristic = new EvalBase64Heuristic();
        $php = "<?php\n// clean plugin file\nreturn;\n";

        $seconds = $this->bench(static function () use ($heuristic, $php): void {
            $heuristic->scan($php);
        });

        $this->assertMaxSeconds($seconds, 'file_integrity (EvalBase64Heuristic::scan, no match)');
    }

    public function test_audit_log_record_benchmark(): void
    {
        WpStubState::setCurrentUser(2, 'perf', 'Perf User');
        $logger = new AuditLogger(new ArrayAuditLogRepository(), new NullLogger());
        $event = AuditEvent::make('perf.probe', 'other', 'info')
            ->withActor(2, 'Perf User')
            ->withRequest('203.0.113.10', 'NiyiGuardPerf/1.0', '/');

        $seconds = $this->bench(static function () use ($logger, $event): void {
            $logger->record($event);
        });

        $this->assertMaxSeconds($seconds, 'audit_log (AuditLogger::record → ArrayAuditLogRepository)');
    }

    public function test_woocommerce_protection_fraud_score_decide_benchmark(): void
    {
        $service = new FraudScoreService(challengeThreshold: 40, denyThreshold: 80);
        $context = new DetectionContext(
            kind: DetectionContext::KIND_CHECKOUT,
            ip: '203.0.113.55',
            userAgent: 'WooPerf/1.0',
            score: 35,
        );

        $seconds = $this->bench(static function () use ($service, $context): void {
            $service->decide($context);
        });

        $this->assertMaxSeconds($seconds, 'woocommerce_protection (FraudScoreService::decide)');
    }

    /**
     * @param Closure():void $work
     */
    private function bench(Closure $work): float
    {
        $start = hrtime(true);
        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            $work();
        }
        $elapsedNs = hrtime(true) - $start;

        return $elapsedNs / 1_000_000_000;
    }

    private function assertMaxSeconds(float $seconds, string $label): void
    {
        $perOpMs = (self::ITERATIONS > 0) ? ($seconds * 1000.0 / self::ITERATIONS) : 0.0;
        self::assertLessThan(
            self::MAX_SECONDS,
            $seconds,
            sprintf(
                '%s: %.3fs for %d iterations (~%.4f ms/op) exceeds %.3fs cap',
                $label,
                $seconds,
                self::ITERATIONS,
                $perOpMs,
                self::MAX_SECONDS
            )
        );
    }
}
