<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\WooCommerce\Middleware;

use PHPUnit\Framework\TestCase;
use SecurePress\Tests\Stubs\WpStubState;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Middleware\Checkout\BotCheckoutMiddleware;
use SecurePress\WooCommerce\Services\BehaviorClock;

/**
 * @see \SecurePress\WooCommerce\Middleware\Checkout\BotCheckoutMiddleware
 */
final class BotCheckoutMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::$transients = [];
    }

    public function test_clean_request_passes_through(): void
    {
        $mw = new BotCheckoutMiddleware(
            new BehaviorClock('secret'),
            honeypotField: 'securepress_hp',
            minSecondsToSubmit: 0, // disable timing check for this test
        );

        $context = $this->ctx('Mozilla/5.0', referer: 'https://store.test/cart');
        $decision = $mw->handle($context, static fn () => Decision::accept());

        self::assertSame(Decision::ACCEPT, $decision->outcome);
    }

    public function test_filled_honeypot_short_circuits_deny(): void
    {
        $mw = new BotCheckoutMiddleware(new BehaviorClock('secret'), 'securepress_hp');
        $context = $this->ctx()->withData('securepress_hp', 'i-am-a-bot');

        $decision = $mw->handle($context, fn () => self::fail('Pipeline should not continue after honeypot deny.'));

        self::assertSame(Decision::DENY, $decision->outcome);
        self::assertSame('bot_honeypot', $decision->signals[array_key_last($decision->signals)]->rule);
    }

    public function test_scanner_user_agent_denies(): void
    {
        $mw = new BotCheckoutMiddleware(new BehaviorClock('secret'));
        $context = $this->ctx('Mozilla sqlmap/1.7');

        $decision = $mw->handle($context, fn () => self::fail('Pipeline should not continue after scanner UA deny.'));

        self::assertSame(Decision::DENY, $decision->outcome);
        self::assertSame('sqlmap', $decision->signals[array_key_last($decision->signals)]->meta['needle']);
    }

    public function test_custom_scanner_pattern_can_be_added(): void
    {
        $mw = new BotCheckoutMiddleware(
            new BehaviorClock('secret'),
            extraScannerUas: ['mycustomscanner'],
        );
        $context = $this->ctx('MyCustomScanner/2.0');

        $decision = $mw->handle($context, fn () => self::fail('Should deny.'));

        self::assertSame(Decision::DENY, $decision->outcome);
        self::assertSame('mycustomscanner', $decision->signals[array_key_last($decision->signals)]->meta['needle']);
    }

    public function test_empty_user_agent_only_signals(): void
    {
        $captured = null;
        $mw = new BotCheckoutMiddleware(
            new BehaviorClock('secret'),
            minSecondsToSubmit: 0,
        );

        $context = $this->ctx('', referer: 'https://store.test/cart');
        $decision = $mw->handle($context, function (DetectionContext $ctx) use (&$captured): Decision {
            $captured = $ctx;
            return Decision::accept();
        });

        self::assertSame(Decision::ACCEPT, $decision->outcome);
        self::assertNotNull($captured);
        $signals = array_filter($captured->signals, static fn ($s) => $s->rule === 'bot_empty_user_agent');
        self::assertCount(1, $signals);
    }

    public function test_submit_too_fast_blocks_only_when_action_is_block(): void
    {
        $now = 1_000_000;
        $clock = new BehaviorClock('secret', static function () use (&$now): int {
            return $now;
        });
        $clock->mark('tok-1');
        $now += 1;

        $mw = new BotCheckoutMiddleware(
            $clock,
            minSecondsToSubmit: 3,
            timingAction: BotCheckoutMiddleware::TIMING_BLOCK,
        );
        $context = $this->ctx('Mozilla/5.0', referer: 'https://store.test/cart')
            ->withData('clock_token', 'tok-1');

        $decision = $mw->handle($context, fn () => self::fail('Should block when timing action is block.'));

        self::assertSame(Decision::DENY, $decision->outcome);
        $last = $decision->signals[array_key_last($decision->signals)];
        self::assertSame('bot_fast_checkout_timing', $last->rule);
        self::assertSame(1, $last->meta['elapsed_seconds']);
        self::assertSame(BotCheckoutMiddleware::TIMING_BLOCK, $last->meta['timing_action']);
    }

    public function test_submit_too_fast_reports_only_by_default(): void
    {
        $now = 1_000_000;
        $clock = new BehaviorClock('secret', static function () use (&$now): int {
            return $now;
        });
        $clock->mark('tok-1');
        $now += 1;

        $mw = new BotCheckoutMiddleware(
            $clock,
            minSecondsToSubmit: 8,
            timingAction: BotCheckoutMiddleware::TIMING_REPORT,
        );
        $context = $this->ctx('Mozilla/5.0', referer: 'https://store.test/cart')
            ->withData('clock_token', 'tok-1');

        $captured = null;
        $decision = $mw->handle($context, function (DetectionContext $ctx) use (&$captured): Decision {
            $captured = $ctx;

            return Decision::accept();
        });

        self::assertSame(Decision::ACCEPT, $decision->outcome);
        self::assertNotNull($captured);
        $timing = array_values(array_filter($captured->signals, static fn ($s) => $s->rule === 'bot_fast_checkout_timing'));
        self::assertCount(1, $timing);
        self::assertSame(BotCheckoutMiddleware::TIMING_REPORT, $timing[0]->meta['timing_action']);
    }

    public function test_timing_disabled_when_floor_is_zero(): void
    {
        $now = 1_000_000;
        $clock = new BehaviorClock('secret', static function () use (&$now): int {
            return $now;
        });
        $clock->mark('tok-1');
        $now += 1;

        $mw = new BotCheckoutMiddleware($clock);
        $context = $this->ctx('Mozilla/5.0', referer: 'https://store.test/cart')
            ->withData('clock_token', 'tok-1');

        $decision = $mw->handle($context, static fn () => Decision::accept());

        self::assertSame(Decision::ACCEPT, $decision->outcome);
    }

    public function test_slow_enough_submission_passes_timing_check(): void
    {
        $now = 1_000_000;
        $clock = new BehaviorClock('secret', static function () use (&$now): int {
            return $now;
        });
        $clock->mark('tok-2');
        $now += 30; // 30 seconds — well over the floor.

        $mw = new BotCheckoutMiddleware($clock, minSecondsToSubmit: 3);
        $context = $this->ctx('Mozilla/5.0', referer: 'https://store.test/cart')
            ->withData('clock_token', 'tok-2');

        $decision = $mw->handle($context, static fn () => Decision::accept());

        self::assertSame(Decision::ACCEPT, $decision->outcome);
    }

    public function test_missing_referer_emits_soft_signal_only(): void
    {
        $captured = null;
        $mw = new BotCheckoutMiddleware(
            new BehaviorClock('secret'),
            minSecondsToSubmit: 0,
        );
        $context = $this->ctx('Mozilla/5.0'); // referer empty by default

        $decision = $mw->handle($context, function (DetectionContext $ctx) use (&$captured): Decision {
            $captured = $ctx;
            return Decision::accept();
        });

        self::assertSame(Decision::ACCEPT, $decision->outcome);
        self::assertNotNull($captured);
        $signals = array_filter($captured->signals, static fn ($s) => $s->rule === 'bot_missing_referer');
        self::assertCount(1, $signals);
    }

    public function test_honeypot_check_runs_before_other_checks(): void
    {
        // Even with a scanner UA, the honeypot rule fires first and reports as
        // the deny reason. This guards against a future refactor accidentally
        // re-ordering the checks.
        $mw = new BotCheckoutMiddleware(new BehaviorClock('secret'));
        $context = $this->ctx('sqlmap')->withData('securepress_hp', 'spam');

        $decision = $mw->handle($context, fn () => self::fail('Should not call next.'));

        self::assertSame('bot_honeypot', $decision->signals[array_key_last($decision->signals)]->rule);
    }

    private function ctx(string $ua = 'Mozilla/5.0', string $referer = ''): DetectionContext
    {
        return new DetectionContext(
            kind: DetectionContext::KIND_CHECKOUT,
            ip: '203.0.113.10',
            userAgent: $ua,
            email: 'alice@example.com',
            referer: $referer,
        );
    }
}
