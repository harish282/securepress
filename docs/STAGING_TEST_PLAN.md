# NiyiGuard — Staging test plan

Use this checklist on a **staging** site before production. Work through sections in order the first time; later releases can re-run only what changed.

**Goals**

- Confirm each security module behaves as designed.
- Confirm you can recover if login disguise, lockout, or rate limits block access.
- Leave production with conservative defaults until staging passes.

---

## 1. Prerequisites

| Item | Notes |
| --- | --- |
| Staging URL | Separate from production; real mail optional (use a mail catcher or disable auth emails in config). |
| WordPress | 6.4+ |
| PHP | 8.2+ |
| Database | Normal WP install; plugin creates custom tables on first run. |
| Admin access | One administrator account you control; one **test subscriber** (or second admin) for 2FA/session tests. |
| SFTP / SSH | To edit `wp-config.php` if you need safe mode. |
| Optional: WooCommerce | Install and activate if you will test Pro checkout protection. |

**Deploy the plugin**

```bash
# From the plugin dev repo
bash scripts/build-release-zip.sh dev
# Copies to wp-content/plugins/niyiguard on this machine's WP tree
```

Or upload the zip from `bash scripts/build-release-zip.sh prod` and activate in **Plugins**.

**After activation**

1. Open **NiyiGuard → Dashboard** (`admin.php?page=niyiguard`).
2. Confirm no PHP errors or “requirements” admin notice.
3. (Recommended) Download and install the **MU loader** from the dashboard callout, then reload the dashboard — callout should disappear when detected.
4. Open **NiyiGuard → Health** (`admin.php?page=niyiguard-health`) and note any failed rows before deep testing.

**Useful paths**

| Screen | Admin URL slug |
| --- | --- |
| Dashboard | `page=niyiguard` |
| Authentication | `page=niyiguard-authentication` |
| Security Headers | `page=niyiguard-security-headers` |
| Rate limiting | `page=niyiguard-rate-limit` |
| URL disguise | `page=niyiguard-url-disguise` |
| File integrity | `page=niyiguard-file-integrity` |
| Audit log | `page=niyiguard-audit-logs` |
| Audit settings | `page=niyiguard-audit-settings` |
| WooCommerce (Pro) | `page=niyiguard-woocommerce` |
| License | `page=niyiguard-license` |
| Account Security (users) | Top-level **Account Security** menu (when auth hardening is on) |

**Log file (optional)**  
`wp-content/plugins/niyiguard/storage/logs/niyiguard.log` (if file logging is enabled in config).

---

## 2. Pre-flight (5 minutes)

- [ ] Plugin activates without fatal errors.
- [ ] Dashboard loads; feature toggles reflect saved state.
- [ ] Health diagnostics: custom tables exist (`niyiguard_audit_logs`, sessions, integrity tables when modules have run).
- [ ] Run PHPUnit locally on the release commit: `vendor/bin/phpunit` (509 tests should pass).
- [ ] Plugin Check report is clean on the same build you deployed.

**Staging-only config tips**

- Keep **URL disguise** off until section 8.
- Keep **global rate limit** off until section 6 (or use a high limit, e.g. 500/minute).
- For lockout tests, use a **dedicated test username** — not your only admin account.
- To avoid real emails on staging, set in `config/plugin.php` under `auth_hardening.notifications`: `'enabled' => false` (re-enable for production mail tests).

---

## 3. Dashboard & feature toggles

**Steps**

1. Go to **NiyiGuard → Dashboard**.
2. Note status cards (Authentication, Headers, Integrity, Audit, License).
3. Turn **one** module off (e.g. Security Headers), save, reload — card should show off.
4. Turn it back on and save.

**Pass criteria**

- [ ] Save shows success notice; toggles persist after reload.
- [ ] No PHP notices in HTML or `debug.log`.

---

## 4. Audit log

**Steps**

1. Enable **Audit log** on the dashboard if off.
2. **Plugins →** deactivate then activate any inactive plugin (or switch a harmless plugin).
3. Open **NiyiGuard → Audit log**.
4. Filter by category **plugin** (if available) and find the activation/deactivation event.
5. Open **Audit settings** — set retention (e.g. 90 days), save.
6. Use **Run prune now** (confirm dialog) — only old rows beyond retention should be removed.
7. Optional: open **Appearance → Theme File Editor** or **Plugin File Editor** (if allowed) — a **view** event should appear; do not save file changes unless you intend to test **modified** events.

**Pass criteria**

- [ ] Plugin change appears with sensible action/message.
- [ ] Prune completes without error.
- [ ] Detail view opens for a single event when linked.

---

## 5. Authentication hardening

### 5.1 Login lockout

**Steps**

1. **NiyiGuard → Authentication** — confirm lockout enabled (defaults: 5 attempts / 15 min window / 15 min lock).
2. Log out. Attempt login with a **fake password** for test user `staging_lockout_test` (create if needed) until locked out.
3. Try again immediately — should be blocked (message or delay).
4. Check **Audit log** for failed-login / lockout-related auth events.
5. Wait for lock to expire **or** clear transients/options for that user/IP if you have tooling — then log in with the correct password.

