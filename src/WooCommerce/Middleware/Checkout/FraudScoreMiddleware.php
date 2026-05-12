<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Checkout;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Services\FraudScoreService;

/**
 * Pipeline tail: turn the accumulated signal score into a final {@see Decision}.
 *
 * Always the last middleware in the chain. Earlier middleware may have short-circuited
 * with their own {@see Decision::deny()} (honeypots, velocity hard-stops); when none
 * did, this one inspects the running score and produces the final outcome via the
 * {@see FraudScoreService}.
 *
 * The middleware ignores `$next` on purpose — it's a terminator. If you have something
 * else to do after scoring, register it as a *kernel* post-hook rather than another
 * middleware, so the pipeline composition stays readable.
 */
final class FraudScoreMiddleware implements WcMiddlewareInterface
{
    public function __construct(private readonly FraudScoreService $scorer)
    {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        unset($next);

        return $this->scorer->decide($context);
    }
}
