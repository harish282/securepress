<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Services;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;

/**
 * Turns an accumulated set of {@see \SecurePress\WooCommerce\Detection\Signal}s into
 * a final {@see Decision}.
 *
 * Two configurable thresholds:
 *
 *  - **`challengeThreshold`** — score ≥ this but < deny → return `CHALLENGE`. The
 *    kernel can interpret that as "require CAPTCHA" / "send confirmation email"
 *    depending on what's wired up.
 *  - **`denyThreshold`**       — score ≥ this → return `DENY`. The kernel maps this to
 *    `wc_add_notice` + abort.
 *
 * The decision reason is the highest-weight signal's reason. Operators reading the
 * audit log can drill into the structured `signals` array for the full breakdown.
 *
 * The scorer is intentionally simple — sum of signed weights — because operators
 * configure weights manually. Anything fancier (logistic regression, anomaly score)
 * would force operators to "tune the model" which is exactly the operational burden
 * a lightweight rule-based system is meant to avoid.
 */
final class FraudScoreService
{
    public function __construct(
        public readonly int $challengeThreshold = 40,
        public readonly int $denyThreshold = 80,
    ) {
    }

    public function decide(DetectionContext $context): Decision
    {
        $score = $context->score;

        if ($score >= $this->denyThreshold) {
            return Decision::deny($this->highestReason($context, 'High fraud score'), $context->signals, $score);
        }
        if ($score >= $this->challengeThreshold) {
            return Decision::challenge($this->highestReason($context, 'Elevated fraud score'), $context->signals, $score);
        }

        return Decision::accept($score, $context->signals);
    }

    private function highestReason(DetectionContext $context, string $fallback): string
    {
        $top = null;
        foreach ($context->signals as $signal) {
            if ($top === null || $signal->weight > $top->weight) {
                $top = $signal;
            }
        }

        return $top?->reason ?: $fallback;
    }
}
