<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use SecurePress\Admin\Diagnostics\HealthDiagnosticsCollector;
use SecurePress\Admin\FeatureRegistry;
use SecurePress\Admin\MuLoaderStatus;
use SecurePress\Core\Audit\AuditLogOptions;
use SecurePress\Core\Audit\AuditLogPruner;
use SecurePress\Core\Audit\AuditLogSchema;
use SecurePress\Core\Auth\AuthHardeningOptions;
use SecurePress\Core\Auth\Sessions\SessionSchema;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Core\Integrity\IntegrityOptions;
use SecurePress\Core\Integrity\IntegritySchema;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Licensing\LicenseValidatorInterface;
use SecurePress\Core\RateLimit\RateLimitOptions;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\UrlDisguise\UrlDisguiseOptions;
use SecurePress\Tests\Stubs\WpStubState;
use SecurePress\WooCommerce\Admin\WooCommerceProtectionOptions;

/**
 * @see \SecurePress\Admin\Diagnostics\HealthDiagnosticsCollector
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
        self::assertContains('license_hmac_secret', $keys);
    }

    private function collector(): HealthDiagnosticsCollector
    {
        $config = new Config(require dirname(__DIR__, 3) . '/config/plugin.php');
        $validator = new class implements LicenseValidatorInterface {
            public function validate(string $key): LicenseStatus
            {
                return LicenseStatus::none();
            }
        };
        $license = new LicenseManager($validator, $config);

        return new HealthDiagnosticsCollector(
            new FeatureRegistry(
                new AuditLogOptions($config),
                new AuthHardeningOptions($config),
                new SecurityHeadersOptions($config),
                new IntegrityOptions($config),
                new WooCommerceProtectionOptions($config),
                new RateLimitOptions($config),
                new UrlDisguiseOptions($config),
                $license,
            ),
            $license,
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
