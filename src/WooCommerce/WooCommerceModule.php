<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce;

use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Logging\LoggerInterface;
use SecurePress\Core\Logging\NullLogger;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Facades\AuditLog;
use SecurePress\WooCommerce\Admin\WooCommerceProtectionOptions;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Pipelines\ApiPipeline;
use SecurePress\WooCommerce\Pipelines\CartPipeline;
use SecurePress\WooCommerce\Pipelines\CheckoutPipeline;
use SecurePress\WooCommerce\Pipelines\PipelineResult;
use SecurePress\WooCommerce\Pipelines\RegistrationPipeline;
use SecurePress\WooCommerce\Services\BehaviorClock;
use SecurePress\WooCommerce\Storage\AbuseCounterStoreInterface;
use Throwable;

/**
 * Bootstraps the WooCommerce protection module and wires every pipeline into the
 * relevant WC / WP hook.
 *
 * Lifecycle:
 *
 *  1. The plugin's main `boot()` instantiates this module unconditionally.
 *  2. `register()` checks (a) the Pro license is active and (b) WooCommerce is
 *     loaded. Either failing short-circuits the boot — we do NOT add any hooks
 *     when the module is dormant. Zero overhead for free / non-WC sites.
 *  3. With both checks passing, the kernel binds:
 *     - `woocommerce_checkout_process`           → CheckoutPipeline
 *     - `woocommerce_register_post`              → RegistrationPipeline
 *     - `woocommerce_add_to_cart_validation`     → CartPipeline (velocity)
 *     - `woocommerce_coupon_error`               → coupon-failure counter increment
 *     - `rest_pre_dispatch`                      → ApiPipeline for WC REST routes
 *     - `woocommerce_after_checkout_form` (etc.) → render clock token + honeypot
 *
 * Why a kernel and not a per-pipeline registration: WordPress hooks have edge cases
 * (priority ordering, multiple-args, removable callbacks) that we want to handle in
 * exactly one place. Pipelines stay pure — they only know how to evaluate a context.
 */
final class WooCommerceModule
{
    public const HOOK_CHECKOUT_PROCESS = 'woocommerce_checkout_process';
    public const HOOK_REGISTER_POST = 'woocommerce_register_post';
    public const HOOK_ADD_TO_CART_VALIDATION = 'woocommerce_add_to_cart_validation';
    public const HOOK_COUPON_ERROR = 'woocommerce_coupon_error';
    public const HOOK_REST_PRE_DISPATCH = 'rest_pre_dispatch';
    public const HOOK_RENDER_CHECKOUT_TOKEN = 'woocommerce_after_checkout_form';
    public const HOOK_RENDER_REGISTER_TOKEN = 'woocommerce_register_form_end';

    public const COUPON_COUNTER_TTL = 600;

