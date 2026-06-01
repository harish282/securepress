<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Services;

use Closure;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Tracks "when did the user start this flow?" for impossible-timing detection.
 *
 * Records when a checkout (or registration) form was rendered so middleware can
 * compare elapsed time on submit. Legitimate express flows (mobile, autofill, password
 * managers, Shop Pay) can be very fast — timing rules are off by default and should
 * only be enabled in signal or deny mode after observing your traffic. The clock writes
 * compares against `time()` when the form is submitted.
 *
 * Why not rely on JS-set form timestamps:
 *  - they're trivial to spoof if the bot is sophisticated;
 *  - they don't work for headless / JS-disabled clients (which is fine, but we still
 *    want a server-side baseline).
 *
 * The token-based variant ({@see startToken()}) lets callers correlate the start mark
 * with the eventual submission without needing a session — useful for the WooCommerce
 * REST checkout where the user might not have an authenticated session yet. We HMAC
 * the token before using it as a transient name so a forged token can't poison the
 * options table key space.
 */
final class BehaviorClock
{
    private const TRANSIENT_PREFIX = 'sp_wc_clk_';

    /** @var Closure(): int */
    private Closure $clock;

    public function __construct(
        private readonly string $hmacSecret,
        ?Closure $clock = null,
        private readonly int $ttlSeconds = 3600,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Marks "now" as the start of a flow keyed by `$key` (typically `sessionId:kind`).
     * Returns the recorded timestamp.
     */
    public function mark(string $key): int
    {
        $now = ($this->clock)();
        WpHelper::setTransient($this->name($key), $now, $this->ttlSeconds);

        return $now;
    }

    /**
     * Returns the previously recorded timestamp for `$key`, or `null` if none / expired.
     */
    public function get(string $key): ?int
    {
        $value = WpHelper::getTransient($this->name($key));

        return is_int($value) ? $value : null;
    }

    /**
     * Returns the elapsed seconds between `mark($key)` and now. `null` if no mark.
     */
    public function elapsedSeconds(string $key): ?int
    {
        $start = $this->get($key);
        if ($start === null) {
            return null;
        }

        return max(0, ($this->clock)() - $start);
    }

    public function clear(string $key): void
    {
        WpHelper::deleteTransient($this->name($key));
    }

    /**
     * Convenience wrapper that creates and returns a unique correlation token, marking
     * "now" against it. The token is hex (URL-safe) and 16 chars long; embed it as a
     * hidden input on the checkout form.
     */
    public function startToken(): string
    {
        $token = bin2hex(random_bytes(8));
        $this->mark($token);

        return $token;
    }

    private function name(string $key): string
    {
        return self::TRANSIENT_PREFIX . substr(hash_hmac('sha256', $key, $this->hmacSecret), 0, 24);
    }
}
