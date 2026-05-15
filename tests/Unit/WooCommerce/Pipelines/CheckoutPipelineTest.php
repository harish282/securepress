<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\WooCommerce\Pipelines;

use PHPUnit\Framework\TestCase;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Middleware\Checkout\BotCheckoutMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\CartSimilarityMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\CheckoutBehaviorMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\DisposableEmailMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\FraudScoreMiddleware;
use SecurePress\WooCommerce\Middleware\Checkout\VelocityDetectionMiddleware;
use SecurePress\WooCommerce\Pipelines\CheckoutPipeline;
use SecurePress\WooCommerce\Services\BehaviorClock;
use SecurePress\WooCommerce\Services\CartFingerprinter;
use SecurePress\WooCommerce\Services\DisposableEmailRegistry;
use SecurePress\WooCommerce\Services\FraudScoreService;
use SecurePress\WooCommerce\Storage\ArrayAbuseCounterStore;

/**
 * @see \SecurePress\WooCommerce\Pipelines\CheckoutPipeline
 * @see \SecurePress\WooCommerce\Middleware\Checkout\VelocityDetectionMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Checkout\DisposableEmailMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Checkout\CheckoutBehaviorMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Checkout\CartSimilarityMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Checkout\FraudScoreMiddleware
 */
final class CheckoutPipelineTest extends TestCase
{
    public function test_clean_request_accepts(): void
    {
        $pipeline = $this->makePipeline();

        $result = $pipeline->run(new DetectionContext(
            kind: DetectionContext::KIND_CHECKOUT,
            ip: '203.0.113.1',
            userAgent: 'Mozilla/5.0',
            email: 'alice@example.com',
        ));

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
    }

    public function test_velocity_hard_threshold_denies_immediately(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new CheckoutPipeline([
            new VelocityDetectionMiddleware($store, softThreshold: 2, hardThreshold: 3, windowSeconds: 60),
        ]);

        for ($i = 0; $i < 3; $i++) {
            $pipeline->run($this->ctx());
        }
        $result = $pipeline->run($this->ctx());

        self::assertTrue($result->blocked());
    }

    public function test_disposable_email_adds_signal_without_blocking(): void
    {
        $pipeline = new CheckoutPipeline([
            new DisposableEmailMiddleware(new DisposableEmailRegistry(), weight: 35),
            new FraudScoreMiddleware(new FraudScoreService(40, 80)),
        ]);

        $result = $pipeline->run($this->ctx('user@mailinator.com'));

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
        self::assertSame(35, $result->decision->score);
    }

    public function test_combination_of_signals_pushes_into_deny(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new CheckoutPipeline([
            new VelocityDetectionMiddleware($store, softThreshold: 1, hardThreshold: 99, windowSeconds: 60, weightSoft: 30),
            new DisposableEmailMiddleware(new DisposableEmailRegistry(), weight: 35),
            new FraudScoreMiddleware(new FraudScoreService(40, 60)),
        ]);

        // 2nd hit triggers velocity soft signal (30), plus disposable email (35) = 65 >= 60 deny
        $pipeline->run($this->ctx('user@mailinator.com'));
        $result = $pipeline->run($this->ctx('user@mailinator.com'));

        self::assertSame(Decision::DENY, $result->decision->outcome);
        self::assertGreaterThanOrEqual(60, $result->decision->score);
    }

    public function test_cart_similarity_denies_on_repeated_fingerprint(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new CheckoutPipeline([
            new CartSimilarityMiddleware(
                new CartFingerprinter(),
                $store,
                softThreshold: 2,
                hardThreshold: 3,
                windowSeconds: 300,
            ),
        ]);
        $cart = [['product_id' => 1, 'quantity' => 1]];

        for ($i = 0; $i < 3; $i++) {
            $r = $pipeline->run($this->ctx()->withData('cart_items', $cart));
        }

        self::assertTrue($r->blocked());
    }

    public function test_bot_middleware_blocks_on_timing_only_when_action_is_block(): void
    {
        $now = 1000;
        $clock = new BehaviorClock('secret', static function () use (&$now): int {
            return $now;
        });
        $clock->mark('tok-1');
        $pipeline = new CheckoutPipeline([
            new BotCheckoutMiddleware(
                $clock,
                minSecondsToSubmit: 5,
                timingAction: BotCheckoutMiddleware::TIMING_BLOCK,
            ),
            new FraudScoreMiddleware(new FraudScoreService(30, 80)),
        ]);

        $ctx = (new DetectionContext(DetectionContext::KIND_CHECKOUT, '203.0.113.1', 'Mozilla/5.0'))
            ->withData('clock_token', 'tok-1');

        $result = $pipeline->run($ctx);

        self::assertSame(Decision::DENY, $result->decision->outcome);
    }

    public function test_country_mismatch_flagged_by_behavior_middleware(): void
    {
        $pipeline = new CheckoutPipeline([
            new CheckoutBehaviorMiddleware(countryMismatchWeight: 50),
            new FraudScoreMiddleware(new FraudScoreService(40, 80)),
        ]);

        $ctx = (new DetectionContext(DetectionContext::KIND_CHECKOUT, '203.0.113.1', 'ua'))
            ->withData('billing_country', 'US')
            ->withData('shipping_country', 'RU')
            ->withData('use_shipping', false);

        $result = $pipeline->run($ctx);

        self::assertSame(Decision::CHALLENGE, $result->decision->outcome);
    }

    private function ctx(?string $email = null): DetectionContext
    {
        return new DetectionContext(
            kind: DetectionContext::KIND_CHECKOUT,
            ip: '203.0.113.10',
            userAgent: 'Mozilla/5.0',
            email: $email,
        );
    }

    private function makePipeline(): CheckoutPipeline
    {
        $store = new ArrayAbuseCounterStore();
        $clock = new BehaviorClock('secret');

        return new CheckoutPipeline([
            new VelocityDetectionMiddleware($store, 3, 8, 120),
            new BotCheckoutMiddleware($clock, minSecondsToSubmit: 0),
            new DisposableEmailMiddleware(new DisposableEmailRegistry()),
            new CartSimilarityMiddleware(new CartFingerprinter(), $store),
            new CheckoutBehaviorMiddleware(),
            new FraudScoreMiddleware(new FraudScoreService(40, 80)),
        ]);
    }
}
