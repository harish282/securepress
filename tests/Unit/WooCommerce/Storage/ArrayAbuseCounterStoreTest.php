<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\WooCommerce\Storage;

use PHPUnit\Framework\TestCase;
use PressSentinel\WooCommerce\Storage\ArrayAbuseCounterStore;

/**
 * @see \PressSentinel\WooCommerce\Storage\ArrayAbuseCounterStore
 */
final class ArrayAbuseCounterStoreTest extends TestCase
{
    public function test_hit_increments_and_returns_total(): void
    {
        $store = new ArrayAbuseCounterStore();

        self::assertSame(1, $store->hit('key', 60));
        self::assertSame(2, $store->hit('key', 60));
        self::assertSame(3, $store->hit('key', 60));
        self::assertSame(3, $store->get('key'));
    }

    public function test_get_returns_zero_for_unknown_key(): void
    {
        $store = new ArrayAbuseCounterStore();

        self::assertSame(0, $store->get('missing'));
    }

    public function test_counter_expires_after_window(): void
    {
        $now = 1_000_000;
        $store = new ArrayAbuseCounterStore(function () use (&$now): int {
            return $now;
        });

        $store->hit('key', 60);
        $store->hit('key', 60);
        self::assertSame(2, $store->get('key'));

        $now += 61;

        self::assertSame(0, $store->get('key'));
        // A fresh hit re-creates the bucket.
        self::assertSame(1, $store->hit('key', 60));
    }

    public function test_reset_clears_counter(): void
    {
        $store = new ArrayAbuseCounterStore();
        $store->hit('key', 60);
        $store->seen('key', 'value', 60);

        $store->reset('key');

        self::assertSame(0, $store->get('key'));
        self::assertSame(1, $store->seen('key', 'value', 60));
    }

    public function test_seen_counts_distinct_values_separately(): void
    {
        $store = new ArrayAbuseCounterStore();

        self::assertSame(1, $store->seen('key', 'foo', 60));
        self::assertSame(2, $store->seen('key', 'foo', 60));
        self::assertSame(1, $store->seen('key', 'bar', 60));
    }
}
