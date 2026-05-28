<?php

declare(strict_types=1);

namespace NiyiGuard\Facades;

use Closure;
use LogicException;
use NiyiGuard\Core\Auth\AuthHardeningOptions;
use NiyiGuard\Core\Config\Config;
use NiyiGuard\Core\Container;
use NiyiGuard\Core\Headers\SecurityHeadersOptions;
use NiyiGuard\Core\Http\RouteGuardRegistry;
use NiyiGuard\Core\Integrity\IntegrityOptions;
use NiyiGuard\Core\Middleware\MiddlewareInterface;
use NiyiGuard\Core\Middleware\MiddlewareRegistry;
use NiyiGuard\Core\Middleware\MiddlewareStack;
use NiyiGuard\Core\RateLimit\RateLimitResult;
use NiyiGuard\Core\RateLimit\RateLimiter;
use NiyiGuard\Core\Support\WpHelper;
use NiyiGuard\Core\Url\NonceStoreInterface;
use NiyiGuard\Core\Url\SignedUrlResult;
use NiyiGuard\Core\Url\UrlSigner;
use NiyiGuard\Middleware\CsrfProtectionMiddleware;
use NiyiGuard\Sdk\AuditApi;
use NiyiGuard\Sdk\Csrf\CsrfTokenManager;
use NiyiGuard\Sdk\Events\EventDispatcher;
use NiyiGuard\Sdk\Exceptions\RateLimitExceededException;
use NiyiGuard\Core\Edition\EditionAccess;
use NiyiGuard\Core\Edition\EditionStatus;
use NiyiGuard\Sdk\IntegrityApi;
use NiyiGuard\Sdk\LockoutApi;
use NiyiGuard\Sdk\WooCommerceApi;
use NiyiGuard\Sdk\Routing\RouteBuilder;
use NiyiGuard\Sdk\SessionApi;
use NiyiGuard\Sdk\TwoFactorApi;

/**
 * Static-style entry point for the NiyiGuard developer SDK.
 *
 * Two layers live on this facade:
 *
 *  1. **Top-level shortcuts** for the highest-traffic security primitives —
 *     {@see middleware()}, {@see signedUrl()}, {@see rateLimit()}, {@see throttle()},
 *     {@see csrfToken()}, {@see verifyCsrf()}, {@see protectRoute()}, {@see route()}.
 *     These are the verbs application code reaches for daily.
 *  2. **Sub-facade accessors** — {@see twoFactor()}, {@see sessions()}, {@see lockout()},
 *     {@see audit()}, {@see events()} — that return small, stable wrappers around the
 *     corresponding core services. The split keeps the top-level surface small while
 *     still giving developers a one-import-to-rule-them-all entry point.
 *
 * The facade resolves everything from the container — tests can therefore re-bootstrap
 * with a custom container ({@see bootstrap()}) and swap any sub-component (e.g., the
 * `RateLimiter`'s store) for an in-memory implementation.
 *
 * **Lifecycle.** The plugin bootstraps the facade exactly once in {@see \NiyiGuard\Core\Plugin::register()}.
 * Third-party code must NOT call `bootstrap()` itself; doing so during runtime would orphan
 * any sub-facade instances already in flight.
 *
 * Example wire-up in a third-party plugin:
 *
 * ```php
 * use NiyiGuard\Facades\Security;
 *
 * Security::route('/wp-admin/admin-post.php?action=my_export')
 *     ->capability('manage_options')
 *     ->csrf()
 *     ->rateLimit(limit: 5, window: 60)
 *     ->run(fn () => myExportHandler());
 * ```
 */
final class Security
{
    public const VERSION = '0.9.0';

    private static ?Container $container = null;
    private static ?TwoFactorApi $twoFactorApi = null;
    private static ?SessionApi $sessionApi = null;
    private static ?LockoutApi $lockoutApi = null;
    private static ?AuditApi $auditApi = null;
    private static ?IntegrityApi $integrityApi = null;
    private static ?WooCommerceApi $wooApi = null;

    public static function bootstrap(Container $container): void
    {
        self::$container = $container;
        self::$twoFactorApi = null;
        self::$sessionApi = null;
        self::$lockoutApi = null;
        self::$auditApi = null;
        self::$integrityApi = null;
        self::$wooApi = null;
    }

    public static function version(): string
    {
        return self::VERSION;
    }

