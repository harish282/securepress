# Why NiyiGuard?

Use this document for your WordPress.org listing, plugin page copy, blog posts, or README excerpts. The canonical public version for the directory is in **`readme.txt`** at the repo root.

---

## One-line summary

**NiyiGuard is self-hosted application-layer security for WordPress** — login hardening, audit trail, file integrity, security headers, optional rate limits, WooCommerce abuse protection, and a developer SDK for protecting your own routes — **with no license server and no telemetry to the author**.

---

## Why site owners should install it

| You need… | NiyiGuard helps by… |
| --- | --- |
| Fewer brute-force logins | IP + username lockouts, optional 2FA (TOTP, email OTP, recovery codes) |
| Accountability | Audit log: logins, plugin/role changes, sensitive options, file editor, WooCommerce events |
| Early warning on file tampering | Core checksums, plugin manifest diff, PHP heuristics (scheduled scans) |
| Stronger browser policies | Per-header controls: HSTS, CSP, X-Frame-Options, Referrer-Policy, and more |
| Less REST / front-end abuse | Optional global rate limiting (wp-admin excluded by default) |
| WooCommerce spam & fake checkouts | Checkout, cart, registration, and Store API protection pipelines |
| Recovery from misconfiguration | Safe mode via `wp-config.php` if lockout or login disguise blocks access |
| Privacy & control | Data stays on your server; no account with NiyiGuard required |

**Plain-language pitch:**  
*Security building blocks that run on your server — not in our cloud. Turn on what you need from one dashboard.*

---

## Who it is for

**Good fit**

- WooCommerce stores dealing with bot checkout, coupon abuse, or registration spam  
- Agencies and developers who maintain custom plugins, themes, or `admin-post` handlers  
- Site owners who want audit + integrity + login protection in **one free GPL plugin**  
- Hosts and teams that prefer **no SaaS dependency** for these features  

**Less ideal (be honest in support)**

- “I only want a famous all-in-one firewall + cloud malware scanner” — consider Wordfence, Sucuri, or your host WAF  
- “I need edge DDoS protection” — use CDN/host firewall; NiyiGuard is **in-application**  
- Multisite — not formally certified in 0.1.0; test on staging first  

---

## What NiyiGuard does *not* claim

- It does **not** replace Cloudflare, your host firewall, or a CDN.  
- It does **not** scan every file like a full antivirus engine (heuristics + checksums, not a commercial AV cloud).  
- It does **not** automatically protect every WordPress hook — the **SDK protects routes you wire** (CSRF, rate limit, signed URLs).  
- It does **not** require a license key or send usage data to the author.  

Use it **together with** edge and host protections, not instead of them.

---

## How it compares to other security plugins

Many excellent plugins (Wordfence, Solid Security, All-In-One WP Security, dedicated audit plugins, etc.) overlap on **2FA, lockout, headers, and scanning**. NiyiGuard does not claim to be the only plugin with those features.

### Where NiyiGuard is similar (table stakes)

- Login lockout and two-factor authentication  
- Security headers  
- Activity / audit style logging  
- File change detection (approaches vary)  

### Where NiyiGuard is more distinctive

**1. Developer-first security SDK**  
Most security plugins focus on admin toggles. NiyiGuard also ships a **`Security` facade** so you can protect custom code paths with **CSRF verification, rate limits, signed URLs, and route guards** — useful for membership sites, custom checkout flows, and agency-built plugins.

**2. WooCommerce abuse in the same package**  
Checkout velocity, cart/coupon abuse, registration spam, disposable-email checks, fraud scoring, and Store API throttling live alongside audit logging and login hardening — one dashboard, one privacy story, self-hosted.

**3. Privacy and architecture**  
- No telemetry to the plugin author  
- No license server or “phone home” for activation  
- Routine external call: WordPress.org core checksums API when integrity scans compare core files  

**4. Operator-friendly recovery**  
- **Safe mode** for emergency bypass when disguise or lockout locks you out  
- Optional **MU loader** for earlier bootstrap in the request lifecycle  
- **Health diagnostics** for hooks, database tables, and module state  

**5. Fully free public release**  
All modules in 0.1.0 ship without a paywall, trial, or license key.

---

## Feature list (0.1.0)

- Authentication hardening (lockout, 2FA, sessions, new-device alerts)  
- Security headers (per-header toggles)  
- Audit log (UI, detail view, retention, pruning)  
- File integrity monitoring (core, plugins, heuristics; themes/uploads optional)  
- Global rate limiting (optional; wp-admin excluded by default)  
- WooCommerce Protection (when WooCommerce is active)  
- CSRF middleware and SDK helpers  
- Signed URLs for time-limited links  
- Login URL disguise (off by default)  
- Safe mode recovery  
- Health diagnostics  
- MU loader download helper  

---

## Suggested copy blocks

### Short (plugin card / tagline)

> Self-hosted WordPress security: 2FA, lockouts, audit log, integrity scans, headers, rate limits, WooCommerce abuse protection, and a developer SDK — free, no cloud account required.

### Medium (directory intro paragraph)

> NiyiGuard hardens WordPress at the application layer: stop login abuse, record who changed what, detect unexpected file changes, send security headers, and (with WooCommerce) reduce fake checkouts and API spam. Everything runs on your server with no license server and no analytics to the author. Developers can protect custom routes and forms using the built-in Security SDK (CSRF, rate limits, signed URLs). Complements your host firewall and CDN — does not replace them.

### FAQ-style (for support or readme)

**How is this different from Wordfence or Solid Security?**  
Those are strong, full-featured products often bundled with cloud scanning or firewall services. NiyiGuard focuses on modular, self-hosted building blocks plus a **code-first SDK** and **integrated WooCommerce pipelines**, without requiring a NiyiGuard account.

**Do I still need a firewall?**  
Yes, for many sites. Use host or edge WAF/CDN protection together with NiyiGuard’s in-app controls.

---

## Version

Aligned with plugin **0.1.0**. Update this file when the feature set or positioning changes.
