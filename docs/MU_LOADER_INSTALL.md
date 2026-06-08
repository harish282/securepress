# NiyiGuard MU Loader Installation Guide

The MU loader makes NiyiGuard load early in WordPress so incoming requests can be monitored sooner.

## Why this is needed

- Standard plugins do not guarantee first load order.
- Must-use plugins (`wp-content/mu-plugins`) are loaded before normal plugins.
- NiyiGuard can apply security checks earlier when loaded as MU.

## Manual installation (recommended first step)

1. Ensure the `mu-plugins` directory exists:

   - Path: `wp-content/mu-plugins`
   - Create it if missing.

2. Copy the loader file:

   - Source:
     `wp-content/plugins/niyiguard/mu-loader/00-niyiguard-loader.php`
   - Destination:
     `wp-content/mu-plugins/00-niyiguard-loader.php`

3. Confirm plugin is active:

   - Keep `NiyiGuard` active in standard plugins list.
   - The MU loader only bootstraps the main plugin earlier.
   - If you deactivate NiyiGuard in **Plugins**, the MU loader will not boot it (rate limiting, headers, and other protections stop). Remove the MU loader file only if you no longer want early-load support on re-activation.

4. Verify:

   - In WordPress admin, go to **Plugins -> Installed Plugins**.
   - NiyiGuard row should show **MU Loader: Installed**.

## Troubleshooting

- If status still shows missing:
  - Confirm destination filename is exactly `00-niyiguard-loader.php`.
  - Confirm `niyiguard` is the plugin folder name.
  - Confirm destination file is readable by PHP.

- If site has a custom plugins directory layout:
  - Update loader path inside `00-niyiguard-loader.php` to your actual `niyiguard.php` path.

## Notes

- MU loader only affects load timing.
- It does not replace the main NiyiGuard plugin files.
