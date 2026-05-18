<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\WooCommerce\Services;

use PHPUnit\Framework\TestCase;
use PressSentinel\WooCommerce\Services\CartFingerprinter;

/**
 * @see \PressSentinel\WooCommerce\Services\CartFingerprinter
 */
final class CartFingerprinterTest extends TestCase
{
    public function test_fingerprint_is_deterministic_for_same_cart(): void
    {
        $fp = new CartFingerprinter();

        $a = $fp->fingerprint([
            ['product_id' => 10, 'quantity' => 1],
            ['product_id' => 22, 'quantity' => 3],
        ]);
        $b = $fp->fingerprint([
            ['product_id' => 10, 'quantity' => 1],
            ['product_id' => 22, 'quantity' => 3],
        ]);

        self::assertSame($a, $b);
        self::assertNotEmpty($a);
    }

    public function test_order_does_not_affect_fingerprint(): void
    {
        $fp = new CartFingerprinter();

        $a = $fp->fingerprint([
            ['product_id' => 10, 'quantity' => 1],
            ['product_id' => 22, 'quantity' => 3],
        ]);
        $b = $fp->fingerprint([
            ['product_id' => 22, 'quantity' => 3],
            ['product_id' => 10, 'quantity' => 1],
        ]);

        self::assertSame($a, $b);
    }

    public function test_different_quantity_produces_different_fingerprint(): void
    {
        $fp = new CartFingerprinter();

        $a = $fp->fingerprint([['product_id' => 10, 'quantity' => 1]]);
        $b = $fp->fingerprint([['product_id' => 10, 'quantity' => 2]]);

        self::assertNotSame($a, $b);
    }

    public function test_variations_are_part_of_fingerprint(): void
    {
        $fp = new CartFingerprinter();

        $a = $fp->fingerprint([['product_id' => 10, 'variation_id' => 100, 'quantity' => 1]]);
        $b = $fp->fingerprint([['product_id' => 10, 'variation_id' => 200, 'quantity' => 1]]);

        self::assertNotSame($a, $b);
    }

    public function test_empty_cart_returns_empty_string(): void
    {
        $fp = new CartFingerprinter();

        self::assertSame('', $fp->fingerprint([]));
        self::assertSame('', $fp->fingerprint([['product_id' => 0, 'quantity' => 1]]));
    }
}
