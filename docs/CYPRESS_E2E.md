# Cypress E2E (staging)

Automated checks that mirror [STAGING_TEST_PLAN.md](STAGING_TEST_PLAN.md). Run against a **staging** WordPress site with NiyiGuard activated and an administrator account.

## Setup

```bash
cd wp-content/plugins-dev/niyiguard   # this repo
npm install
cp cypress.env.example.json cypress.env.json
# Edit cypress.env.json: baseUrl, wpUsername, wpPassword
```

Or export env vars:

```bash
export CYPRESS_BASE_URL=https://staging.example
export CYPRESS_wpUsername=admin
export CYPRESS_wpPassword='your-password'
```

## Run

```bash
npm run test:e2e          # headless, all staging specs
npm run cypress:open      # interactive runner
npm run cypress:run       # all specs under cypress/e2e/
```

## Spec map

| Staging plan section | Cypress spec |
| --- | --- |
| §2 Pre-flight | `cypress/e2e/staging/00-preflight.cy.js` |
| §3 Dashboard | `cypress/e2e/staging/01-dashboard.cy.js` |
| §4 Audit log | `cypress/e2e/staging/02-audit-log.cy.js` |
| §5 Authentication | `cypress/e2e/staging/03-authentication.cy.js` |
| §6 Security headers | `cypress/e2e/staging/04-security-headers.cy.js` |
| §7 Rate limiting | `cypress/e2e/staging/05-rate-limit.cy.js` |
| §8 File integrity | `cypress/e2e/staging/06-file-integrity.cy.js` |
| §9 URL disguise | `cypress/e2e/staging/07-url-disguise.cy.js` |
| §10 WooCommerce | `cypress/e2e/staging/08-woocommerce.cy.js` |
| §12 License | `cypress/e2e/staging/09-license.cy.js` |

## Manual / optional flags

| Env (cypress.env.json) | Purpose |
| --- | --- |
| `runDestructive: true` | §5 lockout smoke, §9 URL disguise (can lock you out — use safe mode) |
| `runWooCommerce: true` | §10 when WooCommerce + Pro are on staging |
| `urlDisguiseSlug` | Custom login path for §9 |
| `lockoutTestUser` | Username for §5.1 lockout (default `staging_lockout_test`) |

Still **manual** on staging (no Cypress spec yet):

- §5.2–5.4 TOTP, sessions, suspicious login
- §11 Safe mode (`NIYIGUARD_SAFE_MODE` in wp-config)
- §13 Developer API smoke
- §14 Sign-off checklist
- PHPUnit / Plugin Check (run locally: `vendor/bin/phpunit`)

## Staging recommendations

Match [STAGING_TEST_PLAN.md](STAGING_TEST_PLAN.md) pre-flight: keep URL disguise off until you set `runDestructive`, use conservative rate limits in production, and restore toggles after `05-rate-limit` and `04-security-headers` (specs attempt cleanup in `after` hooks).
