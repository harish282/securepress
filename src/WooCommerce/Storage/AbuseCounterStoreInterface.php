<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Storage;

/**
 * Cheap per-(key, window) counter used by every velocity / abuse middleware.
 *
 * Why a dedicated abstraction instead of reusing the generic
 * {@see \SecurePress\Core\RateLimit\RateLimiter}:
 *  - the rate limiter is built around a fixed-window "hits / limit / retry_after"
 *    return; the WooCommerce middleware needs the raw count *and* the ability to
 *    snapshot a fingerprint between hits (e.g., "same cart hash N times in a row");
 *  - it also needs a non-incrementing "peek" path for read-only checks during early
 *    middleware that haven't decided to "spend" a hit yet.
 *
 * Implementations:
 *  - {@see TransientAbuseCounterStore} — production; backed by WordPress transients.
 *    Honours the configured TTL so old keys auto-expire without explicit cleanup. The
 *    transient name is HMAC-scoped to avoid leaking the raw key (IP, email, …) into
 *    the options table.
 *  - {@see ArrayAbuseCounterStore}      — test stub; uses an in-memory array with a
 *    controllable clock.
 *
 * The store is intentionally small — three methods — to keep alternative
 * implementations (e.g., Redis-backed) easy to write in a single file.
 */
interface AbuseCounterStoreInterface
{
    /**
     * Increments the counter for `$key` and returns the new value. If the counter is
     * absent, it is created and the TTL is set.
     */
    public function hit(string $key, int $ttlSeconds): int;

    /**
     * Reads the current value without modifying it. Returns 0 if absent / expired.
     */
    public function get(string $key): int;

    /**
     * Resets the counter (deletes the underlying entry).
     */
    public function reset(string $key): void;

    /**
     * Records that we observed `$value` for `$key` and reports how many times that
     * same value has been seen within the window. Used by the cart-similarity rule:
     * `seen('checkout-cart:1.2.3.4', 'cart_hash', 60)` returns 4 if the same cart
     * fingerprint has been submitted four times in the last 60 seconds.
     */
    public function seen(string $key, string $value, int $ttlSeconds): int;
}
