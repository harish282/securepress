<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Pipelines;

/**
 * Pipeline configured for WooCommerce REST API abuse defence.
 *
 * Standard order:
 *   1. SuspiciousRequestMiddleware  — shape filters (empty / scanner UA)
 *   2. ApiRateLimitMiddleware       — per-route + per-IP fixed window
 *
 * No fraud-score tail: API decisions are absolutist (above limit = deny, scanner =
 * deny, otherwise accept). The signal payload still gets attached to the audit log
 * so admins can inspect why a particular request was blocked.
 */
final class ApiPipeline extends AbstractFeaturePipeline
{
}
