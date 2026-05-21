<?php

declare(strict_types=1);

namespace PressSentinel\WooCommerce\Services;

/**
 * Produces a stable, deterministic hash of a cart's *content*, independent of order
 * or formatting.
 *
 * Used by the cart-similarity middleware to detect the "5 checkout attempts in 2
 * minutes with literally the same items" abuse pattern. The fingerprint normalises:
 *
 *  - **Sort order**: items are sorted by `product_id` so reordering the same cart
 *    doesn't change the fingerprint.
 *  - **Variation collapse**: variation id is incorporated; "small red shirt" and
 *    "large red shirt" produce different hashes even though `product_id` is the same.
 *  - **Quantity bucketing**: quantities are taken as-is. We don't bucket because a
 *    legitimate "1 → 2 → 3 → 1" exploration looks the same as bot probing if we did.
 *
 * The hash is SHA-256 truncated to 16 hex chars (64 bits) — same security budget as
 * our license HMAC. Storing the truncated form keeps the abuse-counter buckets small.
 */
final class CartFingerprinter
{
    /**
     * @param iterable<int, array{product_id:int, variation_id?:int, quantity?:int}> $items
     */
    public function fingerprint(iterable $items): string
    {
        $normalized = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $variationId = (int) ($item['variation_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 1);
            if ($quantity <= 0) {
                continue;
            }
            $normalized[] = $productId . ':' . $variationId . ':' . $quantity;
        }

        if ($normalized === []) {
            return '';
        }

        sort($normalized);

        return substr(hash('sha256', implode('|', $normalized)), 0, 16);
    }
}
