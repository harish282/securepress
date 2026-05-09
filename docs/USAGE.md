# SecurePress Usage Guide

This guide shows what SecurePress does once you install and activate it, and how to use the three security primitives that ship in the current build:

1. [Lifecycle: what happens on activation](#lifecycle-what-happens-on-activation)
2. [The middleware pipeline](#the-middleware-pipeline)
3. [CSRF protection](#csrf-protection)
4. [Rate limiting](#rate-limiting)
5. [Signed URLs](#signed-urls)
6. [Configuration reference](#configuration-reference)
7. [Recipes / cookbook](#recipes--cookbook)
8. [Troubleshooting](#troubleshooting)

> **Convention.** All examples use the `SecurePress\Facades\Security` facade. Import it once at the top of your file:
>
> ```php
> use SecurePress\Facades\Security;
> ```

---

## Lifecycle: what happens on activation

When you activate SecurePress (and optionally install the MU loader for earlier load), the following happens:

1. **Bootstrap** (`securepress.php` → `bootstrap/constants.php`)
   - Defines `SECUREPRESS_PATH`, `SECUREPRESS_CONFIG_PATH`, `SECUREPRESS_SRC_PATH`, etc.
   - Registers the PSR-4 autoloader.

2. **`Plugin::boot()`** is called on the `plugins_loaded` hook (or earlier if MU loader is installed):
   - Validates PHP / WordPress requirements via `SystemRequirementsChecker`.
   - Builds the dependency-injection `Container` and registers all services:
     - `Config` (reads `config/plugin.php` + ENV overrides)
     - `LoggerInterface` (file-backed by default → `storage/logs/securepress.log`)
     - `MiddlewareManager`, `MiddlewarePipeline`, `MiddlewareRegistry`, `MiddlewareStack`
     - `RateLimiter`, `RateLimitMiddleware`, `RateLimitStoreInterface` (transient-backed)
     - `CsrfProtectionMiddleware`
     - `UrlSigner`, `SignedUrlMiddleware`, `NonceStoreInterface` (transient-backed), `SecretProviderInterface` (`SECUREPRESS_URL_SECRET` env → `wp_salt('auth')`)
   - Calls `Security::bootstrap($container)` so the static facade can resolve services.
   - Registers admin hooks (notices, plugin row meta).

3. **Your code** registers middleware and protected routes during `init` or earlier:
   ```php
   add_action('plugins_loaded', static function (): void {
       Security::middleware([
           SecurePress\Middleware\RateLimitMiddleware::class,
           SecurePress\Middleware\CsrfProtectionMiddleware::class,
       ]);
       Security::protectRoute('/api/v1/*');
   }, 20);
   ```

4. **Each request** that hits a protected route runs through the middleware pipeline. Middleware can short-circuit the pipeline by writing a `response` payload into context — see [The middleware pipeline](#the-middleware-pipeline) below.

> The current build provides the middleware primitives and three concrete middlewares. The HTTP "kernel" that actually invokes the pipeline against incoming WordPress requests is on the roadmap; until then the middleware is invoked by application code (REST handlers, admin-post actions, custom routers). All the code in this guide is correct for that mode.

---

## The middleware pipeline

Every middleware implements:

```php
interface MiddlewareInterface
{
    public function handle(array $context, callable $next): array;
}
```

The `$context` array is the shared envelope — a middleware can read from it, add data, short-circuit, or pass it on by calling `$next($context)`.

**Standard context keys** the shipped middleware use or write:

| Key | Read by | Written by | Notes |
|---|---|---|---|
| `request.method` | CSRF | — | HTTP method (any case) |
| `request.headers` | CSRF | — | Lowercased header map |
| `request.body` | CSRF | — | Posted form body |
| `request.query` | CSRF, Signed-URL | — | Query string array |
| `request.ip` | Rate-Limit | — | Pre-resolved client IP (override) |
| `request.url` | Signed-URL | — | Full URL or path+query |
| `user.id` | Rate-Limit | — | Authenticated user id (overrides IP bucketing) |
| `csrf` | downstream | CSRF | `verified`, `action`, `tick`, `fresh` |
| `rate_limit` | downstream | Rate-Limit | `allowed`, `key`, `limit`, `hits`, `remaining`, `retry_after` |
| `signed_url` | downstream | Signed-URL | `verified`, `reason`, `path`, `params`, `expires_at`, `one_time`, `consumed` |
| `response` | HTTP layer | any short-circuiting middleware | `status`, `message`, optional `headers` |
| `halted` | HTTP layer | any short-circuiting middleware | `true` when the pipeline was stopped |

### Running the pipeline

```php
use SecurePress\Core\Middleware\MiddlewareManager;

$manager = $container->get(MiddlewareManager::class);
$result  = $manager->handle(
    [
        SecurePress\Middleware\RateLimitMiddleware::class,
        SecurePress\Middleware\CsrfProtectionMiddleware::class,
    ],
    [
        'request' => [
            'method'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'headers' => array_change_key_case(getallheaders() ?: [], CASE_LOWER),
            'body'    => $_POST,
            'query'   => $_GET,
            'url'     => $_SERVER['REQUEST_URI'] ?? '/',
        ],
    ],
);

if (!empty($result['halted'])) {
    status_header((int) $result['response']['status']);
    if (isset($result['response']['headers'])) {
        foreach ($result['response']['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
    }
    wp_send_json(['error' => $result['response']['message']], (int) $result['response']['status']);
}
```

You can wrap that boilerplate in a helper for your own routes — once the kernel ships, this becomes implicit for routes registered via `Security::protectRoute()`.

---

## CSRF protection

`CsrfProtectionMiddleware` verifies a WordPress nonce on every state-changing request (`POST`, `PUT`, `PATCH`, `DELETE`, `CONNECT`, `TRACE` — anything except `GET` / `HEAD` / `OPTIONS`).

### What it checks

The middleware looks for a nonce in this order:

1. `$context['request']['headers']['x-csrf-token']`
2. `$context['request']['headers']['x-wp-nonce']`
3. `$_SERVER['HTTP_X_CSRF_TOKEN']` / `$_SERVER['HTTP_X_WP_NONCE']`
4. `$context['request']['body']['_wpnonce']` / `csrf_token`
5. `$_POST['_wpnonce']` / `$_POST['csrf_token']`
6. `$context['request']['query']['_wpnonce']` / `$_GET['_wpnonce']`

It validates the nonce with `wp_verify_nonce()` against any of the configured actions. By default the middleware accepts:

- `securepress_csrf` (the default action for app-minted forms), and
- `wp_rest` (the standard WordPress REST API action — set automatically when a script loads `wpApiSettings.nonce`).

So **REST API clients sending `X-WP-Nonce` Just Work** without any extra wiring.

### Mint a nonce in a view / form

```php
use SecurePress\Core\Support\WpHelper;
use SecurePress\Middleware\CsrfProtectionMiddleware;

$nonce = WpHelper::createNonce(CsrfProtectionMiddleware::DEFAULT_ACTION);
?>
<form method="post" action="/wp-admin/admin-post.php?action=my_form">
    <input type="hidden" name="_wpnonce" value="<?php echo WpHelper::escapeHtml($nonce); ?>">
    <input type="text" name="message">
    <button type="submit">Send</button>
</form>
```

For REST, you don't need to do anything beyond the standard `wp_localize_script('app', 'wpApiSettings', ['nonce' => wp_create_nonce('wp_rest')])` — the middleware will accept the resulting `X-WP-Nonce` header.

### Use the middleware

#### Globally, on every protected route

```php
add_action('plugins_loaded', static function (): void {
    Security::middleware([
        SecurePress\Middleware\CsrfProtectionMiddleware::class,
    ]);
}, 20);
```

#### Manually, around an `admin-post.php` handler

```php
use SecurePress\Core\Middleware\MiddlewareManager;
use SecurePress\Middleware\CsrfProtectionMiddleware;

add_action('admin_post_my_form', static function () use ($container): void {
    $result = $container->get(MiddlewareManager::class)->handle(
        [CsrfProtectionMiddleware::class],
        [
            'request' => [
                'method' => 'POST',
                'body'   => $_POST,
                'headers' => array_change_key_case(getallheaders() ?: [], CASE_LOWER),
            ],
        ],
    );

    if (!empty($result['halted'])) {
        wp_die(esc_html($result['response']['message']), 'Forbidden', ['response' => 403]);
    }

    // ... handle the form ...
    wp_safe_redirect(admin_url('admin.php?page=my-page'));
    exit;
});
```

### Reading the result

When CSRF passes, downstream code can read:

```php
$result['csrf'] === [
    'verified' => true,
    'action'   => 'securepress_csrf' | 'wp_rest' | ...,
    'tick'     => 1, // 1 = within first half-life (fresh), 2 = second (stale-but-accepted)
    'fresh'    => true,
];
```

Use `tick === 2` to *downgrade trust* — for example, force a re-auth before a destructive action when the nonce is stale.

### Configuring custom actions

If you mint nonces with a different action (e.g. per-feature), construct the middleware manually:

```php
$container->singleton(
    CsrfProtectionMiddleware::class,
    static fn ($c) => new CsrfProtectionMiddleware(
        $c->get(LoggerInterface::class),
        ['my_plugin_export', 'my_plugin_import'] // wp_rest is appended automatically
    )
);
```

---

## Rate limiting

`RateLimitMiddleware` enforces a fixed-window counter via `RateLimiter`. By default it keys requests by:

1. The `Closure` you injected (if any), or
2. `$context['user']['id']` (when authenticated), or
3. `$context['request']['ip']` / `$_SERVER['REMOTE_ADDR']`, or
4. `ip:unknown` (last resort).

> **`X-Forwarded-For` is intentionally ignored.** It is trivially spoofable when the application isn't behind a known proxy. Sites behind a trusted proxy should normalize the IP upstream and pass it via `$context['request']['ip']`.

### Defaults

`config/plugin.php`:

```php
'rate_limit' => [
    'limit'  => 60,   // 60 requests
    'window' => 60,   // per 60 seconds
],
```

These are the global defaults. Per-route limiters are constructed manually (see [recipe](#recipe-strict-login-rate-limit)).

### Use the middleware globally

```php
Security::middleware([
    SecurePress\Middleware\RateLimitMiddleware::class,
]);
```

### Reading the result

When the request is allowed:

```php
$result['rate_limit'] === [
    'allowed'     => true,
    'key'         => 'ip:203.0.113.10' | 'user:42' | ...,
    'limit'       => 60,
    'hits'        => 7,
    'remaining'   => 53,
    'retry_after' => 0,
];
```

When throttled, the middleware short-circuits and writes:

```php
$result['halted']   === true;
$result['response'] === [
    'status'  => 429,
    'message' => 'Too many requests.',
    'headers' => [
        'Retry-After'           => '60',
        'X-RateLimit-Limit'     => '60',
        'X-RateLimit-Remaining' => '0',
    ],
];
```

Your HTTP/kernel layer can render those headers directly (see the [pipeline runner](#running-the-pipeline) above).

### Recipe: strict login rate limit

A separate per-route limiter (5 attempts / minute, bucketed by IP + form action):

```php
use Closure;
use SecurePress\Core\RateLimit\RateLimiter;
use SecurePress\Middleware\RateLimitMiddleware;

$loginLimiter = new RateLimitMiddleware(
    limiter: $container->get(RateLimiter::class),
    logger:  $container->get(LoggerInterface::class),
    limit:   5,
    window:  60,
    keyResolver: static fn (array $context): string => sprintf(
        'login:%s',
        $context['request']['ip'] ?? 'unknown'
    ),
);

add_filter('authenticate', static function ($user, $username) use ($container, $loginLimiter) {
    $result = $container->get(MiddlewareManager::class)
        ->handle([$loginLimiter::class], ['request' => ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']]);

    if (!empty($result['halted'])) {
        return new WP_Error('too_many_attempts', __('Too many login attempts. Try again in a minute.'));
    }
    return $user;
}, 5, 2);
```

> Even if a login succeeds, the counter still ticked — you may want to call `RateLimiter::reset('login:'.$ip)` on success to avoid penalizing legitimate users for past failures.

---

## Signed URLs

`UrlSigner` produces tamper-evident URLs bound to a server-side secret. Signatures are HMAC-SHA256 (256-bit) and cover a canonicalized path + query string. The host/scheme are deliberately excluded so the same URL works across HTTP/HTTPS and CDN mirrors.

Use signed URLs for anything where WordPress nonces aren't a fit:

- **Secure downloads** — `?file=premium-ebook&expires=…&signature=…`
- **Magic-link login** — sign `user=42&expires=…`, log them in on click
- **Password reset** — replaces the homemade hash-in-user_meta dance
- **Email confirmation**
- **Admin invite links** — single-use via the OTU mode
- **Temporary admin access** — "support engineer for 2 hours"
- **Shareable preview links** for drafts

### Mint a URL

```php
use SecurePress\Facades\Security;

// Standard signed URL — replayable until expiry, default TTL from config (3600s)
$path = Security::signedUrl('/download', params: ['file' => 'manual.pdf']);
$url  = home_url($path);

// Custom TTL
$path = Security::signedUrl('/share', expires: 86400, params: ['post' => 42]);

// One-time-use — for password reset / magic link / invite
$path = Security::signedUrl(
    '/auth/magic-link',
    expires: 900,
    params: ['user' => $userId],
    oneTime: true,   // mints a nonce, registers it in NonceStoreInterface
);
$url  = home_url($path);

wp_mail($user->user_email, 'Sign in', "Click to sign in: {$url}");
```

The returned string is a **path + query** (no scheme/host); prefix with `home_url()` or `site_url()` before sending externally.

### Verify a URL

#### Inside a route handler

```php
add_action('init', static function (): void {
    if (!preg_match('#^/auth/magic-link#', $_SERVER['REQUEST_URI'] ?? '')) {
        return;
    }

    $result = Security::verifySignedUrl();   // current request

    if (!$result->valid) {
        if ($result->isExpired()) {
            wp_safe_redirect(home_url('/auth/request-new-link?reason=expired'));
            exit;
        }
        status_header(403);
        wp_die(esc_html('Invalid link.'), 'Forbidden', ['response' => 403]);
    }

    $userId = (int) ($result->params['user'] ?? 0);
    if ($userId > 0) {
        wp_set_auth_cookie($userId);
        wp_safe_redirect(home_url('/dashboard'));
        exit;
    }
});
```

> `Security::verifySignedUrl()` is **stateless** — it does NOT consume one-time nonces. For OTU enforcement, use `SignedUrlMiddleware`.

#### Using `SignedUrlMiddleware` (recommended for OTU)

The middleware verifies + consumes nonces atomically. URLs minted with `oneTime: true` are rejected on replay.

```php
use SecurePress\Middleware\SignedUrlMiddleware;

Security::middleware([
    SignedUrlMiddleware::class, // global
]);

// Or, inline (for a specific endpoint):
$result = $container->get(MiddlewareManager::class)->handle(
    [SignedUrlMiddleware::class],
    [
        'request' => ['url' => $_SERVER['REQUEST_URI'] ?? '/'],
    ],
);
```

### Failure modes → HTTP status

The middleware deliberately distinguishes attacker behavior from user behavior:

| Reason | Status | Meaning |
|---|---|---|
| `valid` | (passes) | Signature OK, not expired, nonce consumed if applicable |
| `expired` | **410 Gone** | Was valid; user should request a fresh link |
| `already-used` | 403 Forbidden | Nonce was consumed (replay or never registered) |
| `tampered` | 403 Forbidden | Signature mismatch |
| `invalid-signature` | 403 Forbidden | Hex/length wrong |
| `missing-signature` | 403 Forbidden | No `signature=` param |
| `malformed` | 403 Forbidden | URL couldn't be parsed |

That `410` for expired links matters for UX: your frontend can say "this link expired, click to send a new one" instead of a generic "forbidden".

### Reading the result

```php
$result['signed_url'] === [
    'verified'   => true,
    'reason'     => 'valid',
    'path'       => '/auth/magic-link',
    'params'     => ['user' => '42', 'n' => '...'],
    'expires_at' => 1700000900,    // Unix timestamp, or null
    'one_time'   => true,
    'consumed'   => true,
];
```

### Security guarantees the signer commits to

| Concern | How it's handled |
|---|---|
| Tamper detection | HMAC-SHA256 over canonical form; constant-time compare via `hash_equals()` |
| Replay across hosts | Host/scheme excluded from signature → URL works on HTTPS/HTTP/CDN mirrors |
| Param re-ordering attacks | Query params sorted by key (URL-decoded) before HMAC |
| Path-case attacks (`/Reset` vs `/reset`) | Path lowercased, repeated slashes collapsed, trailing slash stripped |
| Caller pre-setting `signature=` | Throws `SignedUrlException` at sign time |
| Attacker-extended expiry | `expires` is part of the signed payload — bumping it invalidates signature |
| Forged `expires` (non-numeric) | Detected as `tampered` |
| One-time-use bypass | When middleware has `NonceStoreInterface`, presence of `n=` triggers consume; replays are rejected |
| Hardcoded fallback secret | None — `WpSaltSecretProvider` throws if neither `SECUREPRESS_URL_SECRET` nor `wp_salt('auth')` resolves |

---

## Configuration reference

`config/plugin.php` ships with sensible defaults. Every value can be overridden per environment via an env variable.

| Config key | ENV variable | Default | Used by |
|---|---|---|---|
| `app.env` | `SECUREPRESS_APP_ENV` | `production` | logger / debug toggles |
| `app.debug` | `SECUREPRESS_DEBUG` | `false` | logger verbosity |
| `requirements.php` | `SECUREPRESS_MIN_PHP_VERSION` | `8.2.0` | activation gate |
| `requirements.wordpress` | `SECUREPRESS_MIN_WP_VERSION` | `6.4` | activation gate |
| `logging.channel` | `SECUREPRESS_LOG_CHANNEL` | `file` | `file` or anything else (NullLogger) |
| `logging.level` | `SECUREPRESS_LOG_LEVEL` | `info` | reserved (FileLogger) |
| `logging.file` | `SECUREPRESS_LOG_FILE` | `securepress.log` | log filename under `storage/logs/` |
| `rate_limit.limit` | — | `60` | global RateLimitMiddleware |
| `rate_limit.window` | — | `60` | global RateLimitMiddleware (seconds) |
| `signed_url.ttl_default` | `SECUREPRESS_SIGNED_URL_TTL` | `3600` | `Security::signedUrl()` when no `expires` is passed |
| (none) | `SECUREPRESS_URL_SECRET` | `wp_salt('auth')` | URL signing secret — **set this in production** |

### Recommended production setup

In your server environment (e.g. nginx, Apache, `wp-config.php`, or a `.env` file consumed by `getenv()`):

```bash
export SECUREPRESS_URL_SECRET="<at least 32 random bytes, base64 or hex>"
```

```php
// wp-config.php — alternative if you can't set env vars
putenv('SECUREPRESS_URL_SECRET=' . file_get_contents(ABSPATH . '/.securepress-secret'));
```

Why: the default fallback is `wp_salt('auth')`, which is fine until someone runs WordPress salt rotation — that invalidates **every** outstanding signed URL (password resets, magic links, etc.). A dedicated secret outlives salt rotation.

To rotate the secret intentionally, change the env var and redeploy. All existing signed URLs immediately become invalid, which is the desired behavior on a key compromise.

---

## Recipes / cookbook

### Recipe: protect a custom REST endpoint

```php
add_action('rest_api_init', static function () use ($container): void {
    register_rest_route('myplugin/v1', '/export', [
        'methods'  => 'POST',
        'callback' => static function ($request) use ($container) {
            $manager = $container->get(MiddlewareManager::class);
            $result  = $manager->handle(
                [
                    SecurePress\Middleware\RateLimitMiddleware::class,
                    SecurePress\Middleware\CsrfProtectionMiddleware::class,
                ],
                [
                    'request' => [
                        'method'  => 'POST',
                        'headers' => array_change_key_case($request->get_headers() ?: [], CASE_LOWER),
                        'body'    => $request->get_params(),
                    ],
                    'user' => ['id' => get_current_user_id()],
                ],
            );

            if (!empty($result['halted'])) {
                return new WP_REST_Response(
                    ['error' => $result['response']['message']],
                    (int) $result['response']['status'],
                    $result['response']['headers'] ?? []
                );
            }

            return new WP_REST_Response(['ok' => true]);
        },
        'permission_callback' => '__return_true', // CSRF/nonce already enforced above
    ]);
});
```

The frontend calls it with the standard WP REST nonce:

```js
fetch('/wp-json/myplugin/v1/export', {
    method: 'POST',
    headers: { 'X-WP-Nonce': wpApiSettings.nonce, 'Content-Type': 'application/json' },
    body: JSON.stringify({ format: 'csv' }),
});
```

### Recipe: paid-download protection

```php
// when generating the link (e.g. after a successful purchase)
$path = Security::signedUrl(
    '/download',
    expires: 24 * 3600,                // 24h
    params: ['order' => $orderId, 'sku' => $sku],
);
$downloadUrl = home_url($path);
wp_mail($buyer->user_email, 'Your download', "Get your file: {$downloadUrl}");

// when serving the file
add_action('init', static function (): void {
    if (!str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/download')) {
        return;
    }

    $result = Security::verifySignedUrl();
    if (!$result->valid) {
        status_header($result->isExpired() ? 410 : 403);
        wp_die(esc_html('Invalid or expired download link.'));
    }

    $orderId = (int) ($result->params['order'] ?? 0);
    $sku     = (string) ($result->params['sku'] ?? '');
    // ... stream the file ...
});
```

### Recipe: magic-link login

```php
// /wp-admin/admin-post.php?action=request_magic_link
add_action('admin_post_nopriv_request_magic_link', static function (): void {
    $email = sanitize_email($_POST['email'] ?? '');
    $user  = get_user_by('email', $email);
    if (!$user) {
        wp_safe_redirect(home_url('/login?status=sent')); // don't leak which emails exist
        exit;
    }

    $path = Security::signedUrl(
        '/auth/magic-link',
        expires: 15 * 60,
        params: ['user' => $user->ID],
        oneTime: true,
    );
    wp_mail($user->user_email, 'Sign in', sprintf("Click here: %s", home_url($path)));

    wp_safe_redirect(home_url('/login?status=sent'));
    exit;
});

// the click handler — uses the SignedUrlMiddleware so the OTU nonce is consumed
add_action('init', static function () use ($container): void {
    if (!str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/auth/magic-link')) {
        return;
    }

    $result = $container->get(MiddlewareManager::class)->handle(
        [SecurePress\Middleware\SignedUrlMiddleware::class],
        ['request' => ['url' => $_SERVER['REQUEST_URI']]],
    );

    if (!empty($result['halted'])) {
        wp_safe_redirect(home_url('/login?error=' . $result['signed_url']['reason']));
        exit;
    }

    $userId = (int) ($result['signed_url']['params']['user'] ?? 0);
    wp_set_auth_cookie($userId, /* remember */ true);
    wp_safe_redirect(home_url('/dashboard'));
    exit;
}, 5);
```

### Recipe: throttling WooCommerce checkout

```php
$checkoutLimiter = new RateLimitMiddleware(
    limiter:     $container->get(RateLimiter::class),
    logger:      $container->get(LoggerInterface::class),
    limit:       3,
    window:      60,
    keyResolver: static fn (array $c): string => 'wc_checkout:' . ($c['request']['ip'] ?? 'unknown'),
);

add_action('woocommerce_checkout_process', static function () use ($container, $checkoutLimiter): void {
    $result = $container->get(MiddlewareManager::class)
        ->handle([$checkoutLimiter::class], ['request' => ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']]);

    if (!empty($result['halted'])) {
        wc_add_notice(__('Too many checkout attempts. Wait a minute and try again.', 'mytheme'), 'error');
    }
});
```

### Recipe: CSRF for an `<form action="admin-post.php">`

```php
// in your view
$nonce = WpHelper::createNonce(CsrfProtectionMiddleware::DEFAULT_ACTION);
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="my_form">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
    <input type="text" name="message">
    <button type="submit">Submit</button>
</form>
```

The `admin_post_my_form` handler runs the CSRF middleware as shown in [Use the middleware → manually](#manually-around-an-admin-postphp-handler).

---

## Troubleshooting

### "SecurePress has not been bootstrapped"

Cause: Calling `Security::signedUrl()` / `Security::middleware()` etc. before `Plugin::boot()` ran.
Fix: Wrap the call in `add_action('plugins_loaded', ..., 20)` or later.

### CSRF rejects every REST call

Most likely the frontend is not sending `X-WP-Nonce`. Make sure your script localizes it:

```php
wp_localize_script('my-app', 'myAppData', ['nonce' => wp_create_nonce('wp_rest')]);
```

```js
fetch(url, { headers: { 'X-WP-Nonce': myAppData.nonce } });
```

### Rate limiter blocks legit users behind a shared NAT / corporate proxy

The default key is `REMOTE_ADDR`. Behind a trusted proxy you should set `request.ip` upstream:

```php
$context['request']['ip'] = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR']);
```

…and only do that if you're certain `CF-Connecting-IP` (or whatever header) really is set by your proxy and not by the client.

### Signed URLs all return `tampered` after a salt rotation

You're using the default `wp_salt('auth')` source for the URL secret. Rotating WordPress salts invalidates every outstanding signed URL. Set `SECUREPRESS_URL_SECRET` instead — that pins the secret to the env variable so it survives WP salt rotation. See [Recommended production setup](#recommended-production-setup).

### Signed URL expired but the user clicked it within seconds

Check server clock skew. The signer uses `time()` and the `expires` field is bound into the signature; if your server's clock drifts forward the URL appears expired immediately. Sync via NTP.

### "Cannot resolve URL-signing secret" exception

`SECUREPRESS_URL_SECRET` env var is not set AND `wp_salt()` is not loaded. This typically happens when the plugin runs before WordPress core in a non-standard bootstrap. Either:

- Set `SECUREPRESS_URL_SECRET` in your server environment, or
- Ensure `wp-includes/pluggable.php` is loaded before any code that calls `Security::signedUrl()`.

### Tests fail with "Undefined function wp_verify_nonce"

You're running the plugin's tests without the bootstrap. From the plugin root:

```bash
composer install
vendor/bin/phpunit
```

The test bootstrap (`tests/bootstrap.php`) loads stubs for `wp_verify_nonce`, `wp_salt`, transient functions, etc., backed by `SecurePress\Tests\Stubs\WpStubState`.

---

## What's NOT in this build (yet)

The current build provides the **primitives** (signer, limiter, CSRF middleware, signed-URL middleware, secret/nonce stores, DI bindings, facade). Things still on the roadmap:

- An HTTP **kernel** that automatically dispatches the middleware stack on every request matching a registered route — until then, integrate the pipeline manually as shown in the recipes above.
- **Security headers** middleware (CSP, HSTS, X-Frame-Options, …)
- **Audit logging**
- **2FA** flows
- **Bot/firewall** rules
- Admin **dashboard** UI

See [`ROADMAP_AGILE.md`](../ROADMAP_AGILE.md) for the prioritized backlog.
