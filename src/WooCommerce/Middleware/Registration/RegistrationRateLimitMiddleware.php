<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Registration;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Storage\AbuseCounterStoreInterface;

/**
 * Per-IP registration throttle.
 *
 * Registration floods are the textbook bot abuse pattern: scripts hammer
 * `/wp-login.php?action=register` (or the WooCommerce variant) to seed thousands of
 * accounts for later spam / fraud use.
 *
 * The middleware keeps a fixed-window counter per source IP. When the count exceeds
 * {@see $limit} within {@see $windowSeconds}, the middleware short-circuits with a
 * `DENY`. Unlike the velocity counter on the checkout side, we use a single hard
 * threshold here — there's no legitimate reason for a single residential IP to
 * register more than a handful of accounts per minute.
 *
 * Defaults are deliberately generous so families behind shared NAT IPs (offices,
 * residential CGNAT) aren't impacted. Operators on dedicated audiences can tighten
 * via the admin UI.
 */
final class RegistrationRateLimitMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly AbuseCounterStoreInterface $store,
        private readonly int $limit = 5,
        private readonly int $windowSeconds = 600,
        private readonly int $weight = 200,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        if ($context->ip === '') {
            return $next($context);
        }
        $key = 'regrl:ip:' . $context->ip;
        $count = $this->store->hit($key, $this->windowSeconds);

        if ($count > $this->limit) {
            return Decision::deny(
                'Too many registration attempts from this IP.',
                [...$context->signals, new Signal(
                    rule: 'registration_rate_limit',
                    weight: $this->weight,
                    reason: 'Per-IP registration limit exceeded.',
                    meta: ['hits' => $count, 'limit' => $this->limit, 'window' => $this->windowSeconds],
                )],
                $context->score + $this->weight,
            );
        }

        return $next($context);
    }
}
