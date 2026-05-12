<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\Integrity;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Integrity\Manifest;
use SecurePress\Core\Integrity\ManifestDiff;
use SecurePress\Core\Integrity\ManifestEntry;

/**
 * @see \SecurePress\Core\Integrity\ManifestDiff
 */
final class ManifestDiffTest extends TestCase
{
    public function test_between_identical_manifests_is_empty(): void
    {
        $a = Manifest::empty('plugins', '/wp-content/plugins')
            ->withEntry(new ManifestEntry('a/index.php', 'hash-a', 100, 1000))
            ->withEntry(new ManifestEntry('b/index.php', 'hash-b', 200, 1000));

        $diff = ManifestDiff::between($a, $a);

        self::assertTrue($diff->isEmpty());
        self::assertSame(0, $diff->totalChanges());
    }

    public function test_between_detects_added_modified_and_deleted(): void
    {
        $old = Manifest::empty('plugins', '/wp-content/plugins')
            ->withEntry(new ManifestEntry('unchanged.php', 'hash-1', 10, 100))
            ->withEntry(new ManifestEntry('modified.php', 'hash-old', 20, 100))
            ->withEntry(new ManifestEntry('deleted.php', 'hash-d', 30, 100));

        $new = Manifest::empty('plugins', '/wp-content/plugins')
            ->withEntry(new ManifestEntry('unchanged.php', 'hash-1', 10, 100))
            ->withEntry(new ManifestEntry('modified.php', 'hash-new', 22, 200))
            ->withEntry(new ManifestEntry('added.php', 'hash-a', 5, 300));

        $diff = ManifestDiff::between($old, $new);

        self::assertCount(1, $diff->added);
        self::assertSame('added.php', $diff->added[0]->path);

        self::assertCount(1, $diff->modified);
        self::assertSame('modified.php', $diff->modified[0]['path']);
        self::assertSame('hash-old', $diff->modified[0]['old']->hash);
        self::assertSame('hash-new', $diff->modified[0]['new']->hash);

        self::assertCount(1, $diff->deleted);
        self::assertSame('deleted.php', $diff->deleted[0]->path);

        self::assertSame(3, $diff->totalChanges());
        self::assertFalse($diff->isEmpty());
    }

    public function test_mtime_only_changes_are_ignored(): void
    {
        $old = Manifest::empty('plugins', '/wp-content/plugins')
            ->withEntry(new ManifestEntry('foo.php', 'same-hash', 100, 1000));
        $new = Manifest::empty('plugins', '/wp-content/plugins')
            ->withEntry(new ManifestEntry('foo.php', 'same-hash', 100, 9999));

        $diff = ManifestDiff::between($old, $new);

        self::assertTrue($diff->isEmpty());
    }
}
