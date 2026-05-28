<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Storage;

use NiyiGuard\Core\Support\WpHelper;

/**
 * Production abuse counter store, backed by WordPress transients.
 *
 * Two design decisions worth calling out:
 *
 *  - **Hashed keys.** The raw `$key` (often containing an IP or email) is HMAC'd
 *    before being concatenated into the transient name. Even a misconfigured object
 *    cache can't leak PII into log files because the transient name itself is opaque.
 *
 *  - **Single-transient occurrences.** The `seen($key, $value, ...)` path keeps a
 *    small `array<string, [count, expires]>` in a single transient rather than one
 *    transient per (key, value) pair. WordPress option-table row count grows linearly
 *    with the number of transients; storing 100 values for the same checkout in a
 *    single 200-byte row beats writing 100 rows for the same total payload.
 *
 * The store is the right place for these performance choices because every middleware
 * touches it — keeping the counter cost ≤ 1 transient read/write per middleware is
 * how the protection module stays "shared-hosting friendly".
 */
final class TransientAbuseCounterStore implements AbuseCounterStoreInterface
{
    private const PREFIX = 'sp_wc_';

    public function __construct(private readonly string $hmacSecret)
    {
    }

    public function hit(string $key, int $ttlSeconds): int
    {
        $name = $this->name('c:', $key);
        $current = WpHelper::getTransient($name);
        $count = is_int($current) ? $current + 1 : 1;
        WpHelper::setTransient($name, $count, max(1, $ttlSeconds));

        return $count;
    }

    public function get(string $key): int
    {
        $value = WpHelper::getTransient($this->name('c:', $key));

        return is_int($value) ? $value : 0;
    }

    public function reset(string $key): void
    {
        WpHelper::deleteTransient($this->name('c:', $key));
        WpHelper::deleteTransient($this->name('o:', $key));
    }

    public function seen(string $key, string $value, int $ttlSeconds): int
    {
        $name = $this->name('o:', $key);
        $now = time();
        $bucket = WpHelper::getTransient($name);
        if (!is_array($bucket)) {
            $bucket = [];
        }
        // Prune expired entries from the bucket. Cheap because buckets are small.
        foreach ($bucket as $existing => $entry) {
            if (!is_array($entry) || ($entry['expires'] ?? 0) <= $now) {
                unset($bucket[$existing]);
            }
        }
        $entry = $bucket[$value] ?? ['count' => 0, 'expires' => $now + max(1, $ttlSeconds)];
        $entry['count']++;
        $bucket[$value] = $entry;

        WpHelper::setTransient($name, $bucket, max(1, $ttlSeconds));

        return $entry['count'];
    }

    private function name(string $kind, string $key): string
    {
        return self::PREFIX . $kind . substr(hash_hmac('sha256', $key, $this->hmacSecret), 0, 24);
    }
}
