=== PressSentinel ===
Contributors: harish282
Tags: security, two-factor, audit, login, woocommerce
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Application-layer security for WordPress: 2FA, login lockouts, audit log, headers, integrity scans, rate limits, and optional WooCommerce protection.

== Description ==

See [readme.txt](readme.txt) for the WordPress.org-formatted plugin description (required for directory submissions).

---

# PressSentinel

Laravel-inspired security infrastructure for WordPress — middleware, developer APIs, authentication hardening, audit logging, and WooCommerce protection.

| | |
| --- | --- |
| **Version** | 0.1.0 (beta) |
| **PHP** | 8.2+ |
| **WordPress** | 6.4+ |
| **Tests** | 509 PHPUnit tests passing |
| **Plugin Check** | Clean (errors and warnings resolved) |

## Quick start

1. Clone or copy into `wp-content/plugins/presssentinel` (or run `bash scripts/build-release-zip.sh dev` from this repo).
2. Activate **PressSentinel** in wp-admin.
3. Optional: install the [MU loader](docs/MU_LOADER_INSTALL.md) for earlier bootstrap.
4. Read [docs/USAGE.md](docs/USAGE.md) for CSRF, rate limits, signed URLs, and route protection.

## Implementation status (vs project plan)

This table maps [presssentinel_wordpress_security_plugin_project_plan.md](presssentinel_wordpress_security_plugin_project_plan.md) and [ROADMAP_AGILE.md](ROADMAP_AGILE.md) to what ships in **0.1.0** and how it is verified.

### MVP and core security (plan §6 — Version 1.0)

| Feature | Status | Notes |
| --- | --- | --- |
| Login protection (lockout, failed-login tracking) | **Shipped** | `LoginLockoutService`, transients/options; tested |
| Rate limiting (login, REST, front end) | **Shipped** | Global subscriber + `RateLimitMiddleware` SDK; wp-admin excluded by default |
| Security headers (CSP, HSTS, XFO, etc.) | **Shipped** | Per-header toggles; `SecurityHeadersDispatcher` |
| Signed URLs | **Shipped** | `UrlSigner`, `SignedUrlMiddleware`; tested |
| CSRF layer | **Shipped** | `CsrfProtectionMiddleware`, `CsrfTokenManager`, `Security` facade |
| Audit logging | **Shipped** | DB storage, listeners (auth, plugins, roles, file editor, WooCommerce); admin UI |
| Developer SDK | **Shipped** | `Security` / `AuditLog` facades, `RouteBuilder`, middleware registration |

### Version 1.1 items (plan §7) — largely included in 0.1.0

| Feature | Status | Notes |
| --- | --- | --- |
| Two-factor (TOTP, email OTP, recovery codes) | **Shipped** | `TwoFactorService`, wp-login challenge flow; tested |
| Device & session management | **Shipped** | Custom sessions table, revoke, pruning; tested |
| WooCommerce security pack | **Shipped (Pro)** | Checkout/cart/registration/API pipelines; PHPUnit coverage |
| Laravel-style validation layer | **Not shipped** | No `Validator::make()` module yet |

### Version 2.0 / advanced (plan §8)

| Feature | Status | Notes |
| --- | --- | --- |
| File integrity monitoring | **Shipped** | Core checksums, manifest diff, heuristics (free tier) |
| Malware / heuristic scanning | **Partial** | PHP heuristics only — not a full AV engine (by design) |
| Threat intelligence / cloud SaaS | **Not shipped** | Future roadmap |

### Agile sprints (ROADMAP_AGILE.md)

