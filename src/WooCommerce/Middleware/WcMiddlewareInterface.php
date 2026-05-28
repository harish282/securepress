<?php

declare(strict_types=1);

namespace NiyiGuard\WooCommerce\Middleware;

use NiyiGuard\WooCommerce\Detection\DetectionContext;
use NiyiGuard\WooCommerce\Detection\Decision;

/**
 * Middleware contract for the WooCommerce pipelines.
 *
 * Distinct from the HTTP-level {@see \NiyiGuard\Core\Middleware\MiddlewareInterface}
 * because the payload here is a typed {@see DetectionContext} rather than a generic
 * associative array, and the return shape is a typed {@see Decision} rather than a
 * mutated context.
 *
 * Implementations get a `$next` callable they can:
 *  - **call** to forward the (possibly enriched) context onward;
 *  - **short-circuit** by returning a `Decision::deny(...)` directly — the rest of the
 *    pipeline is skipped. Used by honeypots and other hard-fail rules.
 *
 * Side-effect rule: middleware MAY append signals via `$context->withSignal(...)`, but
 * must NEVER mutate the global state (no `update_option`, no `wp_die`). Side effects
 * are the kernel's job after a final {@see Decision} comes back from the pipeline.
 */
interface WcMiddlewareInterface
{
    /**
     * @param callable(DetectionContext): Decision $next
     */
    public function handle(DetectionContext $context, callable $next): Decision;
}
