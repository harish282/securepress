<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Pipelines;

/**
 * Pipeline configured for WooCommerce registration protection.
 *
 * Middleware order set up by the DI factory:
 *   1. Honeypot (fast hard-deny)
 *   2. Per-IP rate limit
 *   3. Disposable-email check (deny-on-match by default)
 *
 * There's no fraud-score tail here on purpose: the registration rules are
 * absolutist by design (anything that trips is automation), so we don't need the
 * accumulation/threshold semantics that checkout uses.
 */
final class RegistrationPipeline extends AbstractFeaturePipeline
{
}
