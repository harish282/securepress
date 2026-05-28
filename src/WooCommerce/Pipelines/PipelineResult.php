<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Pipelines;

use NiyiGuard\WooCommerce\Detection\DetectionContext;
use NiyiGuard\WooCommerce\Detection\Decision;

/**
 * Bundles the original {@see DetectionContext}, the final {@see Decision}, and the
 * total time the pipeline took to evaluate.
 *
 * Returned from every pipeline (Checkout, Registration, API, Cart) so the kernel can:
 *  - apply the decision against the WooCommerce flow (block, accept, challenge);
 *  - persist the evidence in the audit log;
 *  - expose timing telemetry to the admin "performance" panel without having to
 *    re-measure anywhere else.
 *
 * Designed to be cheap to construct — there is exactly one of these per checkout /
 * registration / cart action / API call, so it never warrants a pool, builder, or any
 * other allocation gymnastics.
 */
final class PipelineResult
{
    public function __construct(
        public readonly DetectionContext $context,
        public readonly Decision $decision,
        public readonly float $elapsedMs = 0.0,
    ) {
    }

    public function blocked(): bool
    {
        return $this->decision->isBlocked();
    }

    public function challenged(): bool
    {
        return $this->decision->outcome === Decision::CHALLENGE;
    }
}
