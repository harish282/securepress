<?php

declare(strict_types=1);

namespace SecurePress\Sdk;

use SecurePress\Core\Container;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Pipelines\ApiPipeline;
use SecurePress\WooCommerce\Pipelines\CartPipeline;
use SecurePress\WooCommerce\Pipelines\CheckoutPipeline;
use SecurePress\WooCommerce\Pipelines\PipelineResult;
use SecurePress\WooCommerce\Pipelines\RegistrationPipeline;
use SecurePress\WooCommerce\WooCommerceModule;

/**
 * Developer-facing facade over the WooCommerce protection pipelines.
 *
 * Accessed as `Security::woo()`. Typical use cases:
 *
 *  - Custom checkout / registration flows (block editor, headless front-ends) that
 *    don't fire the canonical WC hooks. Build a {@see DetectionContext} yourself and
 *    call the appropriate pipeline.
 *
 *      ```php
 *      $ctx = new DetectionContext(kind: DetectionContext::KIND_CHECKOUT, ip: ..., ...);
 *      $result = Security::woo()->checkout($ctx);
 *      if ($result->blocked()) { ... }
 *      ```
 *
 *  - Programmatic license checks before exposing extra Pro UI:
 *      `if (! Security::woo()->isAvailable()) { // hide upsell banner }`
 *
 *  - Re-registering hooks from a custom bootstrap (e.g., a plugin that defers
 *    WooCommerce loading) via {@see register()}.
 */
final class WooCommerceApi
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * True if the module is licensed AND WooCommerce is loaded — same semantics as
     * the kernel's internal gate, exposed so callers can branch their own UI.
     */
    public function isAvailable(): bool
    {
        return $this->module()->canRun();
    }

    public function register(): bool
    {
        return $this->module()->register();
    }

    public function checkout(DetectionContext $context): PipelineResult
    {
        return $this->checkoutPipeline()->run($context);
    }

    public function registration(DetectionContext $context): PipelineResult
    {
        return $this->registrationPipeline()->run($context);
    }

    public function api(DetectionContext $context): PipelineResult
    {
        return $this->apiPipeline()->run($context);
    }

    public function cart(DetectionContext $context): PipelineResult
    {
        return $this->cartPipeline()->run($context);
    }

    public function evaluate(string $kind, DetectionContext $context): Decision
    {
        $result = match ($kind) {
            DetectionContext::KIND_CHECKOUT => $this->checkout($context),
            DetectionContext::KIND_REGISTRATION => $this->registration($context),
            DetectionContext::KIND_API => $this->api($context),
            DetectionContext::KIND_CART => $this->cart($context),
            default => null,
        };

        return $result?->decision ?? Decision::accept();
    }

    private function module(): WooCommerceModule
    {
        return $this->container->get(WooCommerceModule::class);
    }

    private function checkoutPipeline(): CheckoutPipeline
    {
        return $this->container->get(CheckoutPipeline::class);
    }

    private function registrationPipeline(): RegistrationPipeline
    {
        return $this->container->get(RegistrationPipeline::class);
    }

    private function apiPipeline(): ApiPipeline
    {
        return $this->container->get(ApiPipeline::class);
    }

    private function cartPipeline(): CartPipeline
    {
        return $this->container->get(CartPipeline::class);
    }
}
