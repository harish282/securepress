<?php

declare(strict_types=1);

namespace PressSentinel\WooCommerce;

use PressSentinel\Core\Container;
use PressSentinel\Core\Licensing\LicenseManager;
use PressSentinel\Core\Logging\LoggerInterface;
use PressSentinel\Core\Logging\NullLogger;
use PressSentinel\Core\Support\RequestContext;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Facades\AuditLog;
use PressSentinel\WooCommerce\Admin\WooCommerceProtectionOptions;
use PressSentinel\WooCommerce\Detection\Decision;
use PressSentinel\WooCommerce\Detection\DetectionContext;
use PressSentinel\WooCommerce\Pipelines\ApiPipeline;
use PressSentinel\WooCommerce\Pipelines\CartPipeline;
use PressSentinel\WooCommerce\Middleware\Checkout\BotCheckoutMiddleware;
use PressSentinel\WooCommerce\Pipelines\CheckoutPipeline;
use PressSentinel\WooCommerce\Pipelines\PipelineResult;
use PressSentinel\WooCommerce\Pipelines\RegistrationPipeline;
use PressSentinel\WooCommerce\Services\BehaviorClock;
use PressSentinel\WooCommerce\Storage\AbuseCounterStoreInterface;
use Throwable;

/**
 * Bootstraps WooCommerce protection.
 *
 * Design goal #1: **build nothing you don't need**. The kernel receives only the
 * three cheap services it cannot avoid (license, options, container) and
 * lazy-resolves everything else — pipelines, the clock, the abuse-counter store —
 * inside the hook callbacks. A request that never lands on a checkout submission
 * never instantiates `CheckoutPipeline`, never builds its middleware, never
 * resolves the `DisposableEmailRegistry`. The DI container's singleton cache
 * means even if multiple hook handlers run on the same request the resolution
 * cost is paid exactly once.
 *
 * Design goal #2: **register only relevant hooks**. The current request kind
 * (admin / REST / ajax / cron / frontend) is decided via {@see RequestContext}
 * before any hook is registered:
 *
 *  - REST API hooks register via `rest_api_init`. That WordPress action only
 *    fires during REST requests, so on non-REST requests `rest_pre_dispatch` is
 *    never even added to the global filter table.
 *  - Checkout / registration / cart hooks register only for "commerce-capable"
 *    requests (frontend + ajax). Cron, CLI, admin-only, and REST requests skip
 *    these entirely.
 *  - The kernel itself is wired into `init` (not `plugins_loaded`) so on a
 *    static-page request it isn't instantiated until WP is fully loaded — and
 *    if {@see canRun()} bails (no license / WooCommerce not active) it is the
 *    only object constructed before we short-circuit.
 *
 * Combined effect on a "browse the homepage" request: no pipelines, no
 * middleware, no email registry, no clock, no counter store. Three tiny service
 * resolutions and one `canRun()` check.
 */
final class WooCommerceModule
{
    public const HOOK_CHECKOUT_PROCESS = 'woocommerce_checkout_process';
    public const HOOK_REGISTER_POST = 'woocommerce_register_post';
    public const HOOK_ADD_TO_CART_VALIDATION = 'woocommerce_add_to_cart_validation';
    public const HOOK_COUPON_ERROR = 'woocommerce_coupon_error';
    public const HOOK_REST_API_INIT = 'rest_api_init';
    public const HOOK_REST_PRE_DISPATCH = 'rest_pre_dispatch';
    public const HOOK_RENDER_CHECKOUT_TOKEN = 'woocommerce_after_checkout_form';
    public const HOOK_RENDER_REGISTER_TOKEN = 'woocommerce_register_form_end';

    public const COUPON_COUNTER_TTL = 600;

    public function __construct(
        private readonly LicenseManager $license,
        private readonly WooCommerceProtectionOptions $options,
        private readonly Container $container,
    ) {
    }

