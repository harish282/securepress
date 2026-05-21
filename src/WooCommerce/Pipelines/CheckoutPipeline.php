<?php

declare(strict_types=1);

namespace PressSentinel\WooCommerce\Pipelines;

/**
 * The configured pipeline for WooCommerce checkout protection.
 *
 * Concrete subclass of {@see AbstractFeaturePipeline}. Exists primarily as a type
 * marker so the DI container has a single class to bind, and so the kernel can
 * type-hint against `CheckoutPipeline` rather than the generic base.
 *
 * The middleware order is contributed by the DI factory in `Plugin.php` — see the
 * binding for {@see CheckoutPipeline::class}. Standard order:
 *
 *   1. Velocity         (fast counter checks; cheapest rejection path)
 *   2. Disposable email (constant-time lookup)
 *   3. Cart similarity  (transient round-trip)
 *   4. Behavior         (timing + country mismatch)
 *   5. FraudScore       (tail — converts running score → decision)
 *
 * The order matters: cheaper / more decisive middleware run first so the most common
 * abuse patterns short-circuit before we spend cycles on the longer-tail checks.
 */
final class CheckoutPipeline extends AbstractFeaturePipeline
{
}
