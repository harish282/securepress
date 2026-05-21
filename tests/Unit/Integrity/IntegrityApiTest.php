<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Container;
use PressSentinel\Core\Integrity\ArrayFindingRepository;
use PressSentinel\Core\Integrity\ArrayManifestRepository;
use PressSentinel\Core\Integrity\Finding;
use PressSentinel\Core\Integrity\FindingRepositoryInterface;
use PressSentinel\Core\Integrity\FindingSeverity;
use PressSentinel\Core\Integrity\FindingType;
use PressSentinel\Core\Integrity\IntegrityOptions;
use PressSentinel\Core\Integrity\IntegrityService;
use PressSentinel\Core\Integrity\ManifestRepositoryInterface;
use PressSentinel\Core\Integrity\Scanners\ScannerInterface;
use PressSentinel\Core\Config\Config;
use PressSentinel\Facades\Security;
use PressSentinel\Sdk\IntegrityApi;
use PressSentinel\Tests\Stubs\WpStubState;

/**
 * @see \PressSentinel\Sdk\IntegrityApi
 * @see \PressSentinel\Facades\Security::integrity()
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
        $manifests->save(\PressSentinel\Core\Integrity\Manifest::empty('plugins', '/x'));
        $manifests->save(\PressSentinel\Core\Integrity\Manifest::empty('themes', '/y'));

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
