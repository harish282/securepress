<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Integrity\ArrayFindingRepository;
use NiyiGuard\Core\Integrity\Finding;
use NiyiGuard\Core\Integrity\FindingSeverity;
use NiyiGuard\Core\Integrity\FindingType;

/**
 * @see \NiyiGuard\Core\Integrity\ArrayFindingRepository
 */
final class ArrayFindingRepositoryTest extends TestCase
{
    public function test_record_assigns_incrementing_id(): void
    {
        $repo = new ArrayFindingRepository();
        $first = $repo->record($this->finding('a.php'));
        $second = $repo->record($this->finding('b.php'));

        self::assertSame(1, $first->id);
        self::assertSame(2, $second->id);
    }

    public function test_open_excludes_reviewed(): void
    {
        $repo = new ArrayFindingRepository();
        $a = $repo->record($this->finding('a.php'));
        $repo->record($this->finding('b.php'));

        self::assertSame(2, $repo->countOpen());
        $repo->markReviewed($a->id);
        self::assertSame(1, $repo->countOpen());
        self::assertSame(['b.php'], array_map(static fn ($f) => $f->path, $repo->open()));
    }

    public function test_delete_removes_finding(): void
    {
        $repo = new ArrayFindingRepository();
        $a = $repo->record($this->finding('a.php'));
        $repo->record($this->finding('b.php'));

        self::assertTrue($repo->delete($a->id));
        self::assertCount(1, $repo->all());
        self::assertFalse($repo->delete(9999));
    }

    public function test_delete_all_returns_count(): void
    {
        $repo = new ArrayFindingRepository();
        $repo->record($this->finding('a.php'));
        $repo->record($this->finding('b.php'));
        $repo->record($this->finding('c.php'));

        self::assertSame(3, $repo->deleteAll());
        self::assertSame([], $repo->all());
    }

    public function test_mark_reviewed_returns_false_for_unknown_id(): void
    {
        $repo = new ArrayFindingRepository();
        self::assertFalse($repo->markReviewed(123));
    }

    private function finding(string $path): Finding
    {
        return Finding::make(
            scope: 'plugins',
            type: FindingType::SUSPICIOUS_PHP,
            severity: FindingSeverity::HIGH,
            path: $path,
            message: 'test',
        );
    }
}