    /**
     * Wires the module into WooCommerce / WordPress hooks based on the current
     * request context. Idempotent — safe to call once per request.
     *
     * Returns true if hooks were registered (Pro + WC available + relevant
     * context), false otherwise.
     */
    public function register(): bool
    {
        if (!$this->canRun()) {
            return false;
        }

        $context = RequestContext::detect();

        // REST-only hooks: defer to `rest_api_init` so we don't even land in the
        // global filter table for non-REST requests.
        if ($this->options->isApiEnabled() && ($context === RequestContext::REST || $context === RequestContext::FRONTEND || $context === RequestContext::AJAX)) {
            // On REST requests we're either already past `rest_api_init` or it's
            // about to fire — either way, attaching via `rest_api_init` is safe;
            // WordPress will run the callback the first time the action fires.
            WpHelper::addAction(self::HOOK_REST_API_INIT, [$this, 'registerRestHooks'], 5);
        }

        // Commerce hooks only register on requests where WC events can fire.
        if (RequestContext::isCommerceCapable()) {
            if ($this->options->isCheckoutEnabled()) {
                WpHelper::addAction(self::HOOK_CHECKOUT_PROCESS, [$this, 'onCheckoutProcess'], 1);
                WpHelper::addAction(self::HOOK_RENDER_CHECKOUT_TOKEN, [$this, 'onRenderCheckoutToken'], 999);
            }
            if ($this->options->isRegistrationEnabled()) {
                WpHelper::addAction(self::HOOK_REGISTER_POST, [$this, 'onRegisterPost'], 10, 3);
                WpHelper::addAction(self::HOOK_RENDER_REGISTER_TOKEN, [$this, 'onRenderRegisterToken'], 999);
            }
            if ($this->options->isCartEnabled()) {
                WpHelper::addFilter(self::HOOK_ADD_TO_CART_VALIDATION, [$this, 'onAddToCartValidation'], 10, 1);
                WpHelper::addAction(self::HOOK_COUPON_ERROR, [$this, 'onCouponError'], 10, 2);
            }
        }

        return true;
    }

    /**
     * "Is the module allowed to run on this site?"
     *
     * The cheap checks run first — license status (cached after first call) and
     * the `class_exists('WooCommerce', autoload: false)` test, which avoids the
     * Composer autoloader entirely.
     */
    public function canRun(): bool
    {
        if (!$this->license->isPro()) {
            return false;
        }

        return class_exists('WooCommerce', false) || class_exists('WC_Cart', false);
    }

    /**
     * Internal: attaches `rest_pre_dispatch` once we're inside `rest_api_init`.
     *
     * Public because WordPress reflects on the callable; not part of the
     * developer-facing surface.
     */
    public function registerRestHooks(): void
    {
        WpHelper::addFilter(self::HOOK_REST_PRE_DISPATCH, [$this, 'onRestPreDispatch'], 5, 3);
    }

    // ------------------------------------------------------------------
    // Hook callbacks — each lazy-resolves its pipeline on first invocation.
    // ------------------------------------------------------------------

    public function onCheckoutProcess(): void
    {
        $pipeline = $this->container->get(CheckoutPipeline::class);
        $context = $this->buildCheckoutContext();
        $result = $pipeline->run($context);
        $this->applyDecision($result, fn (string $reason) => $this->emitWcError($reason));
        $this->record($result);
    }

    /**
     * @param string $username
     * @param string $email
     * @param object|null $errors WP_Error reference WooCommerce passes in.
     */
    public function onRegisterPost(string $username, string $email, mixed $errors): void
    {
        $pipeline = $this->container->get(RegistrationPipeline::class);
        $context = $this->buildRegistrationContext($username, $email);
        $result = $pipeline->run($context);

        if ($result->blocked() && is_object($errors) && method_exists($errors, 'add')) {
            $errors->add('presssentinel_registration_blocked', $this->safeMessage($result->decision));
        }
        $this->record($result);
    }

    /**
     * @param mixed $request
     */
    public function onRestPreDispatch(mixed $result, mixed $server, mixed $request): mixed
    {
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return $result;
        }
        $route = (string) $request->get_route();
        // Only police WooCommerce-related namespaces; let WP core, JWT auth, etc. through.
        // This check is BEFORE pipeline resolution — non-WC REST calls don't pay any cost.
        if (!$this->isWooRoute($route)) {
            return $result;
        }

        $pipeline = $this->container->get(ApiPipeline::class);
        $context = $this->buildApiContext($route);
        $outcome = $pipeline->run($context);

        if ($outcome->blocked()) {
            $this->record($outcome);
            if (\class_exists('\\WP_Error')) {
                return new \WP_Error(
                    'presssentinel_api_blocked',
                    $this->safeMessage($outcome->decision),
                    ['status' => 429]
                );
            }
        }