**Pass criteria**

- [ ] Lockout triggers after configured attempts.
- [ ] Legitimate login works after lock expires.
- [ ] Events visible in audit log.

### 5.2 Two-factor authentication (TOTP)

**Steps**

1. Log in as test user → **Account Security** (or profile flow).
2. Enrol TOTP — scan QR / enter secret in an authenticator app.
3. Log out. Log in with password → should redirect to **2FA challenge** (`wp-login.php?action=sp_2fa` or your disguised login URL).
4. Enter valid TOTP code → full login.
5. Enter **wrong** code once → error, no session.
6. Test **recovery code** once if shown at enrolment.

**Pass criteria**

- [ ] Cannot complete login without second factor when 2FA is required.
- [ ] Valid TOTP and recovery code succeed.
- [ ] Audit log records auth events where expected.

### 5.3 Sessions

**Steps**

1. While logged in on staging, open **Account Security**.
2. Confirm at least one active session listed.
3. Use **Log out everywhere else** / revoke (wording may vary) if available.
4. Verify other browsers lose access on next request.

**Pass criteria**

- [ ] Sessions list populates.
- [ ] Revoke reduces active sessions as expected.

### 5.4 Suspicious login (optional)

**Steps**

1. Ensure notifications enabled in config (or accept audit-only behaviour).
2. Log in from a “new” browser or private window (new user-agent / no prior session).
3. Check email (if enabled) or audit log for suspicious-login / new-device style entries.

**Pass criteria**

- [ ] Alert or audit entry when rule threshold met (per your settings).

---

## 6. Security headers

**Steps**

1. Enable **Security headers** on dashboard.
2. **NiyiGuard → Security Headers** — enable **one** header first (e.g. `X-Frame-Options: SAMEORIGIN`), save.
3. Visit the **front-end home page** in a browser → DevTools → **Network** → response headers: confirm header present.
4. Enable **HSTS** only if staging uses HTTPS everywhere (avoid on mixed HTTP staging).
5. Enable **CSP** with a **report-only or loose policy** first; tighten only after checking console violations.

**Pass criteria**

- [ ] Enabled headers appear on front-end responses.
- [ ] Site still loads (no broken assets from overly strict CSP).
- [ ] Master toggle off removes headers.

---

## 7. Global rate limiting

**Steps**

1. **NiyiGuard → Rate limiting** — set a **low** limit for testing (e.g. **10 requests / 60 seconds**), enable module, save.
2. **REST:** run 15 quick requests (browser console, `curl`, or REST client):

   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" "https://YOUR-STAGING-SITE/wp-json/wp/v2/posts?per_page=1"
   ```

   Repeat until some return **429**.

3. Confirm **wp-admin** still loads normally (dashboard should **not** be throttled by default).
4. Disable rate limiting and confirm 429s stop.

**Pass criteria**

- [ ] REST (or front-end, depending on context) returns 429 after threshold.
- [ ] Admin UI remains usable with limit enabled.
- [ ] Disabling module stops throttling.

---

## 8. File integrity

**Steps**

1. Enable **File integrity** on dashboard.
2. **NiyiGuard → File integrity** — run **baseline** / **scan** for **plugins** scope (smallest useful scope).
3. Wait for completion — review findings table (expect zero or known items on clean staging).
4. Optional negative test: add a harmless marker file under a test plugin directory (staging only), rescan — expect **added/changed** finding; remove file and rescan.

**Pass criteria**

- [ ] Scan completes without fatal error.
- [ ] Findings list updates; severities look reasonable.
- [ ] Core checksum scan works if you enable core scope (needs outbound access to `api.wordpress.org`).

---

## 9. URL disguise (high risk — do last)

**Only on staging. Have safe mode ready (section 11).**

**Steps**

1. **NiyiGuard → URL disguise** — choose a unique slug (e.g. `secure-login-staging-xyz`), save.
2. Flush permalinks: **Settings → Permalinks → Save** (no change needed).
3. Log out. Visit `https://YOUR-SITE/secure-login-staging-xyz/` — login form should load.
4. Visit `https://YOUR-SITE/wp-login.php` — should 404 or redirect per settings.
5. Log in via disguised URL successfully.
6. Disable disguise and confirm `wp-login.php` works again.

**Pass criteria**

- [ ] Custom slug login works.
- [ ] You can revert without safe mode.
- [ ] Document the chosen slug for your team.

---

## 10. WooCommerce Protection (Pro / evaluation)

**Requires WooCommerce active and Pro access (evaluation period or license).**

**Steps**

1. Confirm **NiyiGuard → License** shows Pro/evaluation active.
2. Enable **WooCommerce Protection** on dashboard.
3. **NiyiGuard → WooCommerce** — review defaults; leave checkout pipeline on.
4. **Registration abuse:** submit customer registration with disposable-style email if you have a test domain blocked in config — expect block or logged decision.
5. **Cart velocity:** rapid add-to-cart from same session/IP — watch for throttle or audit entries.
6. **Checkout:** place a **normal** test order — should succeed.
7. **Checkout bot signals:** submit checkout with honeypot field filled (browser devtools → set hidden `niyiguard_hp` or configured name) — expect block or high fraud score in logs/audit.
8. Check **Audit log** for WooCommerce-related events.