    public function __construct(
        private readonly LicenseManager $license,
        private readonly WooCommerceProtectionOptions $options,
        private readonly CheckoutPipeline $checkout,
        private readonly RegistrationPipeline $registration,
        private readonly ApiPipeline $api,
        private readonly CartPipeline $cart,
        private readonly BehaviorClock $clock,
        private readonly AbuseCounterStoreInterface $counters,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Wires the module into WooCommerce / WordPress hooks. Idempotent — safe to call
     * once per request.
     *
     * Returns true if hooks were registered (Pro + WC available), false otherwise.
     * Callers (the plugin boot, the SDK) use the boolean to decide whether to surface
     * "module active" UI elements.
     */
    public function register(): bool
    {
        if (!$this->canRun()) {
            return false;
        }

        if ($this->options->isCheckoutEnabled()) {
            WpHelper::addAction(self::HOOK_CHECKOUT_PROCESS, [$this, 'onCheckoutProcess'], 1);
            WpHelper::addAction(self::HOOK_RENDER_CHECKOUT_TOKEN, [$this, 'onRenderCheckoutToken'], 999);
        }
        if ($this->options->isRegistrationEnabled()) {
            WpHelper::addAction(self::HOOK_REGISTER_POST, [$this, 'onRegisterPost'], 10, 3);
            WpHelper::addAction(self::HOOK_RENDER_REGISTER_TOKEN, [$this, 'onRenderRegisterToken'], 999);
        }
        if ($this->options->isApiEnabled()) {
            WpHelper::addFilter(self::HOOK_REST_PRE_DISPATCH, [$this, 'onRestPreDispatch'], 5, 3);
        }
        if ($this->options->isCartEnabled()) {
            WpHelper::addFilter(self::HOOK_ADD_TO_CART_VALIDATION, [$this, 'onAddToCartValidation'], 10, 1);
            WpHelper::addAction(self::HOOK_COUPON_ERROR, [$this, 'onCouponError'], 10, 2);
        }

        return true;
    }

    /**
     * "Is the module allowed to run on this site?"
     *
     * Two gates:
     *  - Pro license active (this is a paid-tier feature);
     *  - WooCommerce class loaded (we can't protect a store that isn't there).
     *
     * The license check is run lazily — `$license->isPro()` caches per-request, so
     * repeated calls are cheap.
     */
    public function canRun(): bool
    {
        if (!$this->license->isPro()) {
            return false;
        }
        if (!class_exists('WooCommerce', false) && !class_exists('WC_Cart', false)) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Hook callbacks
    // ------------------------------------------------------------------

    public function onCheckoutProcess(): void
    {
        $context = $this->buildCheckoutContext();
        $result = $this->checkout->run($context);
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
        $context = $this->buildRegistrationContext($username, $email);
        $result = $this->registration->run($context);

        if ($result->blocked() && is_object($errors) && method_exists($errors, 'add')) {
            $errors->add('securepress_registration_blocked', $this->safeMessage($result->decision));
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
        if (!$this->isWooRoute($route)) {
            return $result;
        }

        $context = $this->buildApiContext($route);
        $outcome = $this->api->run($context);

        if ($outcome->blocked()) {
            $this->record($outcome);
            if (\class_exists('\\WP_Error')) {
                return new \WP_Error(
                    'securepress_api_blocked',
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
            return $passed;
        }
        $context = $this->buildCartContext();
        $result = $this->cart->run($context);
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
     * failures occurred — see {@see CouponAbuseMiddleware}.
     */
    public function onCouponError(mixed $errMessage, mixed $errCode = null): void
    {
        unset($errMessage, $errCode);
        $ip = $this->detectIp();
        if ($ip !== '') {
            $this->counters->hit('coupon:ip:' . $ip, self::COUPON_COUNTER_TTL);
        }
    }

    public function onRenderCheckoutToken(): void
    {
        $token = $this->clock->startToken();
        $field = $this->options->all()['registration']['honeypot_field_name'] ?? 'securepress_hp';
        echo '<input type="hidden" name="securepress_clock_token" value="' . WpHelper::escapeAttribute($token) . '" />';
        echo '<input type="text" name="' . WpHelper::escapeAttribute((string) $field) . '" value="" autocomplete="off" tabindex="-1" '
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
        $token = (string) ($post['securepress_clock_token'] ?? '');

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
        $honeypotField = (string) ($this->options->all()['registration']['honeypot_field_name'] ?? 'securepress_hp');
        $token = (string) ($post['securepress_clock_token'] ?? '');
        $elapsed = $token !== '' ? $this->clock->elapsedSeconds($token) : null;

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
            if (!$result->blocked() && !$result->challenged() && $result->context->score === 0) {
                return; // Avoid flooding the audit log with clean traffic.
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
            $this->logger->warning('WC protection audit log failed: ' . $e->getMessage());
        }
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
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
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
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return is_string($ua) ? substr($ua, 0, 512) : '';
    }

    private function detectReferer(): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        return is_string($referer) ? substr($referer, 0, 512) : '';
    }
}
