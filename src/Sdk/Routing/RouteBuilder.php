<?php

declare(strict_types=1);

namespace SecurePress\Sdk\Routing;

use Closure;
use SecurePress\Core\Container;
use SecurePress\Core\Http\RouteGuardRegistry;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Middleware\MiddlewareInterface;
use SecurePress\Core\Middleware\MiddlewarePipeline;
use SecurePress\Core\RateLimit\RateLimiter;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\Url\NonceStoreInterface;
use SecurePress\Core\Url\UrlSigner;
use SecurePress\Middleware\CsrfProtectionMiddleware;
use SecurePress\Middleware\RateLimitMiddleware;
use SecurePress\Middleware\SignedUrlMiddleware;
use SecurePress\Sdk\Exceptions\RouteGuardException;

/**
 * Fluent builder for declarative, route-scoped security guards.
 *
 * The pattern intentionally mirrors Express/Slim middleware chaining: developers describe
 * *what* protections a callback needs and let the SDK assemble + execute the right
 * pipeline. Compared to wiring the underlying middlewares by hand this:
 *
 *  - shares the central {@see RateLimiter}, {@see UrlSigner}, and {@see CsrfProtectionMiddleware}
 *    instances (so behaviour is identical to the global pipeline);
 *  - converts middleware short-circuits into typed {@see RouteGuardException}s so callers
 *    catch one exception instead of inspecting a free-form context array;
 *  - records the path on {@see RouteGuardRegistry} for later introspection — that's how
 *    admin dashboards / audit listings know which routes a plugin has protected.
 *
 * Example:
 *
 * ```php
 * Security::route('/admin/export')
 *     ->capability('manage_options')
 *     ->csrf()
 *     ->rateLimit(10, 60)
 *     ->run(function () {
 *         exportData();
 *     });
 * ```
 *
 * Each guard method returns `$this` so the builder is chainable. A typical request flow:
 *  1. {@see capability()} runs as the first guard (cheap, fails closed for unauthorized).
 *  2. {@see csrf()} appends {@see CsrfProtectionMiddleware} to verify nonces.
 *  3. {@see rateLimit()} appends {@see RateLimitMiddleware} with the supplied window.
 *  4. {@see signedUrl()} appends {@see SignedUrlMiddleware} (with optional one-time-use).
 *  5. {@see run()} executes the pipeline. If every middleware passes, the callback runs.
 *     Otherwise the matching guard throws {@see RouteGuardException}.
 *
 * The builder is single-use by convention but safe to keep around — calling `run()` twice
 * runs the guards twice.
 */
final class RouteBuilder
{
    /** @var list<MiddlewareInterface> */
    private array $middlewares = [];

    /** @var list<string> */
    private array $capabilities = [];

    private ?string $rateLimitKeyOverride = null;

    private bool $registered = false;

