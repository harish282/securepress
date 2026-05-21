# PressSentinel — Privacy Policy

**Plugin:** PressSentinel  
**Version:** 0.1.0 (see `press-sentinel.php`)  
**License:** GPL-2.0-or-later  

This document describes how **PressSentinel** handles data on a WordPress site where it is installed. It is written for **site owners and administrators** (who act as data controllers for their visitors and users) and for **reviewers** assessing the plugin for the [WordPress Plugin Directory](https://wordpress.org/plugins/).

PressSentinel is **security infrastructure** for WordPress. It does **not** operate a separate SaaS backend for core features, does **not** sell personal data, and does **not** include advertising or behavioural analytics trackers.

---

## Who is responsible?

- **Site owner / administrator:** Decides whether to install and configure PressSentinel, sets retention and feature toggles, and is responsible for the site’s overall privacy policy and lawful basis for processing (for example under GDPR, UK GDPR, or CCPA, as applicable).
- **Plugin author:** Provides the software only. Except for optional outbound requests documented below, personal data **stays on the server** where WordPress runs.

---

## Summary

| Topic | PressSentinel behaviour |
|--------|------------------------|
| Data sent to the plugin author | **No** telemetry or analytics to PressSentinel by default |
| Data sent to third parties | **Only** when a documented feature makes an outbound request (see below) |
| Visitor tracking / profiling | **No** cross-site tracking or ad networks |
| Account required with vendor | **No** (offline license validation; optional license key stored locally) |
| Email | Uses WordPress `wp_mail()` only when you enable notifications (e.g. 2FA, suspicious login alerts) |

---

## What personal data may be processed?

Personal data is processed **only when the relevant features are enabled** and a qualifying event occurs (login, checkout, API request, etc.).

### Identifiers and account data

- **WordPress user ID**, **username**, and **email address** (from the logged-in user or order context).
- **License key** (if the administrator saves one on **PressSentinel → License**), stored in the database option `presssentinel_pro_license`.

### Network and device data

- **IP address** (from the web server / WordPress request), used for login lockout, rate limiting, audit entries, session records, and WooCommerce abuse detection.
- **User agent** string (browser/client), stored in audit logs and session records where applicable.
- **Request URI** (path of the HTTP request), stored in audit log entries.

### Authentication and security data

- **Two-factor authentication (2FA):** TOTP secrets, recovery codes, and enrolment state in user meta (`_presssentinel_2fa_state`, `_presssentinel_2fa_pending_secret` during setup). Email OTP codes are sent via `wp_mail()` and held in short-lived transients until used or expired.
- **Login lockout:** Failed-attempt counters and lock flags in **transients** (keys prefixed `sp_lockout_`), keyed by hashed identifiers derived from username/IP (not stored as plain usernames in the lockout keys themselves).
- **Sessions (PressSentinel session log):** Session token hash, device fingerprint hash, IP, user agent, labels, and timestamps in table `{prefix}presssentinel_sessions` (see Retention).

### Audit log

When the audit log is enabled, events may include actor ID/name, IP, user agent, request URI, message, and JSON **context** (event-specific details such as option names, plugin slugs, or WooCommerce order IDs). Stored in `{prefix}presssentinel_audit_logs`.

### File integrity

- **File paths and cryptographic hashes** of core, plugin, theme, and upload files in `{prefix}presssentinel_integrity_baselines` and `{prefix}presssentinel_integrity_findings`.
- **Code snippets** (limited excerpts) may be stored in finding context when heuristics match suspicious PHP patterns.

### WooCommerce (Pro, when licensed or in beta trial)

When WooCommerce Protection is active, the plugin may process checkout/registration/cart/API-related signals, including IP, user agent, cart fingerprints, timing behaviour, email addresses entered on forms, and abuse counters in **transients** (plugin-specific prefixes such as `sp_wc_`). Decisions may be written to the audit log.

### Rate limiting

When enabled, request counts are stored in **transients** keyed by user ID and/or IP (implementation via `RateLimitMiddleware` / `TransientStore`).

### Site configuration (not personal data by itself)

Plugin settings are stored in WordPress **options** (for example `presssentinel_auth_hardening`, `presssentinel_audit_log`, `presssentinel_security_headers`, `presssentinel_integrity`, `presssentinel_rate_limit`, `presssentinel_url_disguise`, `presssentinel_woocommerce_protection`).  
A per-site **license HMAC secret** is stored in `presssentinel_license_hmac_secret` (used to verify offline license keys; treat as confidential).  
Beta trial start time may be stored in `presssentinel_beta_trial_started_at`.

### Log files

If file logging is enabled, diagnostic messages may be written to `wp-content/plugins/press-sentinel/storage/logs/` (or the path configured). These logs are intended for administrators and should not include end-user passwords.

---

## Why is data processed?

Processing supports **legitimate security purposes** chosen by the site administrator, including:

- Detecting and limiting brute-force login attempts  
- Enforcing two-factor authentication  
- Recording security-relevant changes (plugins, roles, options, authentication)  
- Detecting modified or suspicious files  
- Limiting abusive HTTP, checkout, or API traffic  
- Optional email alerts for suspicious logins  

The administrator configures which modules are on and retention periods where applicable.

---

## Where is data stored?

All data listed above is stored **on the same server** as the WordPress installation (MySQL/MariaDB tables and options, WordPress transients/object cache, and optional local log files), unless an outbound request is explicitly made (below).

---

## Retention

| Data | Default retention behaviour |
|------|-----------------------------|
| Audit log | Configurable; default **90 days**, with optional automatic pruning (`presssentinel_audit_log` settings) |
| PressSentinel sessions | Pruned after configurable **retention** (default **90 days** of inactivity) |
| Integrity findings | Configurable; default **60 days** (see `config/plugin.php` / integrity settings) |
| Login lockout transients | Expire automatically after the lockout/window TTL |
| Rate limit / WooCommerce abuse transients | Expire automatically after their window TTL |
| 2FA challenge transients | Short TTL (minutes) |
| License key | Until removed by an administrator |
| Beta trial timestamp | Until removed manually or on uninstall (no automatic erasure) |

Administrators can clear audit logs from the admin UI and adjust retention in plugin settings.

---

## Third-party services and outbound connections

PressSentinel **does not** contact the plugin author’s servers for licensing or telemetry in the default build.

The plugin **may** contact the following **only when the related feature runs**:

### WordPress.org Core Checksums API

- **When:** File integrity monitoring compares WordPress core files and needs official checksums.  
- **Endpoint:** `https://api.wordpress.org/core/checksums/1.0/`  
- **Data sent:** WordPress **version** and **locale** (query parameters). No site-specific user data is intentionally transmitted.  
- **Caching:** Responses are cached in WordPress **transients** (approximately 12 hours).  
- **Privacy:** See [WordPress.org Privacy Policy](https://wordpress.org/about/privacy/).

No other third-party APIs are required for core operation. **Email** delivery uses the site’s configured `wp_mail()` transport (SMTP plugin, PHP mail, etc.) — that transport is the site owner’s responsibility.

---

## Sharing and selling of data

PressSentinel does **not** sell, rent, or share personal data with the plugin author for marketing purposes. Data is not transmitted to analytics or advertising networks by this plugin.

Any sharing with **email providers**, **hosts**, or **security tools** happens only because the **site owner** configured WordPress or server infrastructure that way.

---

## Administrator access

Users with appropriate WordPress capabilities (typically `manage_options`) can view audit logs, integrity findings, session lists, license status, and diagnostics in wp-admin. Protect administrator accounts accordingly.

---

## End-user rights

PressSentinel does not provide a separate “privacy portal” for visitors. Rights requests (access, erasure, restriction, etc.) should be handled by the **site owner** under their site privacy policy.

Helpful WordPress tools:

- **Tools → Export Personal Data** and **Tools → Erase Personal Data** (WordPress core)  
- Removing a WordPress user account removes associated user meta (including PressSentinel 2FA meta) subject to WordPress behaviour  
- Audit log rows may still contain historical references to a user ID or IP until pruned or cleared by an administrator  

Site owners should document PressSentinel in their public privacy policy. The plugin may also register suggested policy text via `wp_add_privacy_policy_content()` when that integration is enabled in code.

---

## Security measures

- Capability checks and nonces on admin actions  
- Prepared SQL for database writes where applicable  
- Hashed or truncated values where appropriate (session tokens, fingerprints, license key display)  
- Optional HMAC signing for license keys and URLs  
- File log directory hardening (`.htaccess` / `index.html`) when logs are created  

No security plugin can guarantee complete protection; administrators remain responsible for updates, backups, and server hardening.

---

## Children’s privacy

PressSentinel does not target children and does not knowingly collect data from children. Sites directed at children should consult legal counsel and configure features appropriately.

---

## Uninstall and data removal

PressSentinel does not register a WordPress `uninstall.php` hook at this time. **Deactivating** the plugin leaves database tables and options in place. To remove data, administrators should:

1. Clear audit logs and integrity data from the admin UI where available, and  
2. Optionally delete plugin options and custom tables manually, or use a database cleanup tool.

Future releases may add an uninstall routine; check the changelog.

---

## Changes to this document

This file may be updated between plugin releases. The version in the plugin package at install time applies unless the site owner replaces it.

---

## Contact

For **privacy questions about a specific site** using PressSentinel, contact that **site’s administrator**.

For **questions about the plugin software**, use the support channel listed on the plugin’s WordPress.org page or repository (for example GitHub issues), not this file.

---

## WordPress.org Plugin Directory

If you submit this plugin to [WordPress.org](https://wordpress.org/plugins/), also include a **Privacy** section in `readme.txt` that summarizes third-party services and links to this file, as required by the [Plugin Directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) (especially guideline **#7**).