        return $result;
    }

    public function onAddToCartValidation(mixed $passed): mixed
    {
        if ($passed === false) {
            // Another validator already failed; don't waste cycles on our pipeline.
            return $passed;
        }
        $pipeline = $this->container->get(CartPipeline::class);
        $result = $pipeline->run($this->buildCartContext());
        $this->record($result);
        if ($result->blocked()) {
            $this->emitWcNotice($this->safeMessage($result->decision));
            return false;
        }

        return $passed;
    }

    /**
     * `woocommerce_coupon_error` fires on every failed coupon application. We
     * increment a per-IP counter so the CouponAbuseMiddleware can see how many
     * failures occurred. Resolves the counter store on first hit only.
     */
    public function onCouponError(mixed $errMessage, mixed $errCode = null): void
    {
        unset($errMessage, $errCode);
        $ip = $this->detectIp();
        if ($ip === '') {
            return;
        }
        /** @var AbuseCounterStoreInterface $store */
        $store = $this->container->get(AbuseCounterStoreInterface::class);
        $store->hit('coupon:ip:' . $ip, self::COUPON_COUNTER_TTL);
    }

    public function onRenderCheckoutToken(): void
    {
        $token = $this->clock()->startToken();
        $field = (string) ($this->options->all()['registration']['honeypot_field_name'] ?? 'presssentinel_hp');
        echo '<input type="hidden" name="presssentinel_clock_token" value="' . esc_attr($token) . '" />';
        echo '<input type="text" name="' . esc_attr($field) . '" value="" autocomplete="off" tabindex="-1" '
            . 'aria-hidden="true" style="position:absolute !important; left:-9999px !important; height:0; width:0; opacity:0;" />';
    }

    public function onRenderRegisterToken(): void
    {
        $this->onRenderCheckoutToken();
    }

    // ------------------------------------------------------------------
    // Context builders
    // ------------------------------------------------------------------

    private function buildCheckoutContext(): DetectionContext
    {
        $post = $this->postArray();
        $email = isset($post['billing_email']) ? strtolower((string) $post['billing_email']) : null;
        $billingCountry = (string) ($post['billing_country'] ?? '');
        $shippingCountry = (string) ($post['shipping_country'] ?? '');
        $useShipping = !empty($post['ship_to_different_address']);
        $cartItems = $this->currentCartItems();
        $token = (string) ($post['presssentinel_clock_token'] ?? '');

        return new DetectionContext(
            kind: DetectionContext::KIND_CHECKOUT,
            ip: $this->detectIp(),
            userAgent: $this->detectUserAgent(),
            email: $email,
            userId: $this->currentUserId(),
            data: [
                'billing_country' => $billingCountry,
                'shipping_country' => $shippingCountry,
                'use_shipping' => $useShipping,
                'cart_items' => $cartItems,
                'clock_token' => $token,
            ],
            occurredAt: time(),
            referer: $this->detectReferer(),
        );
    }

    private function buildRegistrationContext(string $username, string $email): DetectionContext
    {
        $post = $this->postArray();
        $honeypotField = (string) ($this->options->all()['registration']['honeypot_field_name'] ?? 'presssentinel_hp');
        $token = (string) ($post['presssentinel_clock_token'] ?? '');
        $elapsed = $token !== '' ? $this->clock()->elapsedSeconds($token) : null;

        return new DetectionContext(
            kind: DetectionContext::KIND_REGISTRATION,
            ip: $this->detectIp(),
            userAgent: $this->detectUserAgent(),
            email: strtolower($email),
            userId: null,
            data: [
                'username' => $username,
                $honeypotField => (string) ($post[$honeypotField] ?? ''),
                'form_elapsed_seconds' => $elapsed,
            ],
            occurredAt: time(),
        );
    }

    private function buildApiContext(string $route): DetectionContext
    {
        return new DetectionContext(
            kind: DetectionContext::KIND_API,
            ip: $this->detectIp(),
            userAgent: $this->detectUserAgent(),
            email: null,
            userId: $this->currentUserId(),
            route: $route,
            occurredAt: time(),
        );
    }

    private function buildCartContext(): DetectionContext
    {
        return new DetectionContext(
            kind: DetectionContext::KIND_CART,
            ip: $this->detectIp(),
            userAgent: $this->detectUserAgent(),
            userId: $this->currentUserId(),
            occurredAt: time(),
        );
    }

    // ------------------------------------------------------------------
    // Decision application
    // ------------------------------------------------------------------

    private function applyDecision(PipelineResult $result, callable $reject): void
    {
        if ($result->blocked()) {
            $reject($this->safeMessage($result->decision));
        }
    }

    private function emitWcError(string $message): void
    {
        if (\function_exists('wc_add_notice')) {
            \call_user_func('wc_add_notice', $message, 'error');
        }
    }

    private function emitWcNotice(string $message): void
    {
        if (\function_exists('wc_add_notice')) {
            \call_user_func('wc_add_notice', $message, 'error');
        }
    }

    private function record(PipelineResult $result): void
    {
        try {
            $this->recordSuspiciousCheckoutTiming($result);

            if (!$result->blocked() && !$result->challenged() && $result->context->score === 0) {
                return; // Skip clean traffic — keeps the audit log tidy.
            }

            $action = 'wc.' . $result->context->kind . '.' . $result->decision->outcome;
            $context = [
                'kind' => $result->context->kind,
                'route' => $result->context->route,
                'ip' => $result->context->ip,
                'email_hash' => $result->context->email ? hash('sha256', $result->context->email) : null,
                'score' => $result->context->score,
                'elapsed_ms' => round($result->elapsedMs, 3),
                'reason' => $result->decision->reason,
                'signals' => array_map(static fn ($s) => [
                    'rule' => $s->rule,
                    'weight' => $s->weight,
                    'reason' => $s->reason,
                    'meta' => $s->meta,
                ], $result->context->signals),
            ];

            $result->blocked()
                ? AuditLog::warning($action, $context)
                : AuditLog::info($action, $context);
        } catch (Throwable $e) {
            $this->logger()->warning('WC protection audit log failed: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Lazy resolvers for the lightweight side-services. They're tiny but
    // still skipped on requests that never hit a relevant hook.
    // ------------------------------------------------------------------

    private function recordSuspiciousCheckoutTiming(PipelineResult $result): void
    {
        if ($result->context->kind !== DetectionContext::KIND_CHECKOUT) {
            return;
        }

        foreach ($result->context->signals as $signal) {
            if ($signal->rule !== 'bot_fast_checkout_timing') {
                continue;
            }

            AuditLog::notice('wc.checkout.timing_suspicious', [
                'ip' => $result->context->ip,
                'email_hash' => $result->context->email ? hash('sha256', $result->context->email) : null,
                'elapsed_seconds' => $signal->meta['elapsed_seconds'] ?? null,
                'floor_seconds' => $signal->meta['floor_seconds'] ?? null,
                'timing_action' => $signal->meta['timing_action'] ?? BotCheckoutMiddleware::TIMING_REPORT,
                'fraud_score_delta' => $signal->weight,
                'checkout_blocked' => $result->blocked(),
                'reason' => $signal->reason,
            ]);

            return;
        }
    }

    private function clock(): BehaviorClock
    {
        return $this->container->get(BehaviorClock::class);
    }

    private function logger(): LoggerInterface
    {
        if (!$this->container->has(LoggerInterface::class)) {
            return new NullLogger();
        }

        return $this->container->get(LoggerInterface::class);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Customer-facing message — deliberately vague so the bot operator doesn't learn
     * exactly which heuristic tripped. The audit log keeps the real reason.
     */
    private function safeMessage(Decision $decision): string
    {
        return $decision->reason !== ''
            ? $decision->reason
            : 'We were unable to process this request. Please try again later.';
    }

    private function isWooRoute(string $route): bool
    {
        $route = '/' . ltrim($route, '/');

        return str_starts_with($route, '/wc/')
            || str_starts_with($route, '/wc-analytics/')
            || str_starts_with($route, '/wc-store/');
    }

    /**
     * @return array<string, mixed>
     */
    private function postArray(): array
    {
        return isset($_POST) && is_array($_POST) ? $_POST : [];
    }

    /**
     * @return list<array{product_id:int, variation_id?:int, quantity?:int}>
     */
    private function currentCartItems(): array
    {
        if (!\function_exists('WC')) {
            return [];
        }
        $wc = \call_user_func('WC');
        $cart = is_object($wc) && method_exists($wc, 'cart') ? $wc->cart() : ($wc->cart ?? null);
        if (!is_object($cart) || !method_exists($cart, 'get_cart')) {
            return [];
        }
        $out = [];
        foreach ((array) $cart->get_cart() as $item) {
            if (!is_array($item)) {
                continue;
            }
            $out[] = [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'variation_id' => (int) ($item['variation_id'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 1),
            ];
        }

        return $out;
    }

    private function currentUserId(): ?int
    {
        if (!\function_exists('get_current_user_id')) {
            return null;
        }
        $id = (int) \call_user_func('get_current_user_id');

        return $id > 0 ? $id : null;
    }

    private function detectIp(): string
    {
        $candidates = [
            WpHelper::getServerString('HTTP_CF_CONNECTING_IP'),
            WpHelper::getServerString('HTTP_X_FORWARDED_FOR'),
            WpHelper::getServerString('REMOTE_ADDR'),
        ];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return '';
    }

    private function detectUserAgent(): string
    {
        $ua = WpHelper::getServerString('HTTP_USER_AGENT') ?? '';

        return substr($ua, 0, 512);
    }

    private function detectReferer(): string
    {
        $referer = WpHelper::getServerString('HTTP_REFERER') ?? '';

        return substr($referer, 0, 512);
    }
}
