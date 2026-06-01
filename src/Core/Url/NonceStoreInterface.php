<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Url;

/**
 * Persistence contract for one-time-use signed-URL nonces.
 *
 * Implementations track the set of "live" (mintable but unused) nonces. `consume()` MUST be
 * effectively atomic from the caller's perspective: if two concurrent requests arrive with the
 * same nonce, at most one MUST receive `true`. Implementations MAY rely on the underlying
 * cache layer's atomicity guarantees and document trade-offs (e.g. transient stores allow a
 * tiny race window which is acceptable for non-financial flows).
 */
interface NonceStoreInterface
{
    /**
     * Marks `$nonce` as live for `$ttl` seconds.
     */
    public function register(string $nonce, int $ttl): void;

    /**
     * Returns `true` exactly once per registered nonce — on the first consume call.
     * Subsequent calls (or calls for never-registered nonces) MUST return `false`.
     */
    public function consume(string $nonce): bool;
}