**Pass criteria**

- [ ] Legitimate checkout still completes.
- [ ] Obvious abuse patterns are blocked or scored.
- [ ] No PHP errors on cart/checkout pages.

---

## 11. Safe mode recovery (mandatory once)

Prove you can regain access if something locks you out.

**Steps**

1. Add to `wp-config.php` **above** “That’s all, stop editing!”:

   ```php
   define( 'NIYIGUARD_SAFE_MODE', true );
   ```

2. Load wp-admin and disguised login — lockout, disguise, and global rate limit should be **bypassed**.
3. Fix the setting that caused the issue (or disable the module).
4. **Remove** the constant (or set `false`), reload, confirm normal protections apply again.

**Alternative:** `recovery.safe_mode => true` in `config/plugin.php` (same effect if wp-config constant not set).

**Pass criteria**

- [ ] Safe mode restores admin/login access.
- [ ] Removing safe mode re-enables protections.

---

## 12. Licensing (optional on staging)

**Steps**

1. **NiyiGuard → License** — note evaluation/trial status.
2. If testing key validation: paste a valid test key format your issuer provides; save.
3. Confirm WooCommerce menu/features match license state.

**Pass criteria**

- [ ] Status message matches key / trial state.
- [ ] No outbound calls to vendor (offline HMAC only).

---

## 13. Developer API smoke test (optional)

For sites using custom routes, verify SDK wiring from [USAGE.md](USAGE.md):

1. Register middleware on a test REST route or `admin_post` action.
2. Submit form **without** nonce → expect CSRF failure.
3. Submit with `Security::csrfField()` → expect success.
4. Generate a signed URL, open before expiry → OK; after expiry → rejected.

**Pass criteria**

- [ ] CSRF and signed URL behaviour match documentation.

---

## 14. Sign-off before production

| Check | Done |
| --- | --- |
| All sections above passed on staging | [ ] |
| MU loader installed (if you want early bootstrap) | [ ] |
| `config/plugin.php` reviewed: `licensing.secret`, trial flags, notification email | [ ] |
| URL disguise slug documented or left disabled | [ ] |
| Rate limit tuned for real traffic (not test values) | [ ] |
| CSP/HSTS reviewed for production HTTPS | [ ] |
| Safe mode recovery tested | [ ] |
| `debug.log` clean during tests | [ ] |
| Plugin Check clean on release zip | [ ] |
| Database backup taken | [ ] |

---

## 15. Quick troubleshooting

| Symptom | Action |
| --- | --- |
| Locked out of login | `define( 'NIYIGUARD_SAFE_MODE', true );` in `wp-config.php` |
| 429 on REST/AJAX | Disable rate limit or raise limit; exclude test IPs if you add custom logic later |
| Headers break site | Disable CSP or loosen policy; disable HSTS on non-HTTPS staging |
| No audit events | Check dashboard toggle + **Audit settings** listeners + `min_storage_level` |
| WooCommerce blocks everyone | Disable WooCommerce Protection; lower velocity/fraud thresholds on staging |
| Tables missing | Deactivate/reactivate plugin; check Health screen; DB user can `CREATE TABLE` |

---

## 16. Automated Cypress checks

A subset of this plan is covered by Cypress specs under `cypress/e2e/staging/`. See [CYPRESS_E2E.md](CYPRESS_E2E.md) for setup (`npm install`, `cypress.env.json`, `npm run test:e2e`).

| Section | Automated | Notes |
| --- | --- | --- |
| §2 Pre-flight | Yes | Dashboard + Health |
| §3 Dashboard | Yes | Feature toggle persistence |
| §4 Audit log | Yes | Settings, prune; plugin events need manual toggle or `runDestructive` |
| §5 Auth | Partial | Settings UI; lockout/2FA/sessions manual or `runDestructive` |
| §6 Headers | Yes | X-Frame-Options on front end |
| §7 Rate limit | Yes | REST 429 burst |
| §8 File integrity | Yes | Scan form submit |
| §9 URL disguise | Optional | `runDestructive: true` only |
| §10 WooCommerce | Optional | `runWooCommerce: true` |
| §11 Safe mode | No | wp-config change |
| §12 License | Yes | Page load + status text |
| §13 Developer API | No | Custom route required |

---

## Related docs

- [CYPRESS_E2E.md](CYPRESS_E2E.md) — staging E2E runner and env flags  
- [USAGE.md](USAGE.md) — developer APIs and configuration reference  
- [MU_LOADER_INSTALL.md](MU_LOADER_INSTALL.md) — early load setup  
- [PRIVACY.md](PRIVACY.md) — data stored and third-party requests  
- [README.md](../README.md) — feature list and implementation status  
