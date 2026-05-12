<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Middleware\Registration;

use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Signal;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;
use SecurePress\WooCommerce\Services\DisposableEmailRegistry;

/**
 * Registration-side disposable-email check.
 *
 * Behavioural difference from the checkout-side equivalent: on registration we DO
 * deny outright when `$denyOnMatch` is true, because a fresh-account creation has no
 * "legit retry" excuse — a real customer who wants to register with a throwaway
 * email is, in practice, gaming the trial / coupon system.
 *
 * `$denyOnMatch = false` makes the middleware additive (just emit a signal) for
 * sites that prefer to let the {@see \SecurePress\WooCommerce\Services\FraudScoreService}
 * make the call.
 */
final class RegistrationDisposableEmailMiddleware implements WcMiddlewareInterface
{
    public function __construct(
        private readonly DisposableEmailRegistry $registry,
        private readonly bool $denyOnMatch = true,
        private readonly int $weight = 70,
    ) {
    }

    public function handle(DetectionContext $context, callable $next): Decision
    {
        $email = $context->email ?: '';
        if ($email === '' || !$this->registry->isDisposable($email)) {
            return $next($context);
        }

        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        $signal = new Signal(
            rule: 'disposable_email',
            weight: $this->weight,
            reason: 'Registration attempted with a disposable email domain.',
            meta: ['domain' => $domain],
        );

        if ($this->denyOnMatch) {
            return Decision::deny(
                'Disposable email domains are not allowed for registration.',
                [...$context->signals, $signal],
                $context->score + $this->weight,
            );
        }

        return $next($context->withSignal($signal));
    }
}
