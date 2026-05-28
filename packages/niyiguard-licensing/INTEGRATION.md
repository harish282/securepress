# NiyiGuard offline licensing (optional add-on)

This folder is a **standalone copy** of the offline HMAC license system that used to ship inside NiyiGuard. The public plugin is now **free and fully unlocked**; keep this package if you want to sell Pro keys on a private build or reuse licensing in another WordPress plugin.

## What is included

| Path | Purpose |
| --- | --- |
| `src/Core/Licensing/*` | `LicenseManager`, validators, `BetaTrial`, HMAC secret provisioner |
| `src/Admin/LicensePage.php` | wp-admin **License** screen |
| `tests/Unit/Licensing/*` | PHPUnit coverage for validators and manager |
| `config/plugin.licensing.sample.php` | Config keys to merge into your host plugin |

## Build the archive

From the **repository root**:

```bash
bash packages/niyiguard-licensing/scripts/build-licensing-zip.sh
```

Output: `build/niyiguard-licensing-1.0.0.zip`

## Integrate into another plugin (checklist)

### 1. Copy code

Copy into your plugin (adjust namespace if you fork):

- `src/Core/Licensing/` → your `src/Core/Licensing/`
- `src/Admin/LicensePage.php` → your `src/Admin/LicensePage.php`

Ensure your autoloader maps `YourVendor\Core\Licensing\` (or keep `NiyiGuard\` and require this tree).

### 2. Config

Merge the sample from `config/plugin.licensing.sample.php` into your `config/plugin.php`:

- `pro_license` — optional default key, `early_access`, optional `beta_trial`
- `licensing.secret` — fallback HMAC secret (prefer auto-generated DB option)

### 3. Activation

On plugin activation, provision the signing secret:

```php
\NiyiGuard\Core\Licensing\LicenseHmacSecretProvisioner::ensure();
```

### 4. Register services (DI)

Bind at bootstrap (same pattern as NiyiGuard 0.1.0):

```php
$container->singleton(
    LicenseValidatorInterface::class,
    fn ($c) => new LocalLicenseValidator(
        (string) $c->get(Config::class)->get('licensing.secret', 'change-me-in-production')
    )
);
$container->singleton(
    LicenseManager::class,
    fn ($c) => new LicenseManager(
        $c->get(LicenseValidatorInterface::class),
        $c->get(Config::class)
    )
);
$container->singleton(
    LicensePage::class,
    fn ($c) => new LicensePage($c->get(LicenseManager::class))
);
```

Register `LicensePage::register()` on `init` (priority 1), after your admin menu parent exists.

### 5. Gate features

Anywhere you need Pro:

```php
if (!$licenseManager->isPro()) {
    return;
}
```

Or expose `Security::isPro()` / filter `niyiguard.is_pro`.

### 6. Generate license keys (CLI sketch)

Keys look like: `SP-pro-<issued>-<expires>-<hmac>` (see `LocalLicenseValidator`).

Sign with the same secret as the site (`niyiguard_license_hmac_secret` option or `NIYIGUARD_LICENSE_SECRET` in `wp-config.php`). Use a small PHP script with `hash_hmac('sha256', $payload, $secret)` — match the validator’s payload format exactly.

### 7. WordPress options / privacy

Document in your privacy policy:

- `niyiguard_pro_license` — stored license key (if you keep the same option names)
- `niyiguard_license_hmac_secret` — install signing secret
- `niyiguard_beta_trial_started_at` — only if beta trial is enabled

## NiyiGuard free edition

The main plugin uses `NiyiGuard\Core\Edition\FreeEditionAccess` instead of `LicenseManager`. To re-enable licensing in a **private** NiyiGuard build, replace the `EditionAccess` binding in `Plugin::registerEditionServices()` with an adapter that delegates to `LicenseManager`.
