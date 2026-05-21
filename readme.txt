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

PressSentinel adds structured, modular security inside WordPress. It does not replace your hosting firewall, WAF, or CDN. It focuses on login abuse, request integrity, visibility, and WooCommerce-specific threats.

**Included in the free release (0.1.0)**

* **Authentication hardening** — configurable login lockouts (IP and username), TOTP and email two-factor authentication, recovery codes, session tracking with remote revoke, and suspicious-login email alerts.
* **Security headers** — HSTS, Content-Security-Policy, X-Frame-Options, Referrer-Policy, Permissions-Policy, and X-Content-Type-Options (each header can be toggled).
* **Audit log** — stores events for logins, plugin changes, role changes, selected option changes, built-in file editor use, and WooCommerce-related actions when WooCommerce is active. Includes admin list UI, detail view, retention settings, and scheduled pruning.
* **File integrity monitoring** — WordPress.org core checksum comparison, plugin manifest diff scans, suspicious PHP heuristics, and optional themes/uploads scopes.
* **Rate limiting** — optional global throttling for front-end, AJAX, wp-login, and REST API traffic (wp-admin dashboard loads are excluded by default).
* **CSRF middleware and SDK helpers** — nonce verification for custom routes, forms, and REST handlers you register via the developer API.
* **Signed URLs** — time-limited HMAC links for downloads, invites, and other sensitive actions.
* **Login URL disguise** — optional custom login path instead of `wp-login.php` (off by default; test on staging first).
* **Safe mode** — emergency bypass via `PRESS_SENTINEL_SAFE_MODE` in `wp-config.php` or `recovery.safe_mode` in `config/plugin.php` without changing saved settings.
* **Health diagnostics** — admin screen for hooks, database tables, and environment checks.
* **MU loader helper** — downloadable must-use loader so the plugin can initialize earlier in the request lifecycle.

**Pro module (evaluation period or license key)**

* **WooCommerce Protection** — middleware pipelines for checkout, cart, registration, and Store API traffic (velocity limits, honeypots, disposable-email checks, fraud scoring, coupon abuse, and related controls).

New installs include a built-in **evaluation period** with Pro features unlocked for testing. After that period, WooCommerce Protection requires a license key validated **on your server** (offline HMAC). The plugin does not phone home for licensing or analytics.

**Developer APIs**

Middleware-style helpers (`Security` facade), route guards, CSRF fields, rate limiters, signed URLs, and audit APIs are documented in `docs/USAGE.md` in the plugin directory. Middleware runs on routes you protect; it is not a blanket replacement for every WordPress hook until you wire it. Before production, follow the staging checklist in `docs/STAGING_TEST_PLAN.md`.

**Requirements**

* WordPress 6.4+
* PHP 8.2+
* MySQL 5.7+ or MariaDB 10.3+ (standard WordPress database)

== Installation ==

1. Upload the `presssentinel` folder to `/wp-content/plugins/` (or install from the WordPress.org plugin directory when listed).
2. Activate **PressSentinel** on the **Plugins** screen.
3. Open **Press Sentinel** in the admin menu and review dashboard feature toggles.
4. (Recommended) Install the optional MU loader from **Press Sentinel → Dashboard** or follow `docs/MU_LOADER_INSTALL.md`.
5. Configure Authentication, Security Headers, Rate Limiting, File Integrity, and Audit Log on their settings pages before enabling strict rules on production.

== Frequently Asked Questions ==

= Does PressSentinel replace Cloudflare or my host firewall? =

No. PressSentinel is an in-application security layer. Use it together with edge and host protections.

= Does the plugin send data to the author? =

No telemetry or license callbacks are included. The routine outbound request is to the **WordPress.org Core Checksums API** when integrity monitoring compares core files (`api.wordpress.org`). See `PRIVACY.md`.

= How does licensing work? =

Use Pro features during the evaluation period without a key. After that, enter a key on **Press Sentinel → License** when shown. Keys are stored in your database and validated locally.

= I am locked out after enabling login disguise or lockout. What should I do? =

Add `define( 'PRESS_SENTINEL_SAFE_MODE', true );` to `wp-config.php` (before WordPress loads plugins) or set `recovery.safe_mode` to `true` in `config/plugin.php`. Disable safe mode after you regain access.

= Does it work with WooCommerce? =

WooCommerce Protection is a Pro module and loads only when WooCommerce is active and the site has Pro access. Other features work without WooCommerce.

= Is multisite supported? =

Multisite has not been formally certified in 0.1.0. Test on staging first.

= Where is personal data stored? =

On your server: custom tables for audit logs, sessions, and integrity data; WordPress options and transients for settings and rate limits; user meta for two-factor state. See `PRIVACY.md`.

== Screenshots ==

1. Dashboard — feature toggles and module status.
2. Authentication settings — lockout and two-factor options.
3. Audit log — filterable event list.
4. File integrity — scan results and findings.
5. WooCommerce Protection settings (Pro).

== Changelog ==

= 0.1.0 =
* Initial release.
* Middleware pipeline, CSRF protection, signed URLs, and Security SDK facade.
* Authentication hardening: lockout, TOTP/email 2FA, sessions, suspicious-login notifications.
* Security headers module with per-header controls.
* Audit log with retention, pruning, and admin UI.
* File integrity: core checksums, manifest diff, suspicious PHP heuristics.
* Global rate limiting for REST, front end, AJAX, and wp-login.
* Login URL disguise and safe mode recovery.
* WooCommerce Protection module (Pro / evaluation period).
* Offline license validation, health diagnostics, MU loader download.

== Upgrade Notice ==

= 0.1.0 =
Initial release. Test authentication, URL disguise, and rate limits on staging before production.

== Privacy ==

PressSentinel processes security-related data on your WordPress server (IP addresses, user agents, user IDs, audit events, session metadata, and similar fields when features are enabled). It does not sell personal data or include advertising trackers.

**Third-party service**

* **WordPress.org Core Checksums API** (`https://api.wordpress.org/core/checksums/1.0/`) — used for core file integrity checks (WordPress version and locale only; responses may be cached about 12 hours).

**Email**

Optional security emails (two-factor codes, suspicious-login alerts) use WordPress `wp_mail()` and your site's mail configuration.

Full details: `PRIVACY.md` in the plugin folder.
