# SecurePress Usage Guide

This guide shows what SecurePress does once you install and activate it, and how to use the security primitives that ship in the current build:

1. [Lifecycle: what happens on activation](#lifecycle-what-happens-on-activation)
2. [The middleware pipeline](#the-middleware-pipeline)
3. [CSRF protection](#csrf-protection)
4. [Rate limiting](#rate-limiting)
5. [Signed URLs](#signed-urls)
6. [Security headers](#security-headers)
7. [Audit logging](#audit-logging)
8. [Authentication hardening](#authentication-hardening)
9. [Security SDK (developer API)](#security-sdk-developer-api)
10. [File integrity monitoring (Pro)](#file-integrity-monitoring-pro)
11. [WooCommerce protection (Pro)](#woocommerce-protection-pro)
12. [Licensing & Pro features](#licensing--pro-features)
13. [Configuration reference](#configuration-reference)
14. [Recipes / cookbook](#recipes--cookbook)
15. [Troubleshooting](#troubleshooting)

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
     - `SecurityHeadersOptions`, `HeaderRegistryFactory`, `SecurityHeadersDispatcher`, `SecurityHeadersMiddleware`, `SecurityHeadersSettingsPage`
     - `SessionSchema`, `SessionRepositoryInterface`, `SessionService`, session pruner (`securepress_sessions_prune` daily cron)
     - `AuthenticationHardeningKernel`, `TwoFactorChallengeController`, two-factor services (`TotpProvider`, `EmailOtpProvider`, `RecoveryCodeService`, `TwoFactorService`), lockout + suspicion detector, `AuthNotifier`, `UserSecurityProfilePage`
   - Calls `Security::bootstrap($container)` and `AuditLog::bootstrap($container)` so the static facades can resolve services.
   - Registers the **security headers dispatcher** on the `send_headers` hook (priority 1) so the configured headers are emitted on every WordPress response.
   - Runs the **audit log schema installer** (idempotent, only does work when `securepress_audit_log_db_version` is older than the bundled version) and registers each enabled audit listener.
   - Runs the **sessions schema installer** (`wp_securepress_sessions`) when authentication hardening is enabled.
   - Schedules the **daily audit log pruner** WP cron event and, when sessions are enabled, the **daily session pruner** (`securepress_sessions_prune`).
   - Registers the **authentication hardening kernel** (login lockout, 2FA gate, session tracking, suspicious-login alerts) when `auth_hardening.enabled` is true.
   - Registers admin hooks (notices, plugin row meta, the top-level **Secure Press** menu with submenus for Dashboard, Authentication, Security Headers, File Integrity, Audit Logs, WooCommerce Protection, and License, plus the user-facing **Account Security** top-level menu when auth hardening is enabled).

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

## Security headers

The Security Headers Manager lets administrators emit a curated set of HTTP security response headers on every WordPress response. Each header is independently toggleable from the admin UI; the same registry powers both the global dispatcher and an opt-in middleware for per-route customisation.

### Where to find it

After activating the plugin, log in as an administrator and visit:

```
Secure Press → Security Headers
```

You'll see one section per supported header:

| Header | Default | Why this default |
|---|---|---|
| `Strict-Transport-Security` (HSTS) | **Off** | Hard-locks the domain to HTTPS for a year+ once enabled. Enable only when you've verified HTTPS works site-wide; never enable `preload` casually. |
| `Content-Security-Policy` (CSP) | **Off** (Report-Only when on) | Most likely to break sites — start in Report-Only, watch the browser console / your reporting endpoint, then flip to enforce. |
| `X-Frame-Options` | **On** — `SAMEORIGIN` | Blocks clickjacking from third-party origins while keeping the WP customizer / preview iframes working. |
| `Referrer-Policy` | **On** — `strict-origin-when-cross-origin` | Modern browser default; sends full URL same-origin, only the origin to less-secure cross-origin destinations. |
| `Permissions-Policy` | **On** — denies geolocation, camera, microphone, payment, usb, sensors, FLoC | Most content sites don't need any of these APIs. Includes `interest-cohort=()` to opt out of Google FLoC / Topics. |
| `X-Content-Type-Options` | **On** — `nosniff` | Disables MIME-sniffing. Effectively zero risk of breaking anything. |

### How emission works

```
[WP request] → send_headers hook (priority 1)
        ↓
SecurityHeadersDispatcher::send()
        ↓
HeaderRegistryFactory::make()  ←  SecurityHeadersOptions::all()
        ↓                                 ↓
HeaderRegistry::emit()        ←  config/plugin.php  +  wp_options[securepress_security_headers]
        ↓
header('Strict-Transport-Security: …')
header('X-Frame-Options: …')
…
```

The dispatcher hooks `send_headers` at priority `1`, so it runs before any plugin or theme that uses the default priority of `10` and gets stomped by anything later that sets the same header on purpose. It is also guarded by `headers_sent()` — if WordPress has already flushed output, the dispatcher silently does nothing rather than triggering a PHP warning.

### Toggling from the admin UI

For each header section:

1. Tick **Enable** to turn the header on.
2. Adjust the section-specific fields (max-age, policy string, allowlist).
3. Click **Save Changes**.

Save is handled by the standard WordPress Settings API, which already gives you:

- A nonce for CSRF protection on the form submission.
- A `manage_options` capability check.
- Persistence into a single autoloaded `wp_option` named `securepress_security_headers`.
- Sanitisation through `SecurityHeadersOptions::sanitize()`, which coerces `'1'`/`'on'`/`'true'` to booleans, clamps negative `max-age` to zero, validates `X-Frame-Options` and `Referrer-Policy` against their allowed values, and trims policy strings.

After saving, verify on the front of the site:

```bash
curl -sI https://example.com/ | grep -E '^(Strict-Transport-Security|Content-Security-Policy|X-Frame-Options|Referrer-Policy|Permissions-Policy|X-Content-Type-Options):'
```

### Header-specific guidance

#### HSTS (Strict-Transport-Security)

```
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
```

- **Verify HTTPS works site-wide and on every subdomain you might enable** before turning this on.
- Start with a small `max-age` (e.g. `300` = 5 minutes) for a few days, then bump to `31536000` (1 year) once you're confident.
- `includeSubDomains` applies the policy to **all** subdomains — make sure every subdomain serves HTTPS, including any internal admin / API hosts.
- `preload` is *one-way*. It bakes your domain into the browser-shipped HSTS preload list and is effectively impossible to remove. Only enable after submitting at <https://hstspreload.org>.

#### CSP (Content-Security-Policy)

The shipped default policy is intentionally conservative:

```
default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'
```

`'unsafe-inline'` is included for both styles and scripts because the WordPress admin and Gutenberg are full of inline `<style>`/`<script>` blocks. If you only enforce CSP on the front-end, you can tighten this considerably.

**Recommended rollout:**

1. Toggle **Enable CSP** on with **Report-Only mode** also on — this emits `Content-Security-Policy-Report-Only` so the browser reports violations without enforcing.
2. Open the site, navigate the admin and front-end, watch the browser console for `Refused to load…` violations.
3. Either widen the policy to allow legitimate sources, or remove the dependency.
4. Once the console is quiet, untick **Report-Only mode** to switch to enforcing `Content-Security-Policy`.

> **Tip.** A `report-uri` / `report-to` directive is not added by default. If you want centralised reporting, append it to the policy field, e.g.:
>
> ```
> default-src 'self'; …; report-uri /wp-json/myplugin/v1/csp-report
> ```

#### X-Frame-Options

Two valid values; the deprecated `ALLOW-FROM` is intentionally not exposed:

| Value | Effect |
|---|---|
| `DENY` | The page can never be framed, even by your own origin. |
| `SAMEORIGIN` | Only same-origin pages can frame this one (default). |

For richer control (allowing specific external origins to frame your content), use a CSP `frame-ancestors` directive — it supersedes `X-Frame-Options` in modern browsers.

#### Referrer-Policy

Pick one from the dropdown. The eight values browsers support are listed in `ReferrerPolicyHeader::VALID_POLICIES`. The default `strict-origin-when-cross-origin` matches modern browser default behaviour and is a good balance of privacy and analytics utility.

#### Permissions-Policy

Format: comma-separated `feature=(allowlist)` directives. `()` denies the feature everywhere; `(self)` allows it only on your own origin; `(self "https://example.com")` adds an allowlist.

The default policy denies a long list of APIs that most content sites don't use, including the Google FLoC / Topics tracking opt-out:

```
geolocation=(), camera=(), microphone=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=(), interest-cohort=()
```

If your site does use one of these (e.g. a webcam appointment plugin needs the camera), change that directive — for example, `camera=(self)`.

#### X-Content-Type-Options

The only valid value is `nosniff`. Safe to leave on; the only requirement is that your server emits accurate `Content-Type` headers (which WordPress and almost every CDN do).

### Per-route headers via the middleware

Most installs only need the dispatcher's site-wide emission. If you want **different** headers on a specific route — say, a tighter CSP for `/admin/sensitive-tool` — register `SecurityHeadersMiddleware` in the pipeline; the middleware merges registry headers into `$context['response']['headers']` while letting any header you set on the response win.

```php
use SecurePress\Facades\Security;
use SecurePress\Middleware\SecurityHeadersMiddleware;
use SecurePress\Middleware\CsrfProtectionMiddleware;

Security::middleware([
    SecurityHeadersMiddleware::class,
    CsrfProtectionMiddleware::class,
]);

// inside your handler, before passing to the middleware manager:
$context = [
    'response' => ['headers' => [
        'Content-Security-Policy' => "default-src 'none'; script-src 'self'",
    ]],
];
```

The route-level `Content-Security-Policy` will be emitted as-is; the registry-level `X-Frame-Options`, `Referrer-Policy`, etc. are still appended.

### Programmatic configuration overrides

The `SecurityHeadersOptions` resolver merges three sources, last-wins:

1. The defaults baked into `config/plugin.php` (`security_headers.*`).
2. Any keys present in `wp_options[securepress_security_headers]` (set by the admin UI or a deployment script).
3. Per-route overrides (via the middleware pattern above).

For deploy-time configuration without touching the admin UI, you can seed the option from `wp-config.php` or a CLI script:

```php
update_option('securepress_security_headers', [
    'hsts' => ['enabled' => true, 'max_age' => 31_536_000, 'include_subdomains' => true],
    'csp'  => ['enabled' => true, 'report_only' => false, 'policy' => "default-src 'self'"],
]);
```

Any keys you omit fall back to their config defaults.

---

## Audit logging

A first-class audit trail for security-sensitive WordPress activity. Events are stored in a custom DB table, surfaced through an admin viewer, and pruned automatically on a configurable retention window.

### What gets tracked out of the box

| Subsystem (category) | Events | Default level |
|---|---|---|
| `auth` | `user.login.success`, `user.login.failed`, `user.logout` | info / notice / info |
| `plugin` | `plugin.activated`, `plugin.deactivated`, `plugin.deleted`, `plugin.installed`, `plugin.updated` | warning / notice / warning / warning / warning |
| `theme` | `theme.installed`, `theme.updated`, `theme.switched` | warning |
| `user` | `user.created`, `user.deleted`, `user.password.reset` | notice / warning / warning |
| `role` | `user.role.changed`, `user.role.added`, `user.role.removed`, `user.super_admin.granted`, `user.super_admin.revoked` | warning if the change touches `administrator` / `super_admin`, otherwise notice. `super_admin.granted` is critical. |
| `options` | `option.updated` for an allowlist of security-relevant options (see config) | warning for high-impact (siteurl, admin_email, default_role, …), notice for the rest |
| `file_editor` | `file_editor.viewed`, `file_editor.modified` | notice / **critical** |
| `woocommerce` | `order.created`, `order.status.changed`, `order.payment_complete`, `order.refunded` (only when WC is active) | notice / variable / notice / warning |

Events that don't fit a built-in listener can be recorded via the `AuditLog` facade (see below).

### Where to find it

Once the plugin is active, log in as an administrator and visit:

```
Secure Press → Audit Logs
```

The page shows a paginated, filterable list with one row per event:

- **Time (UTC)** — when the event occurred. Always stored UTC; rendered UTC.
- **Level** — PSR-3 level (`emergency` → `debug`).
- **Category** — coarse subsystem bucket; use the dropdown to filter.
- **Action** — fine-grained event name in dot.notation (`user.login.failed`).
- **Actor** — the WordPress user that performed the action, or `system` for automatic events.
- **Target** — the thing being acted on (`user:42`, `plugin:akismet/akismet.php`, `option:siteurl`).
- **IP** — `REMOTE_ADDR` at the time of the event.
- **Details** — opens an event-detail panel below the list with the full JSON context, request URI, and user agent.

Filters on top of the list: **Category**, **Level**, **Search** (matches action / message / actor name / target id), date range, and per-page size. Filters are reflected in the URL so log views can be shared / bookmarked.

### Maintenance buttons

At the bottom of the page:

- **Run prune now** — invokes the pruner immediately (instead of waiting for the daily cron).
- **Clear all logs** — permanently deletes every entry (with a `confirm()` prompt). Both buttons are gated by the `manage_options` capability and a one-shot WP nonce.

### Storage

A custom table is created by `dbDelta()` on first boot:

```
wp_securepress_audit_logs
  id BIGINT UNSIGNED PK
  occurred_at DATETIME (UTC)
  level VARCHAR(20)
  category VARCHAR(40)
  action VARCHAR(120)
  actor_id BIGINT UNSIGNED NULL
  actor_name VARCHAR(120) NULL
  target_type VARCHAR(40) NULL
  target_id VARCHAR(120) NULL
  ip VARCHAR(45) NULL
  user_agent VARCHAR(255) NULL
  request_uri VARCHAR(255) NULL
  message TEXT NULL
  context LONGTEXT NULL  -- JSON

  INDEX (occurred_at), (actor_id), (category, action), (level), (target_type, target_id)
```

The `securepress_audit_log_db_version` option tracks schema generation — bump the constant in `AuditLogSchema::VERSION` if you change the table, and `dbDelta()` will alter the existing table on the next boot.

### Recording events from your own code (Laravel-style)

The `AuditLog` facade is a thin static surface over `AuditLoggerInterface`. Three calling styles are supported:

#### 1. PSR-3 helpers — the fast path

```php
use SecurePress\Facades\AuditLog;

AuditLog::info('user.profile.updated', ['user_id' => 5]);
AuditLog::warning('options.changed', ['option' => 'siteurl', 'old' => $old, 'new' => $new]);
AuditLog::critical('plugin.activated', ['slug' => 'evil-plugin/evil.php']);
```

Actor (current user), IP, user agent, and request URI are auto-filled from the active request — you only have to supply the action and any extra context.

#### 2. Fluent builder — when you need actor / target / message

```php
AuditLog::for($user)                                       // accepts WP_User, int, or null
    ->category(AuditEventCategory::WOOCOMMERCE)
    ->action('order.refunded')
    ->target('order', (string) $order->get_id())
    ->message('Issued partial refund')
    ->context(['amount' => 12.50, 'reason' => $reason])
    ->warning();    // or ->critical(), ->info(), ->record()
```

The builder:
- `for(null)` clears the actor (use for system / cron events).
- `for($numericId)` sets only `actor_id` — `actor_name` stays null.
- `for($wpUser)` sets both id and display name.
- `target($type, $id)` is optional — most events have a target, some don't.

#### 3. Full-fidelity AuditEvent — for custom listeners

```php
use SecurePress\Core\Audit\AuditEvent;
use SecurePress\Core\Audit\AuditEventCategory;
use SecurePress\Core\Audit\AuditEventLevel;

AuditLog::record(
    AuditEvent::make('webhook.signature.invalid', AuditEventCategory::SECURITY, AuditEventLevel::ERROR)
        ->withTarget('webhook', $webhookId)
        ->withMessage('Stripe webhook rejected — bad signature.')
        ->withContext(['ip' => $ip, 'event_id' => $stripeEventId])
);
```

This is the most explicit form — useful when you're building a reusable listener and want to bypass the builder's WordPress-flavored conveniences.

### Listening to your own events

Need to record domain-specific events on every WP hook your plugin emits? Implement `\SecurePress\Core\Audit\Listeners\ListenerInterface`, take the logger via constructor, and register your hooks in `register()`:

```php
final class CartAbandonedListener implements ListenerInterface
{
    public function __construct(private readonly AuditLoggerInterface $logger) {}

    public function register(): void
    {
        WpHelper::addAction('mystore_cart_abandoned', [$this, 'onCartAbandoned'], 10, 2);
    }

    public function onCartAbandoned(int $cartId, int $userId): void
    {
        $this->logger->record(
            AuditEvent::make('cart.abandoned', 'mystore', 'notice')
                ->withActor($userId)
                ->withTarget('cart', (string) $cartId)
        );
    }
}
```

Bind it on the container during your own `plugins_loaded` hook (priority 30+ so SecurePress is up):

```php
add_action('plugins_loaded', static function () use ($plugin): void {
    $listener = new CartAbandonedListener($plugin->container->get(AuditLoggerInterface::class));
    $listener->register();
}, 30);
```

### Retention & pruning

The pruner runs on a daily WP cron event named `securepress_audit_log_prune` and deletes entries older than `audit_log.retention_days` (default **90**). To disable pruning entirely (e.g. for compliance regimes that require permanent retention), set the value to `0`:

```php
// wp-config.php
add_filter('option_securepress_audit_log_retention', static fn () => 0); // or via the option directly
```

…or override at config-load time via the `audit_log.retention_days` key in `config/plugin.php`.

To force a prune outside cron, click **Run prune now** in the admin UI, or invoke from PHP:

```php
$plugin->container->get(\SecurePress\Core\Audit\AuditLogPruner::class)->prune();
```

### Mirroring to the file logger (Laravel-style channels)

Set `audit_log.mirror_to_file_logger` to `true` (default `false`) and every audit event will *also* be written to the existing `LoggerInterface` (which writes to `storage/logs/securepress.log` by default). Useful when you want to:

- Ship audit events to a SIEM via tail / Filebeat / Vector without scraping the database.
- Have a redundant copy in case the DB write fails.
- Get audit events into the standard application log for grep-friendly debugging.

The mirrored line uses the form `[audit] {category} {action}` with the full context as a structured array — your file logger formatter receives the raw context.

### Disabling listeners

Each listener can be turned off independently. In `config/plugin.php`:

```php
'audit_log' => [
    'listeners' => [
        'auth' => true,
        'plugin' => true,
        'user' => true,
        'options' => false,         // skip option-change auditing
        'file_editor' => true,
        'woocommerce' => false,     // skip WooCommerce auditing even when WC is active
    ],
],
```

Or disable the audit log entirely (every facade method becomes a no-op, no DB writes):

```php
'audit_log' => [
    'enabled' => false,
],
```

Disabling at the listener level is preferred to leaving listeners on but ignoring their output — instantiating a disabled listener still costs a tiny amount of memory and adds an unused hook.

---

## Authentication hardening

SecurePress adds an optional **authentication hardening** stack that layers on top of WordPress’s normal login:

| Capability | What it does |
|---|---|
| **Two-factor authentication (2FA)** | After a correct username/password, users with 2FA enabled must enter a **TOTP code** (authenticator app) or an **email one-time code**. Recovery codes work as a fallback. |
| **Login rate limiting** | Tracks failed attempts **per username** and **per IP** (transient-backed). Crossing the threshold temporarily locks further attempts and optionally emails the account holder. |
| **Session / device awareness** | Persists session rows (`wp_securepress_sessions`) with a coarse device fingerprint (IP prefix + User-Agent digest). Users can revoke sessions from **Account Security**. |
| **Email alerts** | Sends plain-text notifications for OTP delivery, 2FA enable/disable, recovery-code use, forced lockouts, and **suspicious logins** (see below). |
| **Suspicious login detection** | Scores logins using pluggable rules. The shipped **New device** rule compares the current fingerprint against prior active sessions; when the score reaches the threshold (default **50**), an informational email is sent. |

### Admin UI: Secure Press → Authentication

Site administrators (`manage_options`) configure the subsystem from **Secure Press → Authentication**. The page persists every value into a single autoloaded option (`securepress_auth_hardening`) using the WordPress Settings API, with the same nonce + capability protections WP applies to its own option pages. Available toggles:

- **Authentication Hardening** — master killswitch. Disabling it stops registering the login lockout, 2FA gate, session tracking, and suspicion alerts on the next request. Existing 2FA enrolments remain in place; re-enabling restores enforcement immediately.
- **Login lockout** — turn lockout on/off; configure max attempts (1–100), counting window (60–86400 s), and lock duration (60–86400 s).
- **Sessions** — toggle session tracking and adjust retention (1–3650 days).
- **Suspicious-login detection** — toggle the detector, set the alert threshold (0–200), and individually enable/disable the shipped rules (currently `new_device`).
- **Two-factor authentication** — pick the issuer label that shows up inside authenticator apps; tune the password→2FA challenge TTL (60–3600 s).
- **Email notifications** — global mute switch for all auth-hardening emails (OTP delivery, 2FA on/off, recovery-code use, lockout, suspicious-login). Useful for staging environments.

Saved values *override* the matching `config/plugin.php` defaults and take effect on the next request — every consumer (the kernel, the lockout policy, the suspicion detector, the session pruner, the notifier, the 2FA service) resolves its values via `AuthHardeningOptions` at container-resolution time.

> Programmatic access: `$plugin->container->get(\SecurePress\Core\Auth\AuthHardeningOptions::class)->all()` returns the fully merged shape, identical to what the page renders.

### User UI: Account Security

When the master switch is enabled, every logged-in user sees **Account Security** in the WordPress admin sidebar (`read` capability).

From there users can:

- Enable **authenticator-app (TOTP)** 2FA — scan the provisioning URI or enter the secret manually, then confirm with a live code.
- Enable **email OTP** 2FA — codes are emailed at each sign-in (recovery codes are still issued once at enrolment).
- View **recovery codes** when they are generated or regenerated (shown once — store them offline).
- Review **active SecurePress sessions** and revoke individual sessions or all other sessions (the current browser session is preserved when revoking “all others”).

### Login flow with 2FA

1. User submits valid credentials on `wp-login.php`.
2. `AuthenticationHardeningKernel` intercepts **before** WordPress issues cookies (`authenticate` filter at priority **30**).
3. If the account has 2FA enabled, SecurePress creates a short-lived **pending challenge** (stored in transients), sends an email OTP immediately when that method is active, and redirects to  
   `wp-login.php?action=sp_2fa&token=…`
4. The user enters their TOTP/email code or a recovery code.
5. On success, SecurePress calls `wp_set_auth_cookie()` and fires `wp_login` so audit logging and other plugins observe the same hook as a normal login.

### Login lockout defaults

Defined under `auth_hardening.lockout` in `config/plugin.php`:

- **5** failed attempts within **900** seconds (15 minutes) → lock for **900** seconds (tracked separately for username + IP).

### Session retention

Old rows in `wp_securepress_sessions` are removed by the daily cron hook `securepress_sessions_prune`. Retention is controlled by `auth_hardening.sessions.retention_days` (default **90**).

### Configuration keys (`auth_hardening`)

See [Configuration reference](#configuration-reference) for the flattened table. Two ways to override:

1. **Secure Press → Authentication** (UI) — recommended for production. Persists into the `securepress_auth_hardening` option and overrides the file-level defaults.
2. **`config/plugin.php`** — sets the *defaults* used when no admin override is stored. Useful for shipping environment-aware bundles (staging defaults differ from production).

```php
// config/plugin.php — disable the entire subsystem at the code level (admin can flip back)
'auth_hardening' => [
    'enabled' => false,
],

// Keep 2FA UI available but skip suspicious-login emails during staging
'auth_hardening' => [
    'suspicion' => [
        'enabled' => false,
    ],
],
```

Reading the resolved (defaults + admin overrides) shape from PHP:

```php
use SecurePress\Core\Auth\AuthHardeningOptions;

/** @var \SecurePress\Core\Plugin $plugin */
$opts = $plugin->container->get(AuthHardeningOptions::class)->all();

if ($opts['enabled'] && $opts['lockout']['enabled']) {
    // …
}
```

### Troubleshooting quick fixes

- **“Too many failed attempts”** — wait out the lockout window or temporarily raise `auth_hardening.lockout.max_attempts`.
- **Email OTP never arrives** — verify SMTP/`wp_mail` works; check spam; ensure the user’s profile email is valid.
- **Authenticator codes fail** — confirm the server clock is synchronised (NTP); TOTP allows ±30s drift via skew windows.
- **Revoked sessions still work** — SecurePress calls `WP_Session_Tokens::destroy()` for the matching verifier token when revoking from **Account Security**. If tokens were issued outside WordPress (custom SSO), revoke there too.

---

## Security SDK (developer API)

SecurePress isn't just a plugin — it's a developer toolkit. Every primitive (CSRF, rate limit, signed URLs, 2FA, sessions, lockout, audit) is exposed through one cohesive static facade: **`SecurePress\Facades\Security`**. Pulling protections from a single import means your code becomes idiomatic SecurePress code, and migrating off it later means rewriting a lot of call sites — which is exactly the kind of ecosystem stickiness "developer-first" is supposed to create.

```php
use SecurePress\Facades\Security;
```

The facade is bootstrapped automatically by `Plugin::register()`; third-party code should never call `Security::bootstrap()` manually.

### Top-level shortcuts

#### Rate limiting

```php
$result = Security::rateLimit('user:' . $userId, limit: 60, window: 60);
if (!$result->allowed) {
    wp_die('Too many requests', 'Too Many Requests', ['response' => 429]);
}
```

For the common "deny or execute" case, use `throttle()`:

```php
use SecurePress\Sdk\Exceptions\RateLimitExceededException;

try {
    $report = Security::throttle('report.expensive', limit: 5, window: 60, callback: fn () => generateReport());
} catch (RateLimitExceededException $e) {
    header('Retry-After: ' . $e->retryAfter());
    status_header(429);
    exit;
}
```

`Security::resetRateLimit('user:42')` clears the counter (useful for "unlock my account" admin actions or post-payment reconciliation).

#### CSRF tokens

```php
$token = Security::csrfToken('export_form');

echo Security::csrfField('export_form');

if (!Security::verifyCsrf($_POST['_wpnonce'] ?? '', 'export_form')) {
    wp_die('Bad CSRF token.', 'Forbidden', ['response' => 403]);
}
```

`Security::csrfTick()` returns the underlying lifecycle integer (1 = fresh, 2 = within grace, 0 = invalid) if you need to differentiate "stale but accepted" from "fresh".

#### Signed URLs

```php
$url = home_url(Security::signedUrl('/download', expires: 3600, params: ['file' => 'manual.pdf']));

// One-time use (for password resets, magic links, invites):
$reset = home_url(Security::signedUrl('/reset', expires: 1800, params: ['user' => $userId], oneTime: true));

$result = Security::verifySignedUrl(); // verifies $_SERVER['REQUEST_URI']
if (!$result->valid) {
    wp_die($result->reason);
}
```

### Fluent route builder

The `route()` builder is the most ergonomic way to compose multiple guards. Each method appends a middleware to a pipeline scoped to that route, and `run()` executes the callback only if every guard passes:

```php
use SecurePress\Sdk\Exceptions\RouteGuardException;

add_action('admin_post_my_export', function () {
    try {
        Security::route('/admin-post/my_export')
            ->capability('manage_options')
            ->csrf()
            ->rateLimit(limit: 5, window: 60)
            ->run(function () {
                streamCsv(getMyData());
            });
    } catch (RouteGuardException $e) {
        status_header($e->statusCode);
        foreach ($e->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        wp_die($e->getMessage());
    }
});
```

Available guard methods:

| Method | Guard | Failure status |
|---|---|---|
| `->capability(string $cap)` | WordPress `current_user_can($cap)` | `403` |
| `->csrf(?array $actions = null)` | `CsrfProtectionMiddleware` | `403` |
| `->rateLimit(?int $limit, ?int $window, ?string $key)` | `RateLimitMiddleware` | `429` (sets `Retry-After`) |
| `->signedUrl(bool $oneTime = false)` | `SignedUrlMiddleware` | `403` / `410` |
| `->withMiddleware(MiddlewareInterface $m)` | Your custom middleware | depends on middleware |

`run(callable)` returns the callback's return value. `check()` runs the guards without invoking a callback and returns the final context array — useful when the protected work is conditional.

### Sub-facades

For the larger feature subsystems, the facade exposes instance-style sub-APIs. They wrap the underlying services with names tuned for developers; the underlying services are free to evolve as long as these surfaces hold.

#### Two-factor

```php
if (Security::twoFactor()->isEnabledFor($user->ID)) {
    // user has 2FA enabled
}

$codes = Security::twoFactor()->regenerateRecoveryCodes($user->ID);
Security::twoFactor()->disable($user->ID, $user->user_email, $user->display_name);
```

#### Sessions

```php
foreach (Security::sessions()->activeFor($user->ID) as $session) {
    echo $session->ip, ' — ', $session->userAgent, "\n";
}

Security::sessions()->revoke($user->ID, $sessionId);
Security::sessions()->revokeAllExceptCurrent($user->ID, $currentSessionId);
```

#### Lockout

Useful for custom REST/AJAX login endpoints that should share the same brute-force counters as `wp-login.php`:

```php
$api = Security::lockout();

if ($api->isLocked($username, $ip)) {
    return new WP_Error('locked', 'Too many failed attempts.', ['status' => 423]);
}

if (!password_verify($input, $hash)) {
    $api->registerFailure($username, $ip);
    return new WP_Error('invalid', 'Bad credentials.');
}

$api->clear($username, $ip);
```

#### Audit logging

`Security::audit()` mirrors the static `AuditLog` facade as instance methods so everything routes through the same entry point:

```php
Security::audit()->info('cart.cleared', ['cart_id' => $id]);

Security::audit()
    ->for($user)
    ->category('woocommerce')
    ->action('refund.issued')
    ->context(['amount' => $amount])
    ->record();
```

### Events

The facade ships a tiny in-process event bus for the SDK so plugins can react to SecurePress activity without learning the underlying hook names. Listeners run synchronously in registration order. Every fire is also bridged to `do_action('securepress.<event>', ...$args)` for WordPress interop.

```php
Security::on('login.failed', function (string $username, ?string $ip) {
    error_log("Failed login for {$username} from {$ip}");
});

Security::fire('login.failed', $username, $ip);

// The same event is observable from native WP hooks:
add_action('securepress.login.failed', function ($username, $ip) {
    // ...
});
```

`Security::on()` returns an unsubscriber:

```php
$off = Security::on('cart.checkout', $listener);
// later …
$off();
```

### Introspection

```php
Security::version();                                  // "0.9.0"
Security::isFeatureEnabled('auth_hardening');         // bool
Security::isFeatureEnabled('auth_hardening.lockout'); // bool
Security::isFeatureEnabled('security_headers');       // bool
Security::isFeatureEnabled('audit_logging');          // bool
```

### Stability contract

Everything documented under **Security SDK** is part of the stable public API. The underlying classes in `src/Core/*` and `src/Sdk/*` may evolve — depend on the facade methods, not the implementation classes.

---

## File integrity monitoring (Pro)

(See the *File integrity monitoring* admin page under **Secure Press → File Integrity** once Pro is active. This section covers the Pro-gated additions in passing — the bulk of the FIM documentation lives in `docs/INTEGRITY.md` if you maintain that separately.)

---

## WooCommerce protection (Pro)

The WooCommerce protection module is a **Pro-tier feature** focused on behavioural abuse prevention for stores: fake checkouts, registration spam, REST API abuse, and cart abuse. It runs only when:

1. an active Pro license is configured (`Secure Press → License`), AND
2. WooCommerce is active on the site.

When either is missing, the module wires up **zero** hooks — there's no overhead on free installs or non-store sites.

### What it does

| Pipeline | WordPress / WooCommerce hook | What it protects |
| --- | --- | --- |
| `CheckoutPipeline` | `woocommerce_checkout_process` | Velocity, disposable emails, impossible timing, repeated identical carts, billing/shipping mismatch |
| `RegistrationPipeline` | `woocommerce_register_post` | Honeypot, per-IP throttle, disposable email denial |
| `ApiPipeline` | `rest_pre_dispatch` (WC namespaces only) | Per-route rate limiting, scanner UA detection, suspicious-request scoring |
| `CartPipeline` | `woocommerce_add_to_cart_validation` + `woocommerce_coupon_error` | Add-to-cart velocity, coupon brute-force detection |

Each pipeline returns one of three outcomes: **accept**, **challenge** (logged + signal, no block), **deny** (logged, user shown a generic error).

Decisions are emitted into the audit log under the actions:

- `wc.checkout.deny` / `wc.checkout.challenge`
- `wc.registration.deny`
- `wc.api.deny`
- `wc.cart.deny`

so you can review them under **Secure Press → Audit Logs**.

### Configuring from the admin UI

`Secure Press → WooCommerce Protection` exposes:

- **Module master switch** (single toggle to disable everything).
- **Checkout**: velocity soft/hard thresholds + window, minimum-seconds-to-submit, fraud-score challenge/deny thresholds.
- **Registration**: per-IP rate limit + window, disposable-email denial, honeypot field name, minimum-seconds-to-submit.
- **API**: default per-IP limit + window, allow-list for authenticated requests, scanner-UA blocking.
- **Cart**: cart velocity (soft/hard) + window, coupon-failure thresholds (soft/hard).

Per-route API limits don't have a UI — set them in `config/plugin.php` (`woocommerce_protection.api.per_route`).

### Calling pipelines from your own code (SDK)

For headless / custom checkout flows that don't fire the canonical WooCommerce hooks, evaluate a context yourself:

```php
use SecurePress\Facades\Security;
use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Decision;

$context = new DetectionContext(
    kind:        DetectionContext::KIND_CHECKOUT,
    ip:          $_SERVER['REMOTE_ADDR'] ?? '',
    userAgent:   $_SERVER['HTTP_USER_AGENT'] ?? '',
    email:       'customer@example.com',
    userId:      get_current_user_id() ?: null,
    data:        [
        'cart_items'      => $items,                     // [{product_id, variation_id?, quantity}]
        'billing_country' => 'US',
        'shipping_country'=> 'US',
        'use_shipping'    => true,
    ],
);

$result = Security::woo()->checkout($context);

if ($result->blocked()) {
    wp_send_json_error(['error' => 'request_blocked'], 429);
}
```

The same surface exists for the other pipelines:

```php
Security::woo()->registration($context);
Security::woo()->api($context);
Security::woo()->cart($context);
```

You can also branch on availability without instantiating a context:

```php
if (Security::woo()->isAvailable()) {
    // Show "Protected by SecurePress" admin badge, etc.
}
```

### Performance characteristics

The module is engineered so a request that doesn't touch any commerce event pays as close to zero cost as possible.

**Lazy construction.** The kernel (`WooCommerceModule`) is built *once* per request and receives only three small services in its constructor: the `LicenseManager`, the options resolver, and the DI container. Pipelines, middleware, the disposable-email registry, the cart fingerprinter, the abuse-counter store, and the behaviour clock are **lazy-resolved on the first hook that actually fires**. A request that never hits `woocommerce_checkout_process` never constructs `CheckoutPipeline`, never builds its five middleware, never instantiates the disposable-domain registry.

**Conditional hook registration.** The kernel inspects `\SecurePress\Core\Support\RequestContext` before adding any callbacks:

- REST hooks (`rest_pre_dispatch`) attach via `rest_api_init`, which only fires during REST requests — so on a plain page view the WC API filter is never even added to WordPress's global filter table.
- Commerce hooks (`woocommerce_checkout_process`, `woocommerce_register_post`, `woocommerce_add_to_cart_validation`, `woocommerce_coupon_error`) only attach on "commerce-capable" requests (frontend + AJAX). Cron, CLI, REST-only, and admin-only requests skip these entirely.
- Module bootstrap is deferred to the `init` action (priority 5), so on activation, deactivation, and wp-cron paths the kernel doesn't get instantiated at all.

**Cheap inner loops.** When a hook *does* fire:

- Counters live in **WordPress transients** (object-cache aware). One read + one write per middleware on the worst-case path.
- The disposable-email registry is an **in-memory hash set** — O(1) lookup, no DB.
- The cart fingerprint is **SHA-256 truncated to 16 hex chars** of a sorted, normalised product list — microseconds per cart.
- Transient names are **HMAC-scoped** so raw IPs and emails never become option-table keys.

**Pro / WC gate.** The whole module short-circuits when Pro isn't licensed OR WooCommerce isn't loaded. On free or non-WC installs there is **literally nothing to skip past** — the kernel's `register()` returns false on its first conditional and never touches the hook table.

### Extension points

- `securepress.disposable_email_domains` (filter, returns `string[]`) — extend the disposable-domain list at runtime.
- `securepress.disposable_email_allowed_domains` (filter, returns `string[]`) — mark specific domains as never-disposable.
- `securepress.is_pro` (filter, returns `bool`) — programmatic Pro toggle (handy for tests).

---

## Licensing & Pro features

SecurePress ships as a **single plugin** with a Pro tier unlocked by an offline license key. There is no separate "Pro" plugin to install.

### What's in Pro

- **WooCommerce Protection** (this guide's previous section).

Free tier still gets the full middleware framework, CSRF, rate limiting, signed URLs, security headers, audit logging, authentication hardening, file integrity monitoring, and the developer SDK.

### How licenses are issued

The default validator is **offline HMAC**: keys look like `SP-PRO-1714780800-1746316800-3f6d1c2e9f6d1c2e` and embed the tier, issuance timestamp, expiry timestamp, and a truncated HMAC-SHA256 signature.

The vendor signs keys with a shared secret (configured via `licensing.secret` in `config/plugin.php` or, recommended, the `SECUREPRESS_LICENSE_SECRET` environment variable). No outbound network call is required to validate — your install can be air-gapped and still authenticate the key correctly.

### Entering a license

Go to **Secure Press → License**, paste the key, hit **Save license**. The page shows:

- Active / Expired / Invalid / None state.
- Tier (`pro`, `agency`, …).
- Expiry date (if any).
- A masked preview of the saved key.

Alternative configuration sources (resolved in this order, first match wins):

1. `wp_option('securepress_pro_license')` — what the Settings page writes to.
2. `SECUREPRESS_PRO_LICENSE` environment variable.
3. `SECUREPRESS_PRO_LICENSE` PHP constant.
4. `apply_filters('securepress.pro_license', '')` — programmatic override (extensions, tests).

### Checking Pro status from your code

```php
use SecurePress\Facades\Security;

if (Security::isPro()) {
    // Pro-only behaviour.
}

$status = Security::licenseStatus();
echo $status->state;       // 'active' | 'expired' | 'invalid' | 'none'
echo $status->tier;        // 'pro' | 'agency' | 'free'
echo $status->daysRemaining(); // int|null
```

A per-feature check is also available:

```php
Security::isFeatureEnabled('woocommerce_protection'); // true on Pro
Security::isFeatureEnabled('pro');                    // alias
```

---

## Configuration reference

`config/plugin.php` ships with sensible defaults. Every value can be overridden per environment via an env variable, except `security_headers.*`, `audit_log.*`, and `auth_hardening.*`, which are intended to be configured from the admin UI (`Secure Press → Security Headers` / `Secure Press → Authentication`) or `config/plugin.php` (`audit_log.*`). Admin overrides are stored in autoloaded `wp_options` and merged on top of the file defaults; no ENV wiring is needed for those.

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
| `security_headers.hsts.enabled` | — | `false` | HSTS dispatcher / middleware |
| `security_headers.hsts.max_age` | — | `31536000` | HSTS `max-age` directive |
| `security_headers.hsts.include_subdomains` | — | `false` | HSTS `includeSubDomains` flag |
| `security_headers.hsts.preload` | — | `false` | HSTS `preload` flag (irreversible) |
| `security_headers.csp.enabled` | — | `false` | CSP dispatcher / middleware |
| `security_headers.csp.policy` | — | conservative WP-friendly policy | CSP directive string |
| `security_headers.csp.report_only` | — | `true` | switch wire name to `Content-Security-Policy-Report-Only` |
| `security_headers.x_frame_options.enabled` | — | `true` | X-Frame-Options dispatcher / middleware |
| `security_headers.x_frame_options.value` | — | `SAMEORIGIN` | `DENY` or `SAMEORIGIN` |
| `security_headers.referrer_policy.enabled` | — | `true` | Referrer-Policy dispatcher / middleware |
| `security_headers.referrer_policy.policy` | — | `strict-origin-when-cross-origin` | one of the 8 standard values |
| `security_headers.permissions_policy.enabled` | — | `true` | Permissions-Policy dispatcher / middleware |
| `security_headers.permissions_policy.policy` | — | conservative deny-list | comma-separated `feature=(allowlist)` |
| `security_headers.x_content_type_options.enabled` | — | `true` | emits `nosniff` |
| `licensing.secret` | `SECUREPRESS_LICENSE_SECRET` | `change-me-in-production` | HMAC secret for `LocalLicenseValidator` |
| `woocommerce_protection.enabled` | — | `true` | Module master switch (Pro-gated) |
| `woocommerce_protection.checkout.enabled` | — | `true` | Fake checkout protection |
| `woocommerce_protection.checkout.velocity_soft` | — | `3` | Per-IP/email soft velocity threshold |
| `woocommerce_protection.checkout.velocity_hard` | — | `8` | Per-IP/email hard velocity threshold |
| `woocommerce_protection.checkout.velocity_window` | — | `120` | Velocity window (seconds) |
| `woocommerce_protection.checkout.min_seconds_to_submit` | — | `3` | Impossible-timing floor (seconds) |
| `woocommerce_protection.checkout.fraud.challenge_threshold` | — | `40` | Fraud-score challenge cutoff |
| `woocommerce_protection.checkout.fraud.deny_threshold` | — | `80` | Fraud-score deny cutoff |
| `woocommerce_protection.registration.enabled` | — | `true` | Registration spam protection |
| `woocommerce_protection.registration.rate_limit` | — | `5` | Max registrations per IP per window |
| `woocommerce_protection.registration.window` | — | `600` | Registration window (seconds) |
| `woocommerce_protection.registration.deny_disposable_emails` | — | `true` | Hard-deny disposable email domains |
| `woocommerce_protection.registration.honeypot_field_name` | — | `securepress_hp` | Hidden honeypot field name |
| `woocommerce_protection.registration.min_seconds_to_submit` | — | `2` | Honeypot-timing floor (seconds) |
| `woocommerce_protection.api.enabled` | — | `true` | WC REST API abuse protection |
| `woocommerce_protection.api.default_limit` | — | `60` | Per-IP default RPS limit |
| `woocommerce_protection.api.default_window` | — | `60` | API rate window (seconds) |
| `woocommerce_protection.api.pass_when_authenticated` | — | `true` | Skip checks for logged-in callers |
| `woocommerce_protection.api.deny_on_scanner_ua` | — | `true` | Block known scanner User-Agents |
| `woocommerce_protection.api.per_route` | — | `[]` | Per-route overrides `{prefix:{limit,window}}` |
| `woocommerce_protection.cart.enabled` | — | `true` | Cart abuse detection |
| `woocommerce_protection.cart.velocity_soft` | — | `20` | Cart soft velocity threshold |
| `woocommerce_protection.cart.velocity_hard` | — | `60` | Cart hard velocity threshold |
| `woocommerce_protection.cart.window` | — | `60` | Cart window (seconds) |
| `woocommerce_protection.cart.coupon_soft` | — | `4` | Coupon failures soft threshold |
| `woocommerce_protection.cart.coupon_hard` | — | `10` | Coupon failures hard threshold |
| `audit_log.enabled` | — | `true` | Master killswitch for audit logging |
| `audit_log.retention_days` | — | `90` | Days of audit history kept; `0` = forever |
| `audit_log.mirror_to_file_logger` | — | `false` | Mirror every event to `storage/logs/securepress.log` |
| `audit_log.listeners.auth` | — | `true` | Login / logout / failed-login tracking |
| `audit_log.listeners.plugin` | — | `true` | Plugin activate / deactivate / install / update / delete |
| `audit_log.listeners.user` | — | `true` | User register / delete / role change / password reset |
| `audit_log.listeners.options` | — | `true` | Allowlisted option changes |
| `audit_log.listeners.file_editor` | — | `true` | Built-in theme / plugin file editor usage |
| `audit_log.listeners.woocommerce` | — | `true` | WooCommerce orders / payments / refunds (no-op if WC inactive) |
| `audit_log.option_allowlist` | — | siteurl, home, admin_email, users_can_register, default_role, blogname, blogdescription, wp_user_roles, permalink_structure, template, stylesheet | List of options the `OptionsListener` watches — extend as needed |
| `auth_hardening.enabled` | — | `true` | Master killswitch for login lockout, 2FA gate, sessions, suspicion alerts |
| `auth_hardening.two_factor.issuer` | — | `SecurePress` | Issuer label embedded in `otpauth://` provisioning URIs |
| `auth_hardening.two_factor.challenge_ttl_seconds` | — | `600` | Pending password→2FA window |
| `auth_hardening.lockout.enabled` | — | `true` | Failed-login counter / temporary bans |
| `auth_hardening.lockout.max_attempts` | — | `5` | Failures allowed inside the rolling window |
| `auth_hardening.lockout.window_seconds` | — | `900` | Rolling counter window |
| `auth_hardening.lockout.lock_seconds` | — | `900` | Lock duration once threshold exceeded |
| `auth_hardening.sessions.enabled` | — | `true` | Persist SecurePress session rows + daily prune |
| `auth_hardening.sessions.retention_days` | — | `90` | Session table pruning horizon |
| `auth_hardening.suspicion.enabled` | — | `true` | Aggregate suspicion scoring after login |
| `auth_hardening.suspicion.alert_threshold` | — | `50` | Minimum score before emailing “new device” |
| `auth_hardening.suspicion.rules.new_device` | — | `true` | Compare device fingerprint against prior sessions |
| `auth_hardening.notifications.enabled` | — | `true` | Reserved — gate future SMTP overrides |
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

### Recipe: enable CSP gradually with reporting

```php
// 1. seed reasonable defaults from a deploy script
update_option('securepress_security_headers', array_replace_recursive(
    get_option('securepress_security_headers', []),
    [
        'csp' => [
            'enabled'     => true,
            'report_only' => true,
            'policy'      => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; report-uri /wp-json/myplugin/v1/csp-report",
        ],
    ]
));

// 2. expose a tiny REST endpoint that logs CSP violations
add_action('rest_api_init', static function (): void {
    register_rest_route('myplugin/v1', '/csp-report', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => static function (\WP_REST_Request $request) {
            error_log('CSP violation: ' . wp_json_encode($request->get_json_params()));
            return new \WP_REST_Response(null, 204);
        },
    ]);
});
```

Once your reports are quiet for a day or two, log into **Secure Press → Security Headers** and untick "Report-Only mode" to start enforcing.

### Recipe: alert on critical audit events

Hook the `audit_log` listener pipeline yourself by wrapping the recorder — useful when you want to trigger Slack pings / email alerts without copying every event.

```php
use SecurePress\Core\Audit\AuditEvent;
use SecurePress\Core\Audit\AuditEventLevel;
use SecurePress\Core\Audit\AuditLoggerInterface;
use SecurePress\Core\Container;

add_action('plugins_loaded', static function () use ($plugin): void {
    $original = $plugin->container->get(AuditLoggerInterface::class);

    $plugin->container->set(AuditLoggerInterface::class, new class ($original) implements AuditLoggerInterface {
        public function __construct(private readonly AuditLoggerInterface $inner) {}

        public function record(AuditEvent $event): ?AuditEvent {
            $stored = $this->inner->record($event);

            if ($stored !== null && in_array($event->level, [AuditEventLevel::CRITICAL, AuditEventLevel::EMERGENCY], true)) {
                MyAlerter::ping(sprintf('[%s] %s', $event->level, $event->action), $event->context);
            }

            return $stored;
        }

        // Forward every other method to the inner logger
        public function log(string $level, string $action, array $context = []): ?AuditEvent { return $this->inner->log($level, $action, $context); }
        public function info(string $action, array $context = []): ?AuditEvent { return $this->inner->info($action, $context); }
        public function notice(string $action, array $context = []): ?AuditEvent { return $this->inner->notice($action, $context); }
        public function warning(string $action, array $context = []): ?AuditEvent { return $this->inner->warning($action, $context); }
        public function error(string $action, array $context = []): ?AuditEvent { return $this->inner->error($action, $context); }
        public function critical(string $action, array $context = []): ?AuditEvent { return $this->inner->critical($action, $context); }
    });
}, 30);
```

### Recipe: extend the audit option allowlist

```php
add_filter('securepress_audit_option_allowlist', static fn (array $options): array => array_merge($options, [
    'mailserver_url',
    'wp_calendar_settings',
    'mystore_payment_gateway',
]));
```

> The current build doesn't ship a filter on the allowlist (the value comes straight from `Config`); to add to the list, edit `config/plugin.php` directly. A filter hook will be added in a future release.

### Recipe: tighten headers for a sensitive admin tool

```php
use SecurePress\Facades\Security;
use SecurePress\Middleware\SecurityHeadersMiddleware;

Security::middleware([SecurityHeadersMiddleware::class]);

add_action('admin_post_export_secrets', static function () use ($container): void {
    if (!current_user_can('manage_options')) {
        wp_die('Forbidden', '', ['response' => 403]);
    }

    $manager = $container->get(\SecurePress\Core\Middleware\MiddlewareManager::class);
    $result  = $manager->handle([SecurityHeadersMiddleware::class], [
        'response' => ['headers' => [
            // a much tighter, page-specific CSP, just for this handler
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'",
            'Cache-Control'           => 'no-store',
        ]],
    ]);

    foreach ($result['response']['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }

    // ... emit the export ...
});
```

The route-level `Content-Security-Policy` overrides the registry-level one (the middleware honors `array + array` precedence — left-hand side wins), while the registry's `X-Frame-Options`, `Referrer-Policy`, `X-Content-Type-Options`, etc. are still appended.

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

### CSP breaks the WP admin / Gutenberg

`'unsafe-inline'` is included by default in both `script-src` and `style-src` because Gutenberg and many admin screens emit inline `<script>` / `<style>`. If you removed `'unsafe-inline'` and the editor stopped working, either add it back or restrict the policy to non-admin URLs (e.g. apply it only on the front-end via a route-level middleware override, see ["Recipe: tighten headers for a sensitive admin tool"](#recipe-tighten-headers-for-a-sensitive-admin-tool)).

### After enabling HSTS, my browser refuses to load the site over HTTP

That's the headline feature. The browser remembers the policy for `max-age` seconds and will not connect over plain HTTP for that long, even if you remove the header. Recovery options:

- Restore HTTPS — usually the right answer.
- Clear HSTS for the domain in browser settings (`chrome://net-internals/#hsts` on Chromium, similar on Firefox / Safari).
- Wait `max-age` seconds — this is why we ship `enabled: false` by default and recommend a small `max-age` (e.g. 5 minutes) when first turning HSTS on.

If you toggled `preload`: there is no easy escape — see <https://hstspreload.org/#removal>.

### Headers don't appear in `curl -I`

Make sure:

1. The plugin is actually active (it's a no-op while inactive).
2. The header is enabled in **Secure Press → Security Headers** and saved.
3. The page hits `send_headers` — most WP requests do, but `wp-cron.php` and a few admin AJAX endpoints can short-circuit before that point.
4. No higher-priority `send_headers` hook (or a downstream proxy / CDN) is stripping the header. The dispatcher hooks at priority `1`, so most plugin-set headers will run after it; if something replaces a header you're trying to set, increase `Priority` or set the header at a different layer (Apache `Header set`, nginx `add_header`).

### Audit log table is missing or empty after install

The schema runs on first boot. Check:

```sql
SHOW TABLES LIKE '%securepress_audit_logs%';
SELECT option_value FROM wp_options WHERE option_name = 'securepress_audit_log_db_version';
```

If the option is missing, the migration didn't run — usually because `dbDelta()` was unavailable (rare; happens when the plugin runs before `wp-admin/includes/upgrade.php` is on the include path). To manually trigger the install:

```php
$plugin->container->get(\SecurePress\Core\Audit\AuditLogSchema::class)->install();
```

If the table exists but stays empty, audit logging is probably disabled — check `audit_log.enabled` in `config/plugin.php`.

### Failed login storm fills the log

Each failed login is one row; with brute-force traffic this can run to thousands of rows per hour. Mitigations, in order of bluntness:

1. **Pair with the rate limiter** on `wp-login.php` — covered in [Rate limiting](#rate-limiting). Most attempts will be 429'd before they reach the auth listener.
2. **Disable the auth listener** specifically (`audit_log.listeners.auth = false`) and rely on `auth.log` from your webserver instead.
3. **Lower retention** (`audit_log.retention_days`) so the table is auto-pruned more aggressively.

### `notice` for high-volume events drowns out important entries

Use the level filter in **Secure Press → Audit Logs** to focus on `warning` / `critical` only. For programmatic SIEM exports, the `audit_log.mirror_to_file_logger` mode lets you tail `storage/logs/securepress.log` and grep for the level prefix.

### Pruner cron isn't running

WP cron runs on traffic. On low-traffic sites (or when `DISABLE_WP_CRON` is set) the daily prune may lag for hours / days — switch to a real cron job:

```cron
*/15 * * * * cd /var/www/html && /usr/bin/wp cron event run --due-now --quiet
```

Verify the schedule exists with `wp cron event list | grep securepress_audit_log_prune`.

### Tests fail with "Undefined function wp_verify_nonce"

You're running the plugin's tests without the bootstrap. From the plugin root:

```bash
composer install
vendor/bin/phpunit
```

The test bootstrap (`tests/bootstrap.php`) loads stubs for `wp_verify_nonce`, `wp_salt`, transient functions, etc., backed by `SecurePress\Tests\Stubs\WpStubState`.

---

## What's NOT in this build (yet)

The current build provides the **primitives** (signer, limiter, CSRF middleware, signed-URL middleware, security headers manager, audit logger + viewer, 2FA / session / lockout services, the developer SDK on `Security::` and `AuditLog::`, secret/nonce stores, DI bindings). Things still on the roadmap:

- An HTTP **kernel** that automatically dispatches the global middleware stack on every request matching a registered route — until then, use the fluent `Security::route()->run(...)` builder to wire guards on a per-route basis.
- **Bot/firewall** rules
- A richer admin **dashboard** UI with charts and trend lines (the current dashboard at **Secure Press → Dashboard** shows status badges only)
- An **audit log CSV / NDJSON exporter** for offline forensics
- **WP-CLI** commands (`wp securepress audit:list`, `wp securepress audit:prune`, `wp securepress 2fa:status <user>`)
- **PHP 8 attribute-based** route protection (`#[ProtectedRoute(rate: 60, csrf: true)]`) — the manual `Security::route(...)->...->run(...)` builder is the supported approach today.

See [`ROADMAP_AGILE.md`](../ROADMAP_AGILE.md) for the prioritized backlog.
