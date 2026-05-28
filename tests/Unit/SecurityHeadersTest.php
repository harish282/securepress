<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Headers\CspHeader;
use NiyiGuard\Core\Headers\HeaderRegistry;
use NiyiGuard\Core\Headers\HstsHeader;
use NiyiGuard\Core\Headers\PermissionsPolicyHeader;
use NiyiGuard\Core\Headers\ReferrerPolicyHeader;
use NiyiGuard\Core\Headers\XContentTypeOptionsHeader;
use NiyiGuard\Core\Headers\XFrameOptionsHeader;

final class SecurityHeadersTest extends TestCase
{
    public function test_hsts_returns_null_when_disabled(): void
    {
        $header = new HstsHeader(enabled: false);

        self::assertSame('Strict-Transport-Security', $header->name());
        self::assertNull($header->value());
    }

    public function test_hsts_emits_max_age_only_by_default(): void
    {
        $header = new HstsHeader(enabled: true, maxAge: 600);

        self::assertSame('max-age=600', $header->value());
    }

    public function test_hsts_appends_subdomain_and_preload_flags(): void
    {
        $header = new HstsHeader(enabled: true, maxAge: 31536000, includeSubDomains: true, preload: true);

        self::assertSame('max-age=31536000; includeSubDomains; preload', $header->value());
    }

    public function test_hsts_clamps_negative_max_age_to_zero(): void
    {
        $header = new HstsHeader(enabled: true, maxAge: -42);

        self::assertSame('max-age=0', $header->value());
    }

    public function test_csp_disabled_returns_null(): void
    {
        $header = new CspHeader(enabled: false, policy: "default-src 'self'");

        self::assertNull($header->value());
    }

    public function test_csp_empty_policy_returns_null(): void
    {
        $header = new CspHeader(enabled: true, policy: '   ');

        self::assertNull($header->value());
    }

    public function test_csp_uses_enforce_header_name_by_default(): void
    {
        $header = new CspHeader(enabled: true, policy: "default-src 'self'", reportOnly: false);

        self::assertSame('Content-Security-Policy', $header->name());
        self::assertSame("default-src 'self'", $header->value());
    }

    public function test_csp_switches_to_report_only_header_name(): void
    {
        $header = new CspHeader(enabled: true, policy: "default-src 'self'", reportOnly: true);

        self::assertSame('Content-Security-Policy-Report-Only', $header->name());
    }

    public function test_x_frame_options_normalizes_value(): void
    {
        $header = new XFrameOptionsHeader(enabled: true, value: 'sameorigin');

        self::assertSame('SAMEORIGIN', $header->value());
    }

    public function test_x_frame_options_falls_back_to_default_for_invalid(): void
    {
        $header = new XFrameOptionsHeader(enabled: true, value: 'allow-from foo');

        self::assertSame('SAMEORIGIN', $header->value());
    }

    public function test_x_frame_options_disabled_returns_null(): void
    {
        $header = new XFrameOptionsHeader(enabled: false, value: 'DENY');

        self::assertNull($header->value());
    }

    public function test_referrer_policy_validates_and_lowercases(): void
    {
        $header = new ReferrerPolicyHeader(enabled: true, policy: 'NO-REFERRER');

        self::assertSame('no-referrer', $header->value());
    }

    public function test_referrer_policy_falls_back_to_default_when_invalid(): void
    {
        $header = new ReferrerPolicyHeader(enabled: true, policy: 'totally-made-up');

        self::assertSame('strict-origin-when-cross-origin', $header->value());
    }

    public function test_permissions_policy_passes_through_when_enabled(): void
    {
        $header = new PermissionsPolicyHeader(enabled: true, policy: 'geolocation=(), camera=()');

        self::assertSame('Permissions-Policy', $header->name());
        self::assertSame('geolocation=(), camera=()', $header->value());
    }

    public function test_permissions_policy_returns_null_when_empty_string(): void
    {
        $header = new PermissionsPolicyHeader(enabled: true, policy: '   ');

        self::assertNull($header->value());
    }

    public function test_x_content_type_options_emits_nosniff(): void
    {
        $header = new XContentTypeOptionsHeader(enabled: true);

        self::assertSame('X-Content-Type-Options', $header->name());
        self::assertSame('nosniff', $header->value());
    }

    public function test_x_content_type_options_returns_null_when_disabled(): void
    {
        $header = new XContentTypeOptionsHeader(enabled: false);

        self::assertNull($header->value());
    }

    public function test_registry_emits_only_active_headers_in_registration_order(): void
    {
        $registry = new HeaderRegistry();
        $registry->register(new HstsHeader(enabled: false));
        $registry->register(new XFrameOptionsHeader(enabled: true, value: 'DENY'));
        $registry->register(new XContentTypeOptionsHeader(enabled: true));

        self::assertSame(
            [
                'X-Frame-Options' => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
            ],
            $registry->emit()
        );
    }

    public function test_registry_clear_resets_state(): void
    {
        $registry = new HeaderRegistry();
        $registry->register(new XContentTypeOptionsHeader(enabled: true));
        self::assertFalse($registry->isEmpty());

        $registry->clear();

        self::assertTrue($registry->isEmpty());
        self::assertSame([], $registry->emit());
    }

    public function test_registry_last_registration_wins_for_duplicate_names(): void
    {
        $registry = new HeaderRegistry();
        $registry->register(new XFrameOptionsHeader(enabled: true, value: 'SAMEORIGIN'));
        $registry->register(new XFrameOptionsHeader(enabled: true, value: 'DENY'));

        self::assertSame(['X-Frame-Options' => 'DENY'], $registry->emit());
    }
}