    /**
     * Registers middleware classes on the global stack.
     *
     * Each class-string is queued on {@see MiddlewareStack}. If the class is already loadable
     * and implements {@see MiddlewareInterface}, it is also registered on {@see MiddlewareRegistry}
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
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- SDK bootstrap diagnostic.
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
     * @param array<string, scalar|null> $params  Extra query parameters bound into the signature.
     * @param int|null                   $expires TTL seconds from now. `null` uses
     *                                            `signed_url.ttl_default` from config (3600s default).
     * @param bool                       $oneTime When `true`, mints a single-use URL backed by the
     *                                            nonce store. The URL is invalidated the first time
     *                                            it passes through {@see \NiyiGuard\Middleware\SignedUrlMiddleware}.
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
     * Use {@see \NiyiGuard\Middleware\SignedUrlMiddleware} when single-use enforcement is required.
     */
    public static function verifySignedUrl(?string $url = null): SignedUrlResult
    {
        $target = $url;
        if ($target === null) {
            $target = WpHelper::requestUri() ?? '';
        }

        return self::container()->get(UrlSigner::class)->verify($target);
    }

    /**
     * Programmatic rate-limit check.
     *
     * Performs a single attempt against {@see RateLimiter} and returns the full result so
     * callers can inspect remaining quota, retry-after, and the resolved key without
     * inspecting middleware context arrays.
     *
     * ```php
     * $result = Security::rateLimit('api.users.create:' . $user->ID, limit: 10, window: 60);
     * if (!$result->allowed) {
     *     return rest_ensure_response(['error' => 'rate_limited'])->set_status(429);
     * }
     * ```
     */
    public static function rateLimit(string $key, int $limit = 60, int $window = 60): RateLimitResult
    {
        return self::container()->get(RateLimiter::class)->attempt($key, $limit, $window);
    }

    /**
     * Convenience wrapper that runs the supplied callable only if the rate-limit check
     * passes. Throws {@see RateLimitExceededException} otherwise.
     *
     * ```php
     * try {
     *     $report = Security::throttle('report.expensive', 5, 60, fn () => generateReport());
     * } catch (RateLimitExceededException $e) {
     *     // emit a 429 with $e->retryAfter() in the Retry-After header
     * }
     * ```
     *
     * @template T
     * @param Closure(RateLimitResult): T $callback
     * @return T
     *
     * @throws RateLimitExceededException
     */
    public static function throttle(string $key, int $limit, int $window, Closure $callback): mixed
    {
        $result = self::rateLimit($key, $limit, $window);
        if (!$result->allowed) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Rate-limit metadata object, not HTML output.
            throw new RateLimitExceededException($result);
        }

