<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\WooCommerce\Detection;

use PHPUnit\Framework\TestCase;
use NiyiGuard\WooCommerce\Detection\Decision;
use NiyiGuard\WooCommerce\Detection\DetectionContext;
use NiyiGuard\WooCommerce\Detection\Signal;

/**
 * @see \NiyiGuard\WooCommerce\Detection\DetectionContext
 * @see \NiyiGuard\WooCommerce\Detection\Decision
 */
final class DetectionContextTest extends TestCase
{
    public function test_with_data_returns_new_instance(): void
    {
        $original = new DetectionContext(DetectionContext::KIND_CHECKOUT, '1.2.3.4', 'ua');

        $modified = $original->withData('foo', 'bar');

        self::assertNotSame($original, $modified);
        self::assertSame([], $original->data);
        self::assertSame(['foo' => 'bar'], $modified->data);
    }

    public function test_with_signal_accumulates_signal_and_score(): void
    {
        $context = new DetectionContext(DetectionContext::KIND_CHECKOUT, '1.2.3.4', 'ua');

        $next = $context
            ->withSignal(new Signal('a', 10, 'r1'))
            ->withSignal(new Signal('b', 20, 'r2'));

        self::assertCount(2, $next->signals);
        self::assertSame(30, $next->score);
    }

    public function test_get_returns_default_when_missing(): void
    {
        $context = (new DetectionContext(DetectionContext::KIND_CHECKOUT, 'ip', 'ua'))
            ->withData('foo', 'bar');

        self::assertSame('bar', $context->get('foo'));
        self::assertSame('fallback', $context->get('missing', 'fallback'));
    }

    public function test_signals_of_rule_filters_by_name(): void
    {
        $context = (new DetectionContext(DetectionContext::KIND_CHECKOUT, 'ip', 'ua'))
            ->withSignal(new Signal('rate', 5, ''))
            ->withSignal(new Signal('rate', 10, ''))
            ->withSignal(new Signal('email', 20, ''));

        self::assertCount(2, $context->signalsOfRule('rate'));
        self::assertCount(1, $context->signalsOfRule('email'));
        self::assertCount(0, $context->signalsOfRule('missing'));
    }

    public function test_decision_factories_set_outcome(): void
    {
        self::assertSame(Decision::ACCEPT, Decision::accept()->outcome);
        self::assertSame(Decision::CHALLENGE, Decision::challenge('r')->outcome);
        self::assertSame(Decision::DENY, Decision::deny('r')->outcome);
        self::assertTrue(Decision::deny('r')->isBlocked());
        self::assertTrue(Decision::accept()->isAccepted());
    }
}
