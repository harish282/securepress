# PressSentinel

**Self-hosted application-layer security for WordPress** — login hardening, audit trail, file integrity, security headers, optional rate limits, WooCommerce abuse protection, and a developer SDK. **Free, full feature set, no cloud account required.**

Laravel-inspired architecture: middleware-style helpers, a `Security` facade, authentication hardening, audit logging, and WooCommerce protection pipelines.

| | |
| --- | --- |
| **Version** | 0.1.0 |
| **Edition** | Free — all features included |
| **PHP** | 8.2+ |
| **WordPress** | 6.4+ (tested up to 7.0) |
| **Tests** | 484 PHPUnit tests passing |
| **Plugin Check** | Clean (errors and warnings resolved) |

> **WordPress.org listing:** the canonical directory readme is **[readme.txt](readme.txt)**. This file is for GitHub and developers.

---

## Why PressSentinel?

PressSentinel hardens WordPress **inside** the application: it does not replace Cloudflare, your host firewall, or a CDN. Use it **together with** edge and host protections.

### Why site owners install it

| You need… | PressSentinel helps by… |
| --- | --- |
| Fewer brute-force logins | IP + username lockouts; optional 2FA (TOTP, email OTP, recovery codes) |
| Accountability | Audit log: logins, plugin/role changes, sensitive options, file editor, WooCommerce events |
| Early warning on tampering | Core checksums, plugin manifest diff, PHP heuristics (scheduled scans) |
| Stronger browser policies | HSTS, CSP, X-Frame-Options, Referrer-Policy, and more (per-header toggles) |
| Less REST / front-end abuse | Optional global rate limiting (wp-admin excluded by default) |
| WooCommerce spam & fake checkouts | Checkout, cart, registration, and Store API protection pipelines |
| Recovery from misconfiguration | Safe mode via `wp-config.php` if lockout or login disguise blocks access |
| Privacy & control | Data stays on your server; no PressSentinel account required |

**Pitch:** *Security building blocks on your server — not in our cloud. Turn on what you need from one dashboard.*

### What makes it different

Many plugins (Wordfence, Solid Security, All-In-One WP Security, etc.) overlap on 2FA, lockouts, headers, or scanning. PressSentinel does **not** claim to be the only plugin with those features. It is distinctive in three ways:

1. **Developer SDK** — protect custom `admin-post` handlers, forms, and REST routes with CSRF, rate limits, signed URLs, and route guards (`Security` facade).
2. **WooCommerce abuse pipelines** — checkout velocity, cart/coupon abuse, registration spam, fraud scoring, and API throttling alongside audit and login hardening.
3. **Privacy-first, fully free** — no license server, no paywalled module, no telemetry to the author.

### Who it is for

**Good fit:** WooCommerce stores; agencies with custom plugins; teams wanting audit + integrity + login protection on-server; developers wiring security into custom code.

**Less ideal:** Sites that only want a single famous cloud firewall/malware suite with no setup. Multisite is not formally certified in 0.1.0.

### What it does not claim

- Does **not** replace edge WAF/CDN or host firewalls.
- Does **not** provide commercial cloud antivirus scanning (heuristics + checksums only).
- Does **not** auto-protect every WordPress hook — the SDK protects **routes you wire**.

More copy blocks and FAQs: **[docs/WHY_PRESSSENTINEL.md](docs/WHY_PRESSSENTINEL.md)**.

---

## Features (0.1.0)

- **Authentication hardening** — lockouts, TOTP/email 2FA, recovery codes, sessions, new-device alerts
- **Security headers** — HSTS, CSP, XFO, Referrer-Policy, Permissions-Policy, XCTO
- **Audit log** — UI, detail view, retention, pruning
- **File integrity** — core checksums, manifest diff, PHP heuristics; themes/uploads optional
- **Rate limiting** — optional; REST, front end, AJAX, wp-login (wp-admin excluded by default)
- **WooCommerce Protection** — when WooCommerce is active
- **Security SDK** — CSRF, rate limits, signed URLs, route guards
- **Login URL disguise** — off by default
- **Safe mode** — emergency recovery
- **Health diagnostics** — module and environment snapshot
- **MU loader** — optional early bootstrap

---

## Quick start

