<?php

declare(strict_types=1);

namespace SecurePress\Facades;

use LogicException;
use SecurePress\Core\Config\Config;
use SecurePress\Core\Container;
use SecurePress\Core\Http\RouteGuardRegistry;
use SecurePress\Core\Middleware\MiddlewareInterface;
use SecurePress\Core\Middleware\MiddlewareRegistry;
use SecurePress\Core\Middleware\MiddlewareStack;
use SecurePress\Core\Url\NonceStoreInterface;
use SecurePress\Core\Url\SignedUrlResult;
use SecurePress\Core\Url\UrlSigner;

/**
 * Facade-style entry point for security APIs.
 *
 * Usage (after WordPress bootstrap):
 *
 * ```
 * SecurePress\Facades\Security::middleware([RateLimit::class, CsrfProtection::class]);
 * SecurePress\Facades\Security::protectRoute('/admin/export');
 * ```
 *
 * Call {@see Security::bootstrap()} from the plugin; third-party code should not bootstrap manually.
 */
final class Security
{
    private static ?Container $container = null;

    public static function bootstrap(Container $container): void
    {
        self::$container = $container;
    }

    /**
     * Registers middleware classes.
     *
     * Each class-string is queued on {@see MiddlewareStack}. If the class is already loadable and
     * implements {@see MiddlewareInterface}, it is also registered on {@see MiddlewareRegistry}
     * under FQCN. Unknown classes remain on the stack for later validation when the kernel runs.
     *
     * @param array<int, class-string> $middleware
     */
    public static function middleware(array $middleware): void
    {
        $stack = self::stack();
        $stack->push($middleware);

        $registry = self::registry();
        foreach ($middleware as $class) {
            if (!class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, MiddlewareInterface::class)) {
                throw new LogicException(
                    sprintf('Middleware "%s" must implement %s.', $class, MiddlewareInterface::class)
                );
            }
            $registry->register($class, $class);
        }
    }

    /**
     * Mints a signed URL.
     *
     * Returns a path + query string (no scheme/host); prefix with `home_url()` or
     * `site_url()` before sharing externally.
     *
     * @param array<string, scalar|null> $params Extra query parameters bound into the signature.
     * @param int|null                   $expires TTL seconds from now. `null` uses
     *                                            `signed_url.ttl_default` from config (3600s default).
     *                                            Pass an explicit value to override.
     * @param bool                       $oneTime When `true`, mints a single-use URL backed by the
     *                                            nonce store. The URL is invalidated the first time
     *                                            it passes through {@see SignedUrlMiddleware}.
     */
    public static function signedUrl(
        string $path,
        ?int $expires = null,
        array $params = [],
        bool $oneTime = false,
    ): string {
        $signer = self::container()->get(UrlSigner::class);
        $config = self::container()->get(Config::class);
        $ttl = $expires ?? (int) $config->get('signed_url.ttl_default', 3600);

        if ($oneTime) {
            $store = self::container()->get(NonceStoreInterface::class);
            $nonce = bin2hex(random_bytes(16));
            $store->register($nonce, $ttl + 60);
            $params = ['n' => $nonce] + $params;
        }

        return $signer->sign($path, $params, $ttl);
    }

    /**
     * Verifies the signed URL on the current request (or a supplied URL).
     *
     * Pure verification of the signature/expiry only — does not consume one-time-use nonces.
     * Use {@see SignedUrlMiddleware} when single-use enforcement is required.
     */
    public static function verifySignedUrl(?string $url = null): SignedUrlResult
    {
        $target = $url;
        if ($target === null) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? null;
            $target = is_string($requestUri) && $requestUri !== '' ? $requestUri : '';
        }

        return self::container()->get(UrlSigner::class)->verify($target);
    }

    /**
     * Records a URI pattern so the middleware / route kernel can enforce it later.
     */
    public static function protectRoute(string $route): void
    {
        $route = trim($route);
        if ($route === '') {
            return;
        }
        self::routeGuards()->register($route);
    }

    /**
     * @throws LogicException Until the CSRF verifier is wired to WordPress / custom tokens.
     */
    public static function verifyCsrf(): bool
    {
        throw new LogicException('SecurePress::verifyCsrf() is not implemented yet.');
    }

    /**
     * @return list<class-string>
     */
    public static function middlewareStack(): array
    {
        return self::stack()->all();
    }

    private static function container(): Container
    {
        if (self::$container === null) {
            throw new LogicException('SecurePress has not been bootstrapped. Call Security::bootstrap() from the plugin.');
        }

        return self::$container;
    }

    private static function stack(): MiddlewareStack
    {
        return self::container()->get(MiddlewareStack::class);
    }

    private static function registry(): MiddlewareRegistry
    {
        return self::container()->get(MiddlewareRegistry::class);
    }

    private static function routeGuards(): RouteGuardRegistry
    {
        return self::container()->get(RouteGuardRegistry::class);
    }
}
