<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Checkout;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Services\DisposableEmailRegistry;

/**
 * Flags checkouts using disposable / throwaway email addresses.
 *
 * The middleware contributes a **signal**, it does NOT short-circuit. Disposable
 * emails are a strong heuristic for fraud, but plenty of legitimate users hit them
 * (privacy-conscious customers, devs testing prod, returning customers who lost
 * their account email). Denying on this signal alone is the textbook
 * false-positive trap — instead, we contribute weight and let
 * {@see \SecurePress\WooCommerce\Services\FraudScoreService} make the final call by
 * combining with other signals.
 *
 * `weight` is configurable so operators can dial sensitivity from the admin page.
 * Default 35 means it bumps a clean attempt into the "challenge" band without on
 * its own pushing into "deny".
 */
final class DisposableEmailMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly DisposableEmailRegistry $registry,
        private readonly int $weight = 35,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        $email = $context->email ?: '';
        if ($email === '' || !$this->registry->isDisposable($email)) {
            return $next($context);
        }

        $domain = substr($email, strrpos($email, '@') + 1);

        $context = $context->withSignal(new Signal(
            rule: 'disposable_email',
            weight: $this->weight,
            reason: 'Disposable email domain used at checkout.',
            meta: ['domain' => strtolower($domain)],
        ));

        return $next($context);
    }
}
