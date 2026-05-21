<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Integrity\ArrayFindingRepository;
use PressSentinel\Core\Integrity\Finding;
use PressSentinel\Core\Integrity\FindingSeverity;
use PressSentinel\Core\Integrity\FindingType;
use PressSentinel\Core\Integrity\IntegrityService;
use PressSentinel\Core\Integrity\Scanners\ScannerInterface;

/**
 * @see \PressSentinel\Core\Integrity\IntegrityService
 */
final class IntegrityServiceTest extends TestCase
{
    public function test_runs_every_scanner_and_persists_findings(): void
    {
        $repo = new ArrayFindingRepository();
        $service = new IntegrityService($repo);

        $service->registerScanner($this->scanner('s1', [
            Finding::make('plugins', FindingType::FILE_ADDED, FindingSeverity::MEDIUM, 'a.php', 'm'),
        ]));
        $service->registerScanner($this->scanner('s2', [
            Finding::make('plugins', FindingType::SUSPICIOUS_PHP, FindingSeverity::CRITICAL, 'b.php', 'c'),
            Finding::make('plugins', FindingType::SUSPICIOUS_PHP, FindingSeverity::CRITICAL, 'd.php', 'c'),
        ]));

        $result = $service->scan();

        self::assertSame(3, $result->totalFindings());
        self::assertSame(3, $repo->countOpen());
        self::assertSame(['s1', 's2'], $result->scannersRun);

        $summary = $result->summary;
        self::assertSame(1, $summary[FindingSeverity::MEDIUM] ?? 0);
        self::assertSame(2, $summary[FindingSeverity::CRITICAL] ?? 0);
        self::assertTrue($result->hasCritical());
    }

    public function test_scanner_exception_does_not_abort_others(): void
    {
        $repo = new ArrayFindingRepository();
        $service = new IntegrityService($repo);

        $service->registerScanner(new class implements ScannerInterface {
            public function name(): string { return 'broken'; }
            public function scan(): array { throw new \RuntimeException('boom'); }
        });
        $service->registerScanner($this->scanner('good', [
            Finding::make('plugins', FindingType::FILE_MODIFIED, FindingSeverity::HIGH, 'x.php', 'ok'),
        ]));

        $result = $service->scan();

        self::assertSame(1, $result->totalFindings());
        self::assertSame(['broken', 'good'], $result->scannersRun);
    }

    /**
     * @param list<Finding> $findings
     */
    private function scanner(string $name, array $findings): ScannerInterface
    {
        return new class($name, $findings) implements ScannerInterface {
            public function __construct(private readonly string $n, private readonly array $f) {}
            public function name(): string { return $this->n; }
            public function scan(): array { return $this->f; }
        };
    }
}
