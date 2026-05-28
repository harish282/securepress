<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Detection;

/**
 * A single piece of evidence that contributed to (or could contribute to) a fraud score.
 *
 * Signals are the language middleware uses to talk to the scorer: "I observed
 * `disposable_email`, weight 30, because the domain `mailinator.com` is on the
 * disposable list". The {@see \NiyiGuard\WooCommerce\Services\FraudScoreService}
 * aggregates them into a single score and explains the decision back to the operator.
 *
 * Weights are signed integers, not 0..1 floats — keeps comparisons exact, JSON
 * serialisation lossless, and lets a future "trust" signal use a negative weight to
 * pull the score down without complicating the math (e.g. "logged-in returning
 * customer with >5 orders" → `-20`).
 *
 * `meta` carries scanner-specific structured data: which domain, which counter value,
 * which header looked off. Plugins extending the system can store anything they need
 * here; the admin UI just renders it as JSON in the activity log.
 */
final class Signal
{
    public function __construct(
        public readonly string $rule,
        public readonly int $weight,
        public readonly string $reason,
        /** @var array<string, mixed> */
        public readonly array $meta = [],
    ) {
    }
}
