<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit\WooCommerce\Services;

use PHPUnit\Framework\TestCase;
use PressSentinel\WooCommerce\Detection\Decision;
use PressSentinel\WooCommerce\Detection\DetectionContext;
use PressSentinel\WooCommerce\Detection\Signal;
use PressSentinel\WooCommerce\Services\FraudScoreService;

/**
 * @see \PressSentinel\WooCommerce\Services\FraudScoreService
 */
final class FraudScoreServiceTest extends TestCase
{
    public function test_accept_when_below_challenge_threshold(): void
    {
        $service = new FraudScoreService(challengeThreshold: 40, denyThreshold: 80);
        $ctx = (new DetectionContext(DetectionContext::KIND_CHECKOUT, 'ip', 'ua'))
            ->withSignal(new Signal('a', 10, 'reason'));

        $decision = $service->decide($ctx);

        self::assertSame(Decision::ACCEPT, $decision->outcome);
        self::assertSame(10, $decision->score);
    }

    public function test_challenge_when_between_thresholds(): void
    {
        $service = new FraudScoreService(40, 80);
        $ctx = (new DetectionContext(DetectionContext::KIND_CHECKOUT, 'ip', 'ua'))
            ->withSignal(new Signal('a', 50, 'because A'));

        $decision = $service->decide($ctx);

        self::assertSame(Decision::CHALLENGE, $decision->outcome);
        self::assertSame('because A', $decision->reason);
    }

    public function test_deny_at_or_above_deny_threshold(): void
    {
        $service = new FraudScoreService(40, 80);
        $ctx = (new DetectionContext(DetectionContext::KIND_CHECKOUT, 'ip', 'ua'))
            ->withSignal(new Signal('a', 60, 'r1'))
            ->withSignal(new Signal('b', 30, 'r2'));

        $decision = $service->decide($ctx);

        self::assertSame(Decision::DENY, $decision->outcome);
        // The highest-weight signal contributes the reason.
        self::assertSame('r1', $decision->reason);
    }
}
