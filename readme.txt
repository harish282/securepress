=== NiyiGuard ===
Contributors: harish282
Tags: security, two-factor, audit, login, woocommerce
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WordPress security: 2FA, lockouts, audit log, integrity, headers, rate limits, WooCommerce protection, and SDK. Free.

== Description ==

NiyiGuard hardens WordPress at the **application layer**: login abuse, accountability, file integrity, browser security headers, optional rate limits, and WooCommerce-specific threats. It **complements** your host firewall, CDN, or WAF — it does not replace them.

Source code and issue tracker: https://github.com/harish282/securepress

= Why install NiyiGuard? =

* **Self-hosted** — security data stays on your server; no NiyiGuard account and no usage telemetry to the author.
* **One dashboard** — enable or disable modules (authentication, audit log, integrity, headers, rate limits, WooCommerce protection).
* **For store owners** — reduce fake checkouts, cart and coupon abuse, registration spam, and Store API abuse when WooCommerce is active.
* **For developers** — protect custom `admin-post` handlers, forms, and REST routes with the **Security SDK** (CSRF, rate limits, signed URLs, route guards).
* **Fully free** — no license key, beta trial, or paywalled module in 0.1.0.

= What makes it different? =

Many security plugins offer two-factor auth, lockouts, headers, or malware scanning. NiyiGuard does not claim to be the only plugin with those features. It stands out in three ways:

1. **Developer SDK** — middleware-style helpers for **your** code paths, not only wp-admin toggles.
2. **WooCommerce abuse pipelines** — checkout, cart, registration, and Store API protection in the same package as audit logging and login hardening.
3. **Privacy-first** — no license server and no analytics to the author (see Privacy section below).

Longer positioning notes and reusable marketing copy: `docs/WHY_NIYIGUARD.md`.

= Features included (0.1.0) =

* **Authentication hardening** — login lockouts (IP and username), TOTP and email two-factor authentication, recovery codes, session tracking with remote revoke, and new-device suspicious-login email alerts.
* **Security headers** — HSTS, Content-Security-Policy, X-Frame-Options, Referrer-Policy, Permissions-Policy, and X-Content-Type-Options (each header can be toggled).
* **Audit log** — logins, plugin changes, role changes, selected option changes, file editor use, and WooCommerce-related actions. Admin list UI, detail view, retention, and scheduled pruning.
* **File integrity monitoring** — WordPress.org core checksum comparison, plugin manifest diff scans, suspicious PHP heuristics, and optional themes/uploads scopes (scheduled scans).
* **Rate limiting** — optional global throttling for front-end, AJAX, wp-login, and REST API traffic (wp-admin dashboard loads excluded by default).
* **WooCommerce Protection** — checkout, cart, registration, and Store API pipelines (velocity limits, honeypots, disposable-email checks, fraud scoring, coupon abuse). Requires WooCommerce.
* **CSRF middleware and SDK** — nonce verification for custom routes, forms, and REST handlers you register.
* **Signed URLs** — time-limited HMAC links for downloads, invites, and sensitive actions.
* **Login URL disguise** — optional custom login path instead of `wp-login.php` (off by default; test on staging first).
* **Safe mode** — emergency bypass via `NIYIGUARD_SAFE_MODE` in `wp-config.php` without changing saved settings.
* **Health diagnostics** — hooks, database tables, and module state on an admin screen.
* **MU loader helper** — optional must-use loader for earlier bootstrap in the request lifecycle.

The **NiyiGuard → Dashboard** includes optional links to leave a WordPress.org review or support development (Ko-fi). Neither is required.

= Developer APIs =

The `Security` facade provides route guards, CSRF fields, rate limiters, signed URLs, and related helpers. Documented in `docs/USAGE.md`. Middleware applies to **routes you protect** — it is not automatic site-wide protection for every WordPress hook. Before production, follow `docs/STAGING_TEST_PLAN.md`.

= Requirements =

* WordPress 6.4+
* PHP 8.2+
* MySQL 5.7+ or MariaDB 10.3+ (standard WordPress database)

== Installation ==

1. Upload the `niyiguard` folder to `/wp-content/plugins/` (or install from the WordPress.org plugin directory when listed).
2. Activate **NiyiGuard** on the **Plugins** screen.
3. Open **NiyiGuard** in the admin menu and review dashboard feature toggles.
4. (Recommended) Install the optional MU loader from **NiyiGuard → Dashboard** or follow `docs/MU_LOADER_INSTALL.md`.
5. Configure Authentication, Security Headers, Rate Limiting, File Integrity, WooCommerce Protection, and Audit Log before enabling strict rules on production.

