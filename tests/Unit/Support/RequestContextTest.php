<?php

declare(strict_types=1);

namespace NiyiGuard\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use NiyiGuard\Core\Support\RequestContext;

/**
 * @see \NiyiGuard\Core\Support\RequestContext
 */
final class RequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::reset();
        unset($_SERVER['REQUEST_URI']);
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        unset($_SERVER['REQUEST_URI']);
    }

    public function test_rest_detected_from_wp_json_uri(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';

        self::assertSame(RequestContext::REST, RequestContext::detect());
        self::assertTrue(RequestContext::isRest());
        self::assertFalse(RequestContext::isCommerceCapable());
    }

    public function test_rest_detected_from_rest_route_query(): void
    {
        $_SERVER['REQUEST_URI'] = '/index.php?rest_route=/wc/v3/customers';

        self::assertSame(RequestContext::REST, RequestContext::detect());
    }

    public function test_frontend_is_default(): void
    {
        $_SERVER['REQUEST_URI'] = '/shop/';

        self::assertSame(RequestContext::FRONTEND, RequestContext::detect());
        self::assertTrue(RequestContext::isFrontend());
        self::assertTrue(RequestContext::isCommerceCapable());
    }

    public function test_result_is_cached_per_request(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/foo';
        self::assertSame(RequestContext::REST, RequestContext::detect());

        $_SERVER['REQUEST_URI'] = '/different/';
        // Without reset(), the cached value sticks.
        self::assertSame(RequestContext::REST, RequestContext::detect());

        RequestContext::reset();
        self::assertSame(RequestContext::FRONTEND, RequestContext::detect());
    }

    public function test_commerce_capable_covers_frontend_and_ajax(): void
    {
        $_SERVER['REQUEST_URI'] = '/shop/';
        self::assertTrue(RequestContext::isCommerceCapable());

        RequestContext::reset();
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/products';
        self::assertFalse(RequestContext::isCommerceCapable());
    }
}
