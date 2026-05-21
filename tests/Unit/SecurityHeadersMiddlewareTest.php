<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\Headers\HeaderRegistryFactory;
use PressSentinel\Core\Headers\SecurityHeadersOptions;
use PressSentinel\Middleware\SecurityHeadersMiddleware;
use PressSentinel\Tests\Stubs\WpStubState;

final class SecurityHeadersMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_appends_headers_to_empty_response(): void
    {
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle([], static fn (array $context): array => $context);

        $headers = $result['response']['headers'] ?? [];
        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options'] ?? null);
        self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
    }

    public function test_does_not_overwrite_existing_response_headers(): void
    {
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle(
            [],
            static fn (array $context): array => $context + [
                'response' => ['headers' => ['X-Frame-Options' => 'DENY']],
            ]
        );

        self::assertSame(
            'DENY',
            $result['response']['headers']['X-Frame-Options'],
            'route-level header overrides registry-level'
        );
        self::assertSame('nosniff', $result['response']['headers']['X-Content-Type-Options']);
    }

    public function test_returns_context_unchanged_when_all_headers_disabled(): void
    {
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = [
            'hsts' => ['enabled' => false],
            'csp' => ['enabled' => false],
            'x_frame_options' => ['enabled' => false],
            'referrer_policy' => ['enabled' => false],
            'permissions_policy' => ['enabled' => false],
            'x_content_type_options' => ['enabled' => false],
        ];
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle(['marker' => true], static fn (array $context): array => $context);

        self::assertTrue($result['marker']);
        self::assertArrayNotHasKey('response', $result);
    }

    public function test_runs_next_before_appending_headers(): void
    {
        $middleware = $this->makeMiddleware();
        $order = [];

        $next = static function (array $context) use (&$order): array {
            $order[] = 'next';

            return $context + ['next' => true];
        };

        $result = $middleware->handle([], $next);

        self::assertSame(['next'], $order);
        self::assertTrue($result['next']);
        self::assertNotEmpty($result['response']['headers']);
    }

    private function makeMiddleware(): SecurityHeadersMiddleware
    {
        return new SecurityHeadersMiddleware(
            new HeaderRegistryFactory(new SecurityHeadersOptions(new Config()))
        );
    }
}
