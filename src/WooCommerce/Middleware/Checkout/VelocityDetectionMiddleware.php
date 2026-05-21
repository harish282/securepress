<?php

declare(strict_types=1);

namespace PressSentinel\WooCommerce\Middleware\Checkout;

use PressSentinel\WooCommerce\Detection\Decision;
use PressSentinel\WooCommerce\Detection\DetectionContext;
use PressSentinel\WooCommerce\Detection\Signal;
use PressSentinel\WooCommerce\Middleware\WcMiddlewareInterface;
use PressSentinel\WooCommerce\Storage\AbuseCounterStoreInterface;

/**
 * "How many checkout attempts has this IP/email tried in the last window?"
 *
 * Tracks two counters per attempt:
 *  - `ckv:ip:<ip>`     — per source IP
 *  - `ckv:email:<sha>` — per email (hashed; we don't want raw addresses in the
 *    options table)
 *
 * Behaviour:
 *  - Under the soft threshold → emit a small advisory signal, continue.
 *  - At/over the soft threshold → emit a `medium` signal with `weight`.
 *  - At/over the hard threshold → short-circuit with `Decision::deny`.
 *
 * The soft / hard gap exists so we can flag and accumulate evidence before resorting
 * to outright denial. Legitimate customers occasionally retry checkout (network blip,
 * wrong card details) — denying on the first burst would generate too many false
 * positives.
 */
final class VelocityDetectionMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly AbuseCounterStoreInterface $store,
        private readonly int $softThreshold = 3,
        private readonly int $hardThreshold = 8,
        private readonly int $windowSeconds = 120,
        private readonly int $weightSoft = 20,
        private readonly int $weightHard = 60,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        $ipKey = 'ckv:ip:' . $context->ip;
        $ipHits = $this->store->hit($ipKey, $this->windowSeconds);

        $emailHits = 0;
        if (is_string($context->email) && $context->email !== '') {
            $emailKey = 'ckv:email:' . hash('sha256', strtolower($context->email));
            $emailHits = $this->store->hit($emailKey, $this->windowSeconds);
        }

        $hits = max($ipHits, $emailHits);

        if ($hits >= $this->hardThreshold) {
            return Decision::deny(
                'Too many checkout attempts in a short window.',
                [...$context->signals, new Signal(
                    rule: 'velocity',
                    weight: $this->weightHard,
                    reason: 'Hard velocity threshold reached.',
                    meta: ['ip_hits' => $ipHits, 'email_hits' => $emailHits, 'window' => $this->windowSeconds],
                )],
                $context->score + $this->weightHard,
            );
        }

        if ($hits >= $this->softThreshold) {
            $context = $context->withSignal(new Signal(
                rule: 'velocity',
                weight: $this->weightSoft,
                reason: 'Checkout attempts approaching velocity threshold.',
                meta: ['ip_hits' => $ipHits, 'email_hits' => $emailHits, 'window' => $this->windowSeconds],
            ));
        }

        return $next($context);
    }
}
