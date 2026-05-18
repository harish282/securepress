<?php

declare(strict_types=1);

namespace PressSentinel\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use PressSentinel\Core\Container;
use PressSentinel\Core\Http\RouteGuardRegistry;
use PressSentinel\Core\Middleware\MiddlewareInterface;
use PressSentinel\Core\Middleware\MiddlewareRegistry;
use PressSentinel\Core\Middleware\MiddlewareStack;
use PressSentinel\Facades\Security;
use PressSentinel\Sdk\Csrf\CsrfTokenManager;
use PressSentinel\Tests\Stubs\WpStubState;

final class SecurityFacadeTest extends TestCase
{
    public function test_middleware_queues_and_registers_when_classes_implement_interface(): void
    {
        $container = $this->createMinimalContainer();

        Security::bootstrap($container);
        Security::middleware([SecurityTestMiddlewareAlpha::class, SecurityTestMiddlewareBeta::class]);

        $stack = $container->get(MiddlewareStack::class);
        self::assertSame([
            SecurityTestMiddlewareAlpha::class,
            SecurityTestMiddlewareBeta::class,
        ], $stack->all());

        $registry = $container->get(MiddlewareRegistry::class);
        self::assertSame(SecurityTestMiddlewareAlpha::class, $registry->resolve(SecurityTestMiddlewareAlpha::class));
        self::assertSame(SecurityTestMiddlewareBeta::class, $registry->resolve(SecurityTestMiddlewareBeta::class));
    }

    public function test_middleware_puts_unknown_class_strings_on_stack_but_skips_registry(): void
    {
        $container = $this->createMinimalContainer();

        Security::bootstrap($container);
        $unknownClass = 'PressSentinel\\Tests\\Stub\\NonExistentMiddleware';
        Security::middleware([SecurityTestMiddlewareAlpha::class, $unknownClass]);

        self::assertSame([
            SecurityTestMiddlewareAlpha::class,
            $unknownClass,
        ], $container->get(MiddlewareStack::class)->all());

        self::expectException(\PressSentinel\Core\Middleware\MiddlewareException::class);
        $container->get(MiddlewareRegistry::class)->resolve($unknownClass);
    }

    public function test_protectRoute_registers_patterns(): void
    {
        $container = $this->createMinimalContainer();
        Security::bootstrap($container);

        Security::protectRoute('/admin/export');
        Security::protectRoute('');

        /** @var RouteGuardRegistry $routes */
        $routes = $container->get(RouteGuardRegistry::class);
        self::assertSame(['/admin/export'], $routes->all());
    }

    public function test_signed_url_mints_a_url_that_round_trips_through_verifySignedUrl(): void
    {
        $container = $this->createSignedUrlContainer();
        Security::bootstrap($container);

        $url = Security::signedUrl('/download/123', expires: 600, params: ['file' => 'manual.pdf']);

        $result = Security::verifySignedUrl($url);
        self::assertTrue($result->valid);
        self::assertSame('/download/123', $result->path);
        self::assertSame('manual.pdf', $result->params['file']);
    }

    public function test_signed_url_uses_default_ttl_from_config_when_expires_omitted(): void
    {
        $container = $this->createSignedUrlContainer();
        Security::bootstrap($container);

        $url = Security::signedUrl('/share');
        $result = Security::verifySignedUrl($url);

        self::assertTrue($result->valid);
        self::assertNotNull($result->expiresAt);
    }

    public function test_signed_url_with_one_time_flag_registers_a_consumable_nonce(): void
    {
        $container = $this->createSignedUrlContainer();
        Security::bootstrap($container);

        $url = Security::signedUrl('/reset', expires: 600, params: ['user' => 7], oneTime: true);
        self::assertStringContainsString('n=', $url);

        $store = $container->get(\PressSentinel\Core\Url\NonceStoreInterface::class);
        $params = $this->parseQuery($url);
        $nonce = $params['n'] ?? '';

        self::assertNotSame('', $nonce);
        self::assertTrue($store->consume($nonce));
        self::assertFalse($store->consume($nonce));
    }

