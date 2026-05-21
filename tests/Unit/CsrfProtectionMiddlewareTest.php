<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressSentinel\Middleware\CsrfProtectionMiddleware;
use PressSentinel\Tests\Stubs\WpStubState;

final class CsrfProtectionMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var array<string, mixed> */
    private array $postBackup = [];

    /** @var array<string, mixed> */
    private array $getBackup = [];

    protected function setUp(): void
    {
        WpStubState::reset();
        $this->serverBackup = $_SERVER;
        $this->postBackup = $_POST;
        $this->getBackup = $_GET;
        $_SERVER = [];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_POST = $this->postBackup;
        $_GET = $this->getBackup;
        WpStubState::reset();
    }

    public function test_safe_methods_bypass_verification_and_call_next(): void
    {
        $middleware = new CsrfProtectionMiddleware();

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $reached = false;
            $next = static function (array $context) use (&$reached): array {
                $reached = true;

                return $context;
            };

            $result = $middleware->handle(['request' => ['method' => $method]], $next);

            self::assertTrue($reached, "Next should be invoked for {$method}");
            self::assertArrayNotHasKey('csrf', $result, "Safe method {$method} should not annotate context");
            self::assertArrayNotHasKey('halted', $result);
        }
    }

    public function test_lowercase_method_in_context_is_normalized(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        WpStubState::registerNonce(CsrfProtectionMiddleware::DEFAULT_ACTION, 'fresh-token', 1);

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'post',
                    'body' => ['_wpnonce' => 'fresh-token'],
                ],
            ],
            static fn (array $context): array => $context + ['next_called' => true]
        );

        self::assertTrue($result['next_called']);
        self::assertSame(['verified' => true, 'action' => CsrfProtectionMiddleware::DEFAULT_ACTION, 'tick' => 1, 'fresh' => true], $result['csrf']);
    }

    public function test_missing_token_short_circuits_with_403(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        $next = static function (): array {
            self::fail('Pipeline must short-circuit when no token is present.');
        };

        $result = $middleware->handle(['request' => ['method' => 'POST']], $next);

        self::assertTrue($result['halted']);
        self::assertSame(['verified' => false, 'reason' => 'missing-token'], $result['csrf']);
        self::assertSame(['status' => 403, 'message' => 'Invalid CSRF token.'], $result['response']);
    }

    public function test_invalid_token_short_circuits_with_403(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        $next = static function (): array {
            self::fail('Pipeline must short-circuit when token is invalid.');
        };

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'POST',
                    'body' => ['_wpnonce' => 'forged-token'],
                ],
            ],
            $next
        );

        self::assertTrue($result['halted']);
        self::assertSame('invalid-token', $result['csrf']['reason']);
        self::assertFalse($result['csrf']['verified']);
        self::assertSame(403, $result['response']['status']);
    }

    public function test_valid_token_via_default_action_in_post_body_passes(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        WpStubState::registerNonce(CsrfProtectionMiddleware::DEFAULT_ACTION, 'good-token', 1);

        $reached = false;
        $next = static function (array $context) use (&$reached): array {
            $reached = true;

            return $context + ['done' => true];
        };

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'POST',
                    'body' => ['_wpnonce' => 'good-token'],
                ],
            ],
            $next
        );

        self::assertTrue($reached);
        self::assertTrue($result['done']);
        self::assertSame(CsrfProtectionMiddleware::DEFAULT_ACTION, $result['csrf']['action']);
        self::assertSame(1, $result['csrf']['tick']);
        self::assertTrue($result['csrf']['fresh']);
    }

    public function test_rest_action_is_always_accepted_regardless_of_configured_actions(): void
    {
        $middleware = new CsrfProtectionMiddleware(null, ['custom_action']);
        WpStubState::registerNonce(CsrfProtectionMiddleware::REST_ACTION, 'rest-nonce', 1);

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'PATCH',
                    'headers' => ['x-wp-nonce' => 'rest-nonce'],
                ],
            ],
            static fn (array $context): array => $context + ['ok' => true]
        );

        self::assertTrue($result['ok']);
        self::assertSame(CsrfProtectionMiddleware::REST_ACTION, $result['csrf']['action']);
    }

    public function test_token_from_x_csrf_token_header_is_recognized(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        WpStubState::registerNonce(CsrfProtectionMiddleware::DEFAULT_ACTION, 'header-token', 1);

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'POST',
                    'headers' => ['x-csrf-token' => 'header-token'],
                ],
            ],
            static fn (array $context): array => $context + ['ok' => true]
        );

        self::assertTrue($result['ok']);
        self::assertTrue($result['csrf']['verified']);
    }

    public function test_token_from_query_string_is_recognized(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        WpStubState::registerNonce(CsrfProtectionMiddleware::DEFAULT_ACTION, 'query-token', 1);

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'DELETE',
                    'query' => ['_wpnonce' => 'query-token'],
                ],
            ],
            static fn (array $context): array => $context + ['ok' => true]
        );

        self::assertTrue($result['ok']);
        self::assertTrue($result['csrf']['verified']);
    }

    public function test_falls_back_to_superglobals_when_context_is_empty(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        WpStubState::registerNonce(CsrfProtectionMiddleware::DEFAULT_ACTION, 'super-token', 1);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_wpnonce'] = 'super-token';

        $result = $middleware->handle(
            [],
            static fn (array $context): array => $context + ['ok' => true]
        );

        self::assertTrue($result['ok']);
        self::assertTrue($result['csrf']['verified']);
    }

    public function test_unslashes_tokens_pulled_from_superglobals(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        WpStubState::registerNonce(CsrfProtectionMiddleware::DEFAULT_ACTION, 'slashed/token', 1);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_wpnonce'] = addslashes('slashed/token');

        $result = $middleware->handle(
            [],
            static fn (array $context): array => $context + ['ok' => true]
        );

        self::assertTrue($result['ok']);
    }

    public function test_stale_token_is_accepted_and_tick_propagates(): void
    {
        $middleware = new CsrfProtectionMiddleware();
        WpStubState::registerNonce(CsrfProtectionMiddleware::DEFAULT_ACTION, 'aging-token', 2);

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'POST',
                    'body' => ['_wpnonce' => 'aging-token'],
                ],
            ],
            static fn (array $context): array => $context
        );

        self::assertSame(2, $result['csrf']['tick']);
        self::assertFalse($result['csrf']['fresh']);
        self::assertTrue($result['csrf']['verified']);
    }

    public function test_actions_returns_normalized_list_with_rest_action_appended(): void
    {
        $middleware = new CsrfProtectionMiddleware(null, ['custom_a', 'custom_b']);

        self::assertSame(['custom_a', 'custom_b', CsrfProtectionMiddleware::REST_ACTION], $middleware->actions());
    }

    public function test_actions_deduplicates_entries(): void
    {
        $middleware = new CsrfProtectionMiddleware(null, ['custom_a', 'custom_a', CsrfProtectionMiddleware::REST_ACTION]);

        self::assertSame(['custom_a', CsrfProtectionMiddleware::REST_ACTION], $middleware->actions());
    }

    public function test_string_action_constructor_argument_is_honored(): void
    {
        $middleware = new CsrfProtectionMiddleware(null, 'admin_action');
        WpStubState::registerNonce('admin_action', 'admin-token', 1);

        $result = $middleware->handle(
            [
                'request' => [
                    'method' => 'POST',
                    'body' => ['_wpnonce' => 'admin-token'],
                ],
            ],
            static fn (array $context): array => $context + ['ok' => true]
        );

        self::assertTrue($result['ok']);
        self::assertSame('admin_action', $result['csrf']['action']);
    }

    public function test_empty_action_list_falls_back_to_default_and_rest(): void
    {
        $middleware = new CsrfProtectionMiddleware(null, []);

        self::assertSame(
            [CsrfProtectionMiddleware::DEFAULT_ACTION, CsrfProtectionMiddleware::REST_ACTION],
            $middleware->actions()
        );
    }
}
