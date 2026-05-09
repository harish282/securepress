<?php

declare(strict_types=1);

namespace SecurePress\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Headers\HeaderRegistryFactory;
use SecurePress\Core\Headers\SecurityHeadersDispatcher;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Tests\Stubs\WpStubState;

final class SecurityHeadersDispatcherTest extends TestCase
{
    protected function setUp(): void
    {
        WpStubState::reset();
    }

    protected function tearDown(): void
    {
        WpStubState::reset();
    }

    public function test_send_emits_default_headers(): void
    {
        $dispatcher = $this->makeDispatcher($emitted);

        $dispatcher->send();

        self::assertSame('SAMEORIGIN', $emitted['X-Frame-Options'] ?? null);
        self::assertSame('strict-origin-when-cross-origin', $emitted['Referrer-Policy'] ?? null);
        self::assertSame('nosniff', $emitted['X-Content-Type-Options'] ?? null);
        self::assertArrayNotHasKey('Strict-Transport-Security', $emitted);
        self::assertArrayNotHasKey('Content-Security-Policy', $emitted);
        self::assertArrayNotHasKey('Content-Security-Policy-Report-Only', $emitted);
    }

    public function test_send_emits_hsts_when_admin_toggles_it_on(): void
    {
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = [
            'hsts' => ['enabled' => true, 'max_age' => 600, 'include_subdomains' => true, 'preload' => false],
        ];

        $dispatcher = $this->makeDispatcher($emitted);

        $dispatcher->send();

        self::assertSame('max-age=600; includeSubDomains', $emitted['Strict-Transport-Security'] ?? null);
    }

    public function test_send_uses_report_only_csp_header_name_when_configured(): void
    {
        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = [
            'csp' => ['enabled' => true, 'policy' => "default-src 'self'", 'report_only' => true],
        ];

        $dispatcher = $this->makeDispatcher($emitted);

        $dispatcher->send();

        self::assertArrayHasKey('Content-Security-Policy-Report-Only', $emitted);
        self::assertArrayNotHasKey('Content-Security-Policy', $emitted);
    }

    public function test_send_picks_up_admin_changes_on_each_call(): void
    {
        $dispatcher = $this->makeDispatcher($emitted);

        $dispatcher->send();
        self::assertArrayNotHasKey('Strict-Transport-Security', $emitted);

        WpStubState::$options[SecurityHeadersOptions::OPTION_NAME] = [
            'hsts' => ['enabled' => true, 'max_age' => 31536000],
        ];
        $emitted = [];
        $dispatcher->send();

        self::assertSame('max-age=31536000', $emitted['Strict-Transport-Security']);
    }

    /**
     * @param-out array<string, string> $emitted
     */
    private function makeDispatcher(?array &$emitted): SecurityHeadersDispatcher
    {
        $emitted = [];
        $factory = new HeaderRegistryFactory(new SecurityHeadersOptions(new Config()));

        return new SecurityHeadersDispatcher(
            $factory,
            static function (string $name, string $value) use (&$emitted): void {
                $emitted[$name] = $value;
            }
        );
    }
}