1. Clone or copy into `wp-content/plugins/presssentinel` (or run `bash scripts/build-release-zip.sh dev` from this repo).
2. Activate **PressSentinel** in wp-admin.
3. Open **Press Sentinel → Dashboard** and review feature toggles.
4. Optional: install the [MU loader](docs/MU_LOADER_INSTALL.md) for earlier bootstrap.
5. Read [docs/USAGE.md](docs/USAGE.md) for CSRF, rate limits, signed URLs, and route protection.
6. Before production: [docs/STAGING_TEST_PLAN.md](docs/STAGING_TEST_PLAN.md).

```bash
composer install
vendor/bin/phpunit
bash scripts/build-release-zip.sh prod   # build/presssentinel-0.1.0.zip
```

Configuration: `config/plugin.php` and optional `wp-config.php` constants (`PRESS_SENTINEL_SAFE_MODE`, `PRESS_SENTINEL_INTERNAL_SECRET`). Optional tips: `support.donation_url` in config ([Ko-fi](https://ko-fi.com/)).

---

## Implementation status (vs project plan)

Maps [presssentinel_wordpress_security_plugin_project_plan.md](presssentinel_wordpress_security_plugin_project_plan.md) and [ROADMAP_AGILE.md](ROADMAP_AGILE.md) to **0.1.0**.

### MVP and core security

| Feature | Status | Notes |
| --- | --- | --- |
| Login protection (lockout, failed-login tracking) | **Shipped** | `LoginLockoutService`; tested |
| Rate limiting (login, REST, front end) | **Shipped** | Global subscriber + SDK; wp-admin excluded by default |
| Security headers | **Shipped** | Per-header toggles |
| Signed URLs | **Shipped** | `UrlSigner`, `SignedUrlMiddleware` |
| CSRF layer | **Shipped** | `Security` facade |
| Audit logging | **Shipped** | DB storage, listeners, admin UI |
| Developer SDK | **Shipped** | `Security` / `AuditLog` facades, `RouteBuilder` |

### Version 1.1 items (largely in 0.1.0)

| Feature | Status | Notes |
| --- | --- | --- |
| Two-factor (TOTP, email OTP, recovery codes) | **Shipped** | wp-login challenge flow |
| Device & session management | **Shipped** | Sessions table, revoke, pruning |
| WooCommerce security pack | **Shipped** | Checkout/cart/registration/API pipelines |
| Laravel-style validation layer | **Not shipped** | — |

### Version 2.0 / advanced

| Feature | Status | Notes |
| --- | --- | --- |
| File integrity monitoring | **Shipped** | Core checksums, manifest diff, heuristics |
| Malware / heuristic scanning | **Partial** | PHP heuristics only — not a full AV engine |
| Threat intelligence / cloud SaaS | **Not shipped** | Future roadmap |

### Known gaps

- Audit log CSV / NDJSON export — not implemented
- Full-site automatic middleware on every hook — SDK is opt-in per route
- Multisite — not formally certified

---

## Documentation

| Document | Purpose |
| --- | --- |
| [readme.txt](readme.txt) | WordPress.org plugin directory readme (canonical public description) |
| [docs/WHY_PRESSSENTINEL.md](docs/WHY_PRESSSENTINEL.md) | Why install, comparisons, reusable marketing copy |
| [docs/USAGE.md](docs/USAGE.md) | Developer and operator usage |
| [docs/MU_LOADER_INSTALL.md](docs/MU_LOADER_INSTALL.md) | Early-load MU plugin setup |
| [docs/STAGING_TEST_PLAN.md](docs/STAGING_TEST_PLAN.md) | Staging QA checklist |
| [docs/CYPRESS_E2E.md](docs/CYPRESS_E2E.md) | Cypress E2E automation |
| [docs/PRIVACY.md](docs/PRIVACY.md) | Privacy policy for site owners and reviewers |
| [ROADMAP_AGILE.md](ROADMAP_AGILE.md) | Sprint backlog |

### Optional licensing module (commercial builds)

Offline HMAC licensing was extracted for private/commercial forks:

```bash
bash packages/press-sentinel-licensing/scripts/build-licensing-zip.sh
```

See [packages/press-sentinel-licensing/INTEGRATION.md](packages/press-sentinel-licensing/INTEGRATION.md).

---

## License

GPL-2.0-or-later. See [license.txt](license.txt).
