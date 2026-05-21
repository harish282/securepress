=== PressSentinel ===
Contributors: harish282
Tags: security, authentication, audit, two-factor, woocommerce
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress security: 2FA, audit logs, login hardening, security headers, file integrity scans, and optional WooCommerce protection.

== Description ==

PressSentinel adds a structured security layer to WordPress without replacing your host firewall or CDN. It is built around clear modules, an admin dashboard, and optional developer APIs inspired by modern PHP application design.

**Free features (always available on your site)**

* **Authentication hardening** — login lockout, email/TOTP two-factor authentication, recovery codes, session tracking, and suspicious-login alerts.
* **Security headers** — HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, and X-Content-Type-Options (each can be toggled).
* **Audit log** — records authentication events, plugin changes, role changes, selected option changes, file-editor use, and WooCommerce-related events when WooCommerce is active.
* **File integrity monitoring** — baseline and diff scans for plugins; optional themes/uploads scopes; WordPress core checksum comparison.
* **Rate limiting** — optional global HTTP throttling for front end, REST, AJAX, and wp-login (wp-admin dashboard loads are excluded by default).
* **Login URL disguise** — optional custom login path instead of `wp-login.php` (off by default; configure carefully).
* **Safe mode** — emergency recovery via `PRESS_SENTINEL_SAFE_MODE` in `wp-config.php` or `recovery.safe_mode` in `config/plugin.php` to bypass disguise, lockouts, and global rate limits without changing saved settings.

**Pro features**

* **WooCommerce Protection** — behavioural checks for checkout, registration, cart, and WooCommerce REST traffic (velocity, honeypots, fraud scoring, API limits, and related controls).

New installs include an **evaluation period** during which Pro capabilities are unlocked so you can test WooCommerce Protection before entering a license key. After that period, Pro features require a valid license key stored in your database. The free feature set remains available without a key.

PressSentinel does **not** phone home to the plugin author for licensing or analytics. License validation is performed **on your server** using keys you paste in wp-admin (offline HMAC verification).

For developers, middleware-style helpers (CSRF, rate limiting, signed URLs) are documented in the plugin repository. See `docs/USAGE.md` after installation.

== Installation ==

1. Upload the `press-sentinel` folder to `/wp-content/plugins/`, or install through **Plugins → Add New** once the plugin is listed on WordPress.org.
2. Activate **PressSentinel** through the **Plugins** screen.
3. Open **Press Sentinel** in the admin menu and review the dashboard feature toggles.
4. (Recommended) Install the optional MU loader so PressSentinel loads earlier in the request lifecycle — see **Press Sentinel → Dashboard** or `docs/MU_LOADER_INSTALL.md` in the plugin folder.
5. Configure subsystems (Authentication, Security Headers, Rate Limiting, File Integrity, Audit Log) from their settings pages before enabling aggressive rules on production.

**Requirements**

* WordPress 6.4 or later
* PHP 8.2 or later
* MySQL 5.7+ / MariaDB 10.3+ (standard WordPress database requirements)

== Frequently Asked Questions ==

= Does PressSentinel replace my hosting firewall or Cloudflare? =

No. PressSentinel runs inside WordPress and focuses on application-layer controls (login abuse, headers, audit trail, integrity scans, WooCommerce abuse). Use host and edge firewalls together with this plugin.

= Does the plugin send data to the author or a third-party analytics service? =

No telemetry or license callbacks to the plugin author are included. The only routine outbound request is to the **WordPress.org Core Checksums API** when file integrity monitoring needs official core file hashes (`api.wordpress.org`). See the Privacy section and `PRIVACY.md` in the plugin directory.

= How does licensing work? =

Pro features (WooCommerce Protection) can be used during the built-in evaluation period without a key. After that, paste a license key on **Press Sentinel → License** (when shown). Keys are validated locally and stored in the WordPress database (`presssentinel_pro_license`). No WordPress.org account is required to use the free features.

= I locked myself out after enabling login disguise or lockout. What do I do? =

Enable **safe mode** by adding `define( 'PRESS_SENTINEL_SAFE_MODE', true );` to `wp-config.php` (before WordPress loads plugins) or set `recovery.safe_mode` to `true` in `config/plugin.php`. This bypasses login disguise, lockouts, and global rate limiting until you regain access. Turn safe mode off after fixing settings.

= Does PressSentinel work with WooCommerce? =

WooCommerce Protection is a **Pro** module. It registers hooks only when WooCommerce is active and the site has Pro access (evaluation period or valid license). Other features work without WooCommerce.

= Where is personal data stored? =

On your server: custom tables for audit logs, sessions, and integrity data; WordPress options and transients for settings, rate limits, and lockouts; user meta for two-factor state. Details are in `PRIVACY.md`.

= Is the code obfuscated? =

No. PHP source is shipped as readable files under the GPL.

= Can I use PressSentinel on multisite? =

Multisite has not been formally certified in this release. Test on staging before production use.

== Screenshots ==

1. Press Sentinel dashboard — feature toggles and status overview.
2. Authentication hardening settings — lockout and two-factor options.
3. Audit log — searchable security event list.
4. File integrity — findings and scan controls.
5. WooCommerce Protection settings (Pro).

== Changelog ==

= 0.1.0 =
* Initial public release.
* Authentication hardening: lockout, TOTP/email 2FA, sessions, suspicious-login notifications.
* Security headers module with per-header controls.
* Audit log with retention and pruning.
* File integrity: core checksums, manifest diff, suspicious PHP heuristics.
* Optional global rate limiting (REST and front end; wp-admin excluded).
* Optional login URL disguise and safe mode recovery.
* WooCommerce Protection module (Pro / evaluation period).
* Offline license key storage and local HMAC validation.
* Health diagnostics and MU loader download helper.

== Upgrade Notice ==

= 0.1.0 =
Initial release. Review authentication and URL disguise settings on staging before enabling on production.

== Privacy ==

PressSentinel processes security-related data **on your WordPress server** (IP addresses, user agents, user IDs, audit events, session metadata, and similar fields when features are enabled). It does not sell personal data or include advertising trackers.

**Third-party service**

* **WordPress.org Core Checksums API** (`https://api.wordpress.org/core/checksums/1.0/`) — used when file integrity monitoring compares WordPress core files. Sends WordPress version and locale only. Responses may be cached in transients for about 12 hours.

**Email**

Optional security emails (for example two-factor codes or suspicious-login alerts) are sent using WordPress `wp_mail()` and your site’s mail configuration.

**Site owner responsibility**

You are responsible for your site’s privacy policy and lawful basis for processing. Full details: `PRIVACY.md` in the plugin folder.
