<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Sdk;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Middleware\CsrfProtectionMiddleware;
use NiyiGuard\Sdk\Csrf\CsrfTokenManager;
use NiyiGuard\Tests\Stubs\WpStubState;

/**
 * @see \NiyiGuard\Sdk\Csrf\CsrfTokenManager
 */
final class CsrfTokenManagerTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    public function test_mint_produces_a_token_that_verifies(): void
    {
        $manager = new CsrfTokenManager();

        $token = $manager->mint();

        self::assertNotSame('', $token);
        self::assertTrue($manager->verify($token));
    }

    public function test_verify_rejects_empty_or_unknown_tokens(): void
    {
        $manager = new CsrfTokenManager();

        self::assertFalse($manager->verify(''));
        self::assertFalse($manager->verify('definitely-not-a-real-nonce'));
    }

    public function test_token_is_scoped_to_the_action(): void
    {
        $manager = new CsrfTokenManager();

        $token = $manager->mint('action_a');

        self::assertTrue($manager->verify($token, 'action_a'));
        self::assertFalse($manager->verify($token, 'action_b'));
    }

    public function test_tick_returns_lifecycle_value(): void
    {
        $manager = new CsrfTokenManager();
        $token = $manager->mint('action_a');

        self::assertSame(1, $manager->tick($token, 'action_a'));
        self::assertSame(0, $manager->tick($token, 'action_b'));

        WpStubState::registerNonce('action_a', 'stale-token', 2);
        self::assertSame(2, $manager->tick('stale-token', 'action_a'));
    }

    public function test_field_renders_an_escaped_hidden_input(): void
    {
        $manager = new CsrfTokenManager();

        $html = $manager->field('action_a');

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('name="_wpnonce"', $html);
        self::assertStringStartsWith('<input', $html);
    }

    public function test_field_honors_custom_name(): void
    {
        $manager = new CsrfTokenManager();

        $html = $manager->field(CsrfProtectionMiddleware::DEFAULT_ACTION, 'csrf_token');

        self::assertStringContainsString('name="csrf_token"', $html);
    }
}
