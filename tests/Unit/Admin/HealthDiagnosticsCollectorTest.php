<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Admin\Diagnostics\HealthDiagnosticsCollector;
use NiyiGuard\Admin\FeatureRegistry;
use NiyiGuard\Admin\MuLoaderStatus;
use NiyiGuard\Core\Audit\AuditLogOptions;
use NiyiGuard\Core\Audit\AuditLogPruner;
use NiyiGuard\Core\Audit\AuditLogSchema;
use NiyiGuard\Core\Auth\AuthHardeningOptions;
use NiyiGuard\Core\Auth\Sessions\SessionSchema;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\Integrity\IntegrityOptions;
use NiyiGuard\Core\Integrity\IntegritySchema;
use NiyiGuard\Core\RateLimit\RateLimitOptions;
use NiyiGuard\Core\Support\WpHelper;
use NiyiGuard\Core\UrlDisguise\UrlDisguiseOptions;
use NiyiGuard\Tests\Stubs\WpStubState;
use NiyiGuard\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * @see \NiyiGuard\Admin\Diagnostics\HealthDiagnosticsCollector
 */
final class HealthDiagnosticsCollectorTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_collect_reports_transient_probe_pass(): void
    {
        $report = $this->collector()->collect();

        self::assertTrue($report['transients']['functions_available']);
        self::assertTrue($report['transients']['read_write_ok']);
    }

    public function test_collect_marks_expected_hook_missing_when_not_registered(): void
    {
        WpHelper::addAction('send_headers', static function (): void {
        }, 1);

        $report = $this->collector()->collect();
        $headersRow = null;
        foreach ($report['hooks'] as $row) {
            if ($row['hook'] === 'send_headers') {
                $headersRow = $row;
                break;
            }
        }

        self::assertNotNull($headersRow);
        self::assertTrue($headersRow['expected']);
        self::assertTrue($headersRow['registered']);
        self::assertSame('ok', $headersRow['status']);
    }

    public function test_collect_flags_audit_prune_hook_missing_when_expected(): void
    {
        $report = $this->collector()->collect();
        $pruneRow = null;
        foreach ($report['hooks'] as $row) {
            if ($row['hook'] === AuditLogPruner::HOOK) {
                $pruneRow = $row;
                break;
            }
        }

        self::assertNotNull($pruneRow);
        self::assertTrue($pruneRow['expected']);
        self::assertFalse($pruneRow['registered']);
        self::assertSame('missing', $pruneRow['status']);
    }

    public function test_collect_includes_all_feature_protections(): void
    {
        $keys = array_map(
            static fn (array $row): string => $row['key'],
            $this->collector()->collect()['protections']
        );

        self::assertContains('auth_hardening', $keys);
        self::assertContains('audit_log', $keys);
        self::assertContains('mu_loader', $keys);
        self::assertContains('rate_limit_http', $keys);
    }

    private function collector(): HealthDiagnosticsCollector
    {
        $config = new Config(require dirname(__DIR__, 3) . '/config/plugin.php');

        return new HealthDiagnosticsCollector(
            new FeatureRegistry(
                new AuditLogOptions($config),
                new AuthHardeningOptions($config),
                new SecurityHeadersOptions($config),
                new IntegrityOptions($config),
                new WooCommerceProtectionOptions($config),
                new RateLimitOptions($config),
                new UrlDisguiseOptions($config),
            ),
            new SecurityHeadersOptions($config),
            new UrlDisguiseOptions($config),
            new RateLimitOptions($config),
            new AuditLogOptions($config),
            new AuthHardeningOptions($config),
            new IntegrityOptions($config),
            new WooCommerceProtectionOptions($config),
            new AuditLogSchema(),
            new SessionSchema(),
            new IntegritySchema(),
            new MuLoaderStatus(),
            $config,
        );
    }
}
