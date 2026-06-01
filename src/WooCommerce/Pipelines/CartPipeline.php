<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Pipelines;

/**
 * Pipeline configured for WooCommerce cart abuse protection.
 *
 * Standard order:
 *   1. CartVelocityMiddleware  — add-to-cart throttling
 *   2. CouponAbuseMiddleware   — coupon brute-force detection
 */
final class CartPipeline extends AbstractFeaturePipeline
{
}