    public function test_verify_signed_url_returns_failure_for_unsigned_paths(): void
    {
        $container = $this->createSignedUrlContainer();
        Security::bootstrap($container);

        $result = Security::verifySignedUrl('/no-signature?id=1');

        self::assertFalse($result->valid);
        self::assertSame('missing-signature', $result->reason);
    }

    public function test_verify_csrf_round_trips_through_token_manager(): void
    {
        WpStubState::reset();
        $container = $this->createMinimalContainer();
        $container->singleton(CsrfTokenManager::class, static fn (): CsrfTokenManager => new CsrfTokenManager());
        Security::bootstrap($container);

        $token = Security::csrfToken('my_form');

        self::assertTrue(Security::verifyCsrf($token, 'my_form'));
        self::assertFalse(Security::verifyCsrf('garbage', 'my_form'));
        self::assertFalse(Security::verifyCsrf('', 'my_form'));
        self::assertSame(1, Security::csrfTick($token, 'my_form'));
    }

    public function test_csrf_field_renders_hidden_input(): void
    {
        WpStubState::reset();
        $container = $this->createMinimalContainer();
        $container->singleton(CsrfTokenManager::class, static fn (): CsrfTokenManager => new CsrfTokenManager());
        Security::bootstrap($container);

        $html = Security::csrfField('my_form');

        self::assertStringContainsString('type="hidden"', $html);
        self::assertStringContainsString('name="_wpnonce"', $html);
    }

    public function test_middleware_throws_when_class_exists_but_does_not_implement_middleware(): void
    {
        $container = $this->createMinimalContainer();
        Security::bootstrap($container);

        $this->expectException(LogicException::class);
        Security::middleware([SecurityTestPlainClass::class]);
    }

    public function test_requires_bootstrap_before_use(): void
    {
        $keep = $this->createMinimalContainer();
        Security::bootstrap($keep);

        $property = (new \ReflectionClass(Security::class))->getProperty('container');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $this->expectException(LogicException::class);

        try {
            Security::middleware([SecurityTestMiddlewareAlpha::class]);
        } finally {
            Security::bootstrap($keep);
        }
    }

    private function createMinimalContainer(): Container
    {
        $container = new Container();
        $container->singleton(MiddlewareRegistry::class, static fn (): MiddlewareRegistry => new MiddlewareRegistry());
        $container->singleton(MiddlewareStack::class, static fn (): MiddlewareStack => new MiddlewareStack());
        $container->singleton(RouteGuardRegistry::class, static fn (): RouteGuardRegistry => new RouteGuardRegistry());

        return $container;
    }

    private function createSignedUrlContainer(): Container
    {
        $container = $this->createMinimalContainer();
        $container->singleton(
            \PressSentinel\Core\Config\Config::class,
            static fn (): \PressSentinel\Core\Config\Config => new \PressSentinel\Core\Config\Config()
        );

        $signer = new \PressSentinel\Core\Url\UrlSigner(
            new \PressSentinel\Core\Url\ArraySecretProvider('test-secret-32-bytes-of-entropy-XX')
        );
        $container->set(\PressSentinel\Core\Url\UrlSigner::class, $signer);
        $container->singleton(
            \PressSentinel\Core\Url\NonceStoreInterface::class,
            static fn (): \PressSentinel\Core\Url\NonceStoreInterface => new \PressSentinel\Core\Url\ArrayNonceStore()
        );

        return $container;
    }

    /**
     * @return array<string, string>
     */
    private function parseQuery(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return [];
        }
        parse_str($parts['query'], $parsed);

        $out = [];
        foreach ($parsed as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}


final class SecurityTestMiddlewareAlpha implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        return $next($context);
    }
}

final class SecurityTestMiddlewareBeta implements MiddlewareInterface
{
    public function handle(array $context, callable $next): array
    {
        return $next($context);
    }
}

final class SecurityTestPlainClass
{
}