| Sprint | Goal | Status |
| --- | --- | --- |
| 0 — Initialization | Repo, standards, backlog | **Partial** — code and tests exist; GitHub Projects/issue seeding optional |
| 1 — Foundation | Bootstrap, container, config, logging | **Done** |
| 2 — Middleware core | Pipeline, ordering, short-circuit | **Done** — 87+ middleware-related tests |
| 3 — Auth & abuse | Lockout, suspicious login, REST throttle | **Done** — XML-RPC not a separate module; may fall under global/front-end limits |
| 4 — CSRF, signed URLs, headers | Integrity controls | **Done** |
| 5 — Audit & admin UX | Log UI, retention | **Mostly done** — **audit export (CSV) not implemented** |
| 6 — WooCommerce | Abuse prevention pack | **Done in code** — requires WooCommerce + Pro/eval on site for live checks |
| 7 — Beta launch | Security review, docs, release | **In progress** — Plugin Check clean; formal OWASP checklist not automated in repo |

### Architecture plan vs codebase

| Planned (plan §3–5) | Actual 0.1.0 |
| --- | --- |
| Monolog, Symfony HTTP, dotenv | **Not used** — lightweight `FileLogger`, `config/plugin.php`, wp-config constants |
| React / Gutenberg admin UI | **Not used** — PHP admin views under `resources/views/` |
| PestPHP | **PHPUnit only** |
| `routes/` directory | **Not present** — route guards via SDK / `RouteGuardRegistry` |
| Bot / geo middleware | **Not shipped** |

### Extra features (not in original MVP list)

| Feature | Status |
| --- | --- |
| Login URL disguise | Shipped |
| Safe mode recovery | Shipped |
| MU loader early bootstrap | Shipped |
| Health diagnostics admin page | Shipped |
| Offline Pro licensing (HMAC) | Shipped |

## Working as intended?

**Automated verification:** `vendor/bin/phpunit` — **509 tests, 1372 assertions, all passing** (security middleware, lockout, 2FA, integrity, WooCommerce pipelines, licensing, signed URLs, CSRF).

**Manual verification recommended on a staging site:**

* Enable auth hardening → confirm lockout after failed logins and 2FA challenge on wp-login.
* Toggle security headers → inspect response headers on front end and REST.
* Run a file integrity scan → confirm findings table updates.
* With WooCommerce + Pro/eval → place test checkout/cart actions and review audit log / blocked responses.
* Enable global rate limit → confirm HTTP 429 on burst REST or front-end requests (not wp-admin).

**Known gaps (functionality unchanged by Plugin Check fixes):** Plugin Check work was PHPCS suppressions, `WpHelper` input wrappers, and view syntax fixes — no intentional weakening of security logic. Remaining product gaps are listed above (export, validation layer, full-site auto-middleware kernel, SaaS).

## Development

```bash
composer install
vendor/bin/phpunit
bash scripts/build-release-zip.sh dev    # sync to wp-content/plugins/presssentinel
bash scripts/build-release-zip.sh prod   # zip under ./build

# Staging E2E (requires a live WP site — see docs/CYPRESS_E2E.md)
npm install
npm run test:e2e
```

Configuration: `config/plugin.php` and optional `wp-config.php` constants (`PRESS_SENTINEL_SAFE_MODE`, `PRESS_SENTINEL_PRO_LICENSE`, `PRESS_SENTINEL_LICENSE_SECRET`). See [docs/USAGE.md](docs/USAGE.md) and [PRIVACY.md](PRIVACY.md).

## Documentation

| Document | Purpose |
| --- | --- |
| [readme.txt](readme.txt) | WordPress.org plugin directory readme |
| [docs/USAGE.md](docs/USAGE.md) | Developer and operator usage |
| [docs/MU_LOADER_INSTALL.md](docs/MU_LOADER_INSTALL.md) | Early-load MU plugin setup |
| [docs/STAGING_TEST_PLAN.md](docs/STAGING_TEST_PLAN.md) | Step-by-step staging QA checklist |
| [docs/CYPRESS_E2E.md](docs/CYPRESS_E2E.md) | Cypress automation for the staging plan |
| [ROADMAP_AGILE.md](ROADMAP_AGILE.md) | Sprint backlog |
| [presssentinel_wordpress_security_plugin_project_plan.md](presssentinel_wordpress_security_plugin_project_plan.md) | Product vision and phases |

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) if present in your distribution package.
