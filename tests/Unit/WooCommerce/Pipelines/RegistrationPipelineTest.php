<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit\WooCommerce\Pipelines;

use PHPUnit\Framework\TestCase;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Middleware\Registration\HoneypotMiddleware;
use SecurePress\WooCommerce\Middleware\Registration\RegistrationDisposableEmailMiddleware;
use SecurePress\WooCommerce\Middleware\Registration\RegistrationRateLimitMiddleware;
use SecurePress\WooCommerce\Pipelines\RegistrationPipeline;
use SecurePress\WooCommerce\Services\DisposableEmailRegistry;
use SecurePress\WooCommerce\Storage\ArrayAbuseCounterStore;

/**
 * @see \SecurePress\WooCommerce\Pipelines\RegistrationPipeline
 * @see \SecurePress\WooCommerce\Middleware\Registration\HoneypotMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Registration\RegistrationRateLimitMiddleware
 * @see \SecurePress\WooCommerce\Middleware\Registration\RegistrationDisposableEmailMiddleware
 */
final class RegistrationPipelineTest extends TestCase
{
    public function test_clean_registration_accepts(): void
    {
        $pipeline = $this->makePipeline();

        $result = $pipeline->run($this->ctx('alice@example.com'));

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
    }

    public function test_honeypot_filled_denies_immediately(): void
    {
        $pipeline = $this->makePipeline();
        $ctx = $this->ctx('alice@example.com')->withData('securepress_hp', 'spam');

        $result = $pipeline->run($ctx);

        self::assertSame(Decision::DENY, $result->decision->outcome);
        self::assertNotEmpty($result->context->signals);
    }

    public function test_form_too_fast_denies(): void
    {
        $pipeline = $this->makePipeline();
        $ctx = $this->ctx('alice@example.com')->withData('form_elapsed_seconds', 0);

        $result = $pipeline->run($ctx);

        self::assertSame(Decision::DENY, $result->decision->outcome);
    }

    public function test_rate_limit_denies_after_too_many_hits(): void
    {
        $store = new ArrayAbuseCounterStore();
        $pipeline = new RegistrationPipeline([
            new RegistrationRateLimitMiddleware($store, limit: 2, windowSeconds: 60),
        ]);

        for ($i = 0; $i < 2; $i++) {
            self::assertSame(Decision::ACCEPT, $pipeline->run($this->ctx('a@example.com'))->decision->outcome);
        }
        $result = $pipeline->run($this->ctx('a@example.com'));

        self::assertTrue($result->blocked());
    }

    public function test_disposable_email_denies_when_strict(): void
    {
        $pipeline = new RegistrationPipeline([
            new RegistrationDisposableEmailMiddleware(new DisposableEmailRegistry(), denyOnMatch: true),
        ]);

        $result = $pipeline->run($this->ctx('user@mailinator.com'));

        self::assertSame(Decision::DENY, $result->decision->outcome);
    }

    public function test_disposable_email_only_signals_when_lenient(): void
    {
        $pipeline = new RegistrationPipeline([
            new RegistrationDisposableEmailMiddleware(new DisposableEmailRegistry(), denyOnMatch: false),
        ]);

        $result = $pipeline->run($this->ctx('user@mailinator.com'));

        self::assertSame(Decision::ACCEPT, $result->decision->outcome);
        self::assertNotEmpty($result->context->signals);
    }

    private function ctx(string $email): DetectionContext
    {
        return new DetectionContext(
            kind: DetectionContext::KIND_REGISTRATION,
            ip: '203.0.113.5',
            userAgent: 'Mozilla/5.0',
            email: $email,
        );
    }

    private function makePipeline(): RegistrationPipeline
    {
        $store = new ArrayAbuseCounterStore();

        return new RegistrationPipeline([
            new HoneypotMiddleware('securepress_hp', minSecondsToSubmit: 2),
            new RegistrationRateLimitMiddleware($store, limit: 5, windowSeconds: 600),
            new RegistrationDisposableEmailMiddleware(new DisposableEmailRegistry(), denyOnMatch: true),
        ]);
    }
}
