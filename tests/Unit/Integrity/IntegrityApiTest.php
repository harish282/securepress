<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Container;
use NiyiGuard\Core\Integrity\ArrayFindingRepository;
use NiyiGuard\Core\Integrity\ArrayManifestRepository;
use NiyiGuard\Core\Integrity\Finding;
use NiyiGuard\Core\Integrity\FindingRepositoryInterface;
use NiyiGuard\Core\Integrity\FindingSeverity;
use NiyiGuard\Core\Integrity\FindingType;
use NiyiGuard\Core\Integrity\IntegrityOptions;
use NiyiGuard\Core\Integrity\IntegrityService;
use NiyiGuard\Core\Integrity\ManifestRepositoryInterface;
use NiyiGuard\Core\Integrity\Scanners\ScannerInterface;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Facades\Security;
use NiyiGuard\Sdk\IntegrityApi;
use NiyiGuard\Tests\Stubs\WpStubState;

/**
 * @see \NiyiGuard\Sdk\IntegrityApi
 * @see \NiyiGuard\Facades\Security::integrity()
 */
final class IntegrityApiTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_security_integrity_returns_integrity_api(): void
    {
        $container = $this->container();

        Security::bootstrap($container);

        self::assertInstanceOf(IntegrityApi::class, Security::integrity());
        self::assertSame(Security::integrity(), Security::integrity(), 'API should be memoised.');
    }

    public function test_scan_runs_registered_scanners(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        /** @var IntegrityService $service */
        $service = $container->get(IntegrityService::class);
        $service->registerScanner(new class implements ScannerInterface {
            public function name(): string { return 'fake'; }
            public function scan(): array
            {
                return [
                    Finding::make('plugins', FindingType::SUSPICIOUS_PHP, FindingSeverity::CRITICAL, 'evil.php', 'm'),
                ];
            }
        });

        $result = Security::integrity()->scan();

        self::assertSame(1, $result->totalFindings());
        self::assertCount(1, Security::integrity()->openFindings());
    }

    public function test_mark_reviewed_and_clear(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        /** @var FindingRepositoryInterface $repo */
        $repo = $container->get(FindingRepositoryInterface::class);
        $f = $repo->record(Finding::make('plugins', FindingType::FILE_MODIFIED, FindingSeverity::HIGH, 'foo.php', 'msg'));

        self::assertSame(1, Security::integrity()->countOpen());
        self::assertTrue(Security::integrity()->markReviewed($f->id));
        self::assertSame(0, Security::integrity()->countOpen());
        self::assertSame(1, Security::integrity()->clearFindings());
    }

    public function test_reset_baseline_clears_per_scope(): void
    {
        $container = $this->container();
        Security::bootstrap($container);

        /** @var ManifestRepositoryInterface $manifests */
        $manifests = $container->get(ManifestRepositoryInterface::class);
        $manifests->save(\NiyiGuard\Core\Integrity\Manifest::empty('plugins', '/x'));
        $manifests->save(\NiyiGuard\Core\Integrity\Manifest::empty('themes', '/y'));

        Security::integrity()->resetBaseline('plugins');
        self::assertNull($manifests->load('plugins'));
        self::assertNotNull($manifests->load('themes'));

        Security::integrity()->resetBaseline();
        self::assertNull($manifests->load('themes'));
    }

    private function container(): Container
    {
        $container = new Container();
        $container->singleton(FindingRepositoryInterface::class, static fn () => new ArrayFindingRepository());
        $container->singleton(ManifestRepositoryInterface::class, static fn () => new ArrayManifestRepository());
        $container->singleton(
            IntegrityService::class,
            static fn (Container $c): IntegrityService => new IntegrityService(
                $c->get(FindingRepositoryInterface::class)
            )
        );
        $container->singleton(Config::class, static fn () => new Config());
        $container->singleton(
            IntegrityOptions::class,
            static fn (Container $c): IntegrityOptions => new IntegrityOptions($c->get(Config::class))
        );

        return $container;
    }
}