== Frequently Asked Questions ==

= Does NiyiGuard replace Cloudflare or my host firewall? =

No. NiyiGuard is an in-application security layer. Use it together with edge and host protections.

= How is NiyiGuard different from Wordfence, Solid Security, or similar plugins? =

Those are mature products and often include cloud scanning or firewall services. NiyiGuard focuses on **modular, self-hosted** controls, a **Security SDK** for custom routes, and **WooCommerce abuse pipelines** in one free package. Choose NiyiGuard for application-layer hardening without a NiyiGuard cloud account. Choose an all-in-one cloud firewall/scanner if that is your primary need.

= Who should install NiyiGuard? =

**Good fit:** WooCommerce sites with checkout or spam issues; agencies with custom plugins; teams wanting audit, integrity, and login protection on-server; developers protecting custom forms and REST endpoints.

**Less ideal:** Sites that only want a single famous cloud malware suite with zero configuration — compare established plugins first. Multisite is not formally certified in 0.1.0.

= Does the plugin send data to the author? =

No telemetry or license callbacks. The routine outbound request is the **WordPress.org Core Checksums API** when integrity monitoring compares core files (`api.wordpress.org`). Optional Ko-fi links on the dashboard open in the browser; payments are handled by Ko-fi only. See the Privacy section below.

= Is the plugin really free? =

Yes. All security modules in 0.1.0 are included without a license key or time limit.

= How can I support development? =

Use **Support development** on **NiyiGuard → Dashboard** (optional Ko-fi tip) or leave a review on WordPress.org.

= I am locked out after enabling login disguise or lockout. What should I do? =

Add `define( 'NIYIGUARD_SAFE_MODE', true );` to `wp-config.php` (before WordPress loads plugins) or set `recovery.safe_mode` to `true` in `config/plugin.php`. Disable safe mode after you regain access.

= Does it work with WooCommerce? =

Yes. **WooCommerce Protection** is included and loads when WooCommerce is active and the module is enabled on the dashboard. Other features work without WooCommerce.

= Is multisite supported? =

Multisite has not been formally certified in 0.1.0. Test on staging first.

= Where is personal data stored? =

On your server: custom tables for audit logs, sessions, and integrity data; WordPress options and transients for settings and rate limits; user meta for two-factor state. See the Privacy section below.

== Screenshots ==

1. Dashboard — feature toggles, module status, and optional review / support section.
2. Authentication settings — lockout and two-factor options.
3. Audit log — filterable event list.
4. File integrity — scan results and findings.
5. WooCommerce Protection settings.

== Changelog ==

= 0.1.0 =
* Initial public release — all features free (no license or evaluation period).
* Positioning and documentation: `docs/WHY_NIYIGUARD.md`, updated directory readme.
* Security SDK: middleware pipeline, CSRF protection, signed URLs, route guards.
* Authentication hardening: lockout, TOTP/email 2FA, sessions, new-device alerts.
* Security headers module with per-header controls.
* Audit log with retention, pruning, detail view, and admin UI.
* File integrity: core checksums, manifest diff, suspicious PHP heuristics.
* Global rate limiting for REST, front end, AJAX, and wp-login.
* Login URL disguise and safe mode recovery.
* WooCommerce Protection (checkout, cart, registration, API pipelines).
* Health diagnostics, MU loader download, dashboard review and Ko-fi support links.

== Upgrade Notice ==

= 0.1.0 =
Initial release. Test authentication, URL disguise, and rate limits on staging before production.

== Privacy ==

NiyiGuard processes security-related data on your WordPress server (IP addresses, user agents, user IDs, audit events, session metadata, and similar fields when features are enabled). It does not sell personal data or include advertising trackers.

**Third-party service**

* **WordPress.org Core Checksums API** (`https://api.wordpress.org/core/checksums/1.0/`) — used for core file integrity checks (WordPress version and locale only; responses may be cached about 12 hours).

**Email**

Optional security emails (two-factor codes, suspicious-login alerts) use WordPress `wp_mail()` and your site's mail configuration.

**Optional donations**

If you use the dashboard Ko-fi link, payment and any data you provide are handled by Ko-fi under their terms, not by NiyiGuard.

Full details: `docs/PRIVACY.md` in the plugin folder, and the Privacy section below.