        return $callback($result);
    }

    public static function resetRateLimit(string $key): void
    {
        self::container()->get(RateLimiter::class)->reset($key);
    }

    /**
     * Mints a fresh CSRF token tied to `$action`.
     *
     * The token format matches what {@see CsrfProtectionMiddleware} accepts, so a token
     * minted with `Security::csrfToken('my_form')` will validate against the default
     * middleware chain when the request carries it as `_wpnonce` / `X-CSRF-Token` /
     * `X-WP-Nonce` and the middleware was constructed for `my_form`.
     */
    public static function csrfToken(string $action = CsrfProtectionMiddleware::DEFAULT_ACTION): string
    {
        return self::csrfManager()->mint($action);
    }

    /**
     * Verifies a CSRF token. Returns `true` on success.
     *
     * Unlike the legacy stub this fully implements the verification — no exception is
     * thrown when the SDK is bootstrapped.
     */
    public static function verifyCsrf(string $token, string $action = CsrfProtectionMiddleware::DEFAULT_ACTION): bool
    {
        return self::csrfManager()->verify($token, $action);
    }

    /**
     * Returns the lifecycle tick of the supplied token (1, 2, or 0).
     */
    public static function csrfTick(string $token, string $action = CsrfProtectionMiddleware::DEFAULT_ACTION): int
    {
        return self::csrfManager()->tick($token, $action);
    }

    public static function csrfField(string $action = CsrfProtectionMiddleware::DEFAULT_ACTION, string $name = '_wpnonce'): string
    {
        return self::csrfManager()->field($action, $name);
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
     * Starts a fluent {@see RouteBuilder} for the given path.
     *
     * The path acts as both an audit label and the key under which the route is recorded
     * on {@see RouteGuardRegistry}. It does NOT have to match the actual HTTP URL — for
     * `admin-post.php`-style endpoints, callers usually pass the logical action name
     * (`/admin-post/my_export`) so audit listings stay readable.
     */
    public static function route(string $path): RouteBuilder
    {
        return new RouteBuilder(self::container(), $path);
    }

    public static function twoFactor(): TwoFactorApi
    {
        return self::$twoFactorApi ??= new TwoFactorApi(self::container());
    }

    public static function sessions(): SessionApi
    {
        return self::$sessionApi ??= new SessionApi(self::container());
    }

    public static function lockout(): LockoutApi
    {
        return self::$lockoutApi ??= new LockoutApi(self::container());
    }

    public static function audit(): AuditApi
    {
        return self::$auditApi ??= new AuditApi(self::container());
    }

    public static function integrity(): IntegrityApi
    {
        return self::$integrityApi ??= new IntegrityApi(self::container());
    }

    public static function woo(): WooCommerceApi
    {
        return self::$wooApi ??= new WooCommerceApi(self::container());
    }

    /**
     * True when premium-tier features are available. The free distribution always returns true.
     */
    public static function isPro(): bool
    {
        if (!self::container()->has(EditionAccess::class)) {
            return true;
        }

        return self::container()->get(EditionAccess::class)->isPro();
    }

    /**
     * Edition metadata for admin UI. Free builds return {@see EditionStatus::free()}.
     */
    public static function licenseStatus(): EditionStatus
    {
        if (!self::container()->has(EditionAccess::class)) {
            return EditionStatus::free();
        }

        return self::container()->get(EditionAccess::class)->status();
    }

    public static function events(): EventDispatcher
    {
        return self::container()->get(EventDispatcher::class);
    }

    /**
     * Registers a listener for a NiyiGuard SDK event.
     *
     * Equivalent to `Security::events()->listen($event, $callback)` — the short form
     * exists because event registration shows up frequently in plugin bootstrap code.
     *
     * @return callable():void Unsubscriber.
     */
    public static function on(string $event, callable $callback): callable
    {
        return self::events()->listen($event, $callback);
    }

    /**
     * Fires an SDK event. Listeners registered via {@see on()} run synchronously; the
     * event is also bridged to `do_action('niyiguard.<event>', …)` for WordPress
     * interop.
     */
    public static function fire(string $event, mixed ...$args): void
    {
        self::events()->fire($event, ...$args);
    }

    /**
     * Introspection helper — `true` if the named feature subsystem is enabled.
     *
     * Supported features:
     *
     *  - `auth_hardening` — master switch for the auth-hardening kernel.
     *  - `auth_hardening.lockout` / `.sessions` / `.suspicion` / `.two_factor` / `.notifications`
     *  - `security_headers` — master switch for the headers dispatcher.
     *  - `audit_logging` — bool from `config('audit_log.enabled')`.
     *
     * Unknown feature names always return `false`.
     */
    public static function isFeatureEnabled(string $feature): bool
    {
        $feature = trim($feature);
        if ($feature === '') {
            return false;
        }

        if (str_starts_with($feature, 'auth_hardening')) {
            if (!self::container()->has(AuthHardeningOptions::class)) {
                return false;
            }
            $options = self::container()->get(AuthHardeningOptions::class);
            $tail = substr($feature, strlen('auth_hardening'));
            if ($tail === '' || $tail === '.enabled') {
                return $options->isEnabled();
            }
            if (!str_starts_with($tail, '.')) {
                return false;
            }
            if (!$options->isEnabled()) {
                return false;
            }
            $subkey = substr($tail, 1);
            $sub = $options->all()[$subkey] ?? null;
            if (!is_array($sub)) {
                return false;
            }
            // The `two_factor` section has no per-section toggle: it's a configuration
            // bundle that's always considered active when the master switch is on. Every
            // other section follows the canonical `enabled` convention.
            if ($subkey === 'two_factor') {
                return true;
            }
            return ($sub['enabled'] ?? false) === true;
        }

        if ($feature === 'security_headers') {
            if (!self::container()->has(SecurityHeadersOptions::class)) {
                return false;
            }
            foreach (self::container()->get(SecurityHeadersOptions::class)->all() as $headerOptions) {
                if (is_array($headerOptions) && ($headerOptions['enabled'] ?? false) === true) {
                    return true;
                }
            }

            return false;
        }

        if ($feature === 'audit_logging') {
            return (bool) self::container()->get(Config::class)->get('audit_log.enabled', false);
        }

        if ($feature === 'file_integrity') {
            if (!self::container()->has(IntegrityOptions::class)) {
                return false;
            }
            return self::container()->get(IntegrityOptions::class)->isEnabled();
        }

        if ($feature === 'woocommerce_protection' || $feature === 'woo' || $feature === 'pro') {
            return self::isPro();
        }

        return false;
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
            throw new LogicException('NiyiGuard has not been bootstrapped. Call Security::bootstrap() from the plugin.');
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

    private static function csrfManager(): CsrfTokenManager
    {
        return self::container()->get(CsrfTokenManager::class);
    }
}