    public function __construct(
        private readonly Container $container,
        private readonly string $path,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Requires that the current user has the given WordPress capability.
     *
     * Multiple `capability()` calls AND together — every capability must be granted.
     * Failure raises a 403 {@see RouteGuardException} (guard name: `capability`).
     */
    public function capability(string $capability): self
    {
        $capability = trim($capability);
        if ($capability !== '') {
            $this->capabilities[] = $capability;
        }

        return $this;
    }

    /**
     * Appends {@see CsrfProtectionMiddleware} to the pipeline.
     *
     * Safe-method requests (GET/HEAD/OPTIONS) bypass nonce verification — see the
     * middleware's PHPDoc for the full token-resolution order.
     *
     * @param list<string>|string|null $actions Override the accepted nonce actions. When
     *                                          `null` the default action is used.
     */
    public function csrf(array|string|null $actions = null): self
    {
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $this->middlewares[] = new CsrfProtectionMiddleware(
            $logger,
            $actions ?? CsrfProtectionMiddleware::DEFAULT_ACTION
        );

        return $this;
    }

    /**
     * Appends {@see RateLimitMiddleware} to the pipeline.
     *
     * @param int|null    $limit  Maximum hits per window. `null` keeps the middleware
     *                            default ({@see RateLimitMiddleware::DEFAULT_LIMIT}).
     * @param int|null    $window Window length in seconds. `null` keeps the middleware
     *                            default ({@see RateLimitMiddleware::DEFAULT_WINDOW}).
     * @param string|null $key    Optional explicit bucket key. When supplied, all requests
     *                            on this route share one counter. When `null`, the
     *                            middleware buckets by user id / IP automatically.
     */
    public function rateLimit(?int $limit = null, ?int $window = null, ?string $key = null): self
    {
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $resolvedLimit = $limit ?? RateLimitMiddleware::DEFAULT_LIMIT;
        $resolvedWindow = $window ?? RateLimitMiddleware::DEFAULT_WINDOW;
        $this->rateLimitKeyOverride = $key;

        $keyResolver = null;
        if ($key !== null) {
            $constantKey = 'route:' . $this->path . ':' . $key;
            $keyResolver = static fn (array $context): string => $constantKey;
        }

        $this->middlewares[] = new RateLimitMiddleware(
            $this->container->get(RateLimiter::class),
            $logger,
            $resolvedLimit,
            $resolvedWindow,
            $keyResolver
        );

        return $this;
    }

    /**
     * Appends {@see SignedUrlMiddleware} to the pipeline.
     *
     * Pass `oneTime: true` to also require the URL carries an unused `n` nonce — this is
     * what password-reset / invite-link routes typically want.
     */
    public function signedUrl(bool $oneTime = false): self
    {
        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        $nonceStore = $oneTime && $this->container->has(NonceStoreInterface::class)
            ? $this->container->get(NonceStoreInterface::class)
            : null;

        $this->middlewares[] = new SignedUrlMiddleware(
            $this->container->get(UrlSigner::class),
            $nonceStore,
            $logger
        );

        return $this;
    }

    /**
     * Appends an arbitrary middleware to the pipeline.
     *
     * Useful for plugin-specific guards (e.g., a tenant-scope check) that follow the
     * standard {@see MiddlewareInterface} contract.
     */
    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * Executes every registered guard, then the callback if all of them pass.
     *
     * @template T
     * @param Closure(): T                $callback
     * @param array<string, mixed>        $context Initial context payload merged into the
     *                                             pipeline (e.g., explicit `request` data
     *                                             for unit tests). Empty by default.
     * @return T
     *
     * @throws RouteGuardException When any guard rejects the request.
     */
    public function run(Closure $callback, array $context = []): mixed
    {
        $this->ensureRegistered();
        $this->runCapabilityGuard();

        if ($this->middlewares !== []) {
            $context = $this->container->get(MiddlewarePipeline::class)
                ->process($this->middlewares, $context);

            if (($context['halted'] ?? false) === true) {
                throw $this->translateHalt($context);
            }
        }

        return $callback();
    }

    /**
     * Runs the guards without executing a callback — handy when the protected work is
     * conditional and the caller just needs to know "are we allowed?".
     *
     * @return array<string, mixed> The final context array (so callers can read
     *                              `rate_limit`, `csrf`, etc.).
     *
     * @throws RouteGuardException When any guard rejects.
     */
    public function check(array $context = []): array
    {
        $this->ensureRegistered();
        $this->runCapabilityGuard();

        if ($this->middlewares === []) {
            return $context;
        }

        $context = $this->container->get(MiddlewarePipeline::class)
            ->process($this->middlewares, $context);

        if (($context['halted'] ?? false) === true) {
            throw $this->translateHalt($context);
        }

        return $context;
    }

    /**
     * @return list<MiddlewareInterface>
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function rateLimitKey(): ?string
    {
        return $this->rateLimitKeyOverride;
    }

    private function ensureRegistered(): void
    {
        if ($this->registered) {
            return;
        }

        if ($this->container->has(RouteGuardRegistry::class)) {
            $this->container->get(RouteGuardRegistry::class)->register($this->path);
        }

        $this->registered = true;
    }

    private function runCapabilityGuard(): void
    {
        if ($this->capabilities === []) {
            return;
        }

        foreach ($this->capabilities as $capability) {
            if (!WpHelper::currentUserCan($capability)) {
                throw new RouteGuardException(
                    'capability',
                    403,
                    sprintf('Missing required capability "%s".', $capability)
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function translateHalt(array $context): RouteGuardException
    {
        $response = is_array($context['response'] ?? null) ? $context['response'] : [];
        $status = is_int($response['status'] ?? null) ? (int) $response['status'] : 403;
        $message = is_string($response['message'] ?? null) ? (string) $response['message'] : 'Route guard rejected request.';
        $headers = is_array($response['headers'] ?? null) ? $this->normalizeHeaders($response['headers']) : [];

        $guard = match (true) {
            isset($context['csrf']['verified']) && $context['csrf']['verified'] === false => 'csrf',
            isset($context['rate_limit']['allowed']) && $context['rate_limit']['allowed'] === false => 'rate_limit',
            isset($context['signed_url']['verified']) && $context['signed_url']['verified'] === false => 'signed_url',
            default => 'guard',
        };

        return new RouteGuardException($guard, $status, $message, $headers);
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }
}
