<?php

declare(strict_types=1);

namespace SecurePress\Core\Url;

use SecurePress\Core\Support\WpHelper;

/**
 * Persists one-time-use nonces in WordPress transients.
 *
 * `consume()` deletes the transient before returning `true`, which is "atomic enough" for
 * most flows: a race window exists where two near-simultaneous requests could both observe
 * the live transient and both succeed. For password reset / magic-link flows this is normally
 * acceptable because the surrounding operation (e.g. setting a new password) is itself
 * idempotent and audited. For higher-stakes operations layer an additional lock or use an
 * atomic-aware store implementation.
 */
final class TransientNonceStore implements NonceStoreInterface
{
    private const PREFIX = 'sp_nonce_';

    public function register(string $nonce, int $ttl): void
    {
        if ($nonce === '' || $ttl < 1) {
            return;
        }

        WpHelper::setTransient($this->key($nonce), 1, $ttl);
    }

    public function consume(string $nonce): bool
    {
        if ($nonce === '') {
            return false;
        }

        $key = $this->key($nonce);
        $value = WpHelper::getTransient($key);
        if ($value === false || $value === null) {
            return false;
        }

        WpHelper::deleteTransient($key);

        return true;
    }

    private function key(string $nonce): string
    {
        return self::PREFIX . hash('sha256', $nonce);
    }
}
