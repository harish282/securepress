# SecurePress MU Loader Installation Guide

The MU loader makes SecurePress load early in WordPress so incoming requests can be monitored sooner.

## Why this is needed

- Standard plugins do not guarantee first load order.
- Must-use plugins (`wp-content/mu-plugins`) are loaded before normal plugins.
- SecurePress can apply security checks earlier when loaded as MU.

## Manual installation (recommended first step)

1. Ensure the `mu-plugins` directory exists:

   - Path: `wp-content/mu-plugins`
   - Create it if missing.

2. Copy the loader file:

   - Source:
     `wp-content/plugins/secure-press/mu-loader/00-securepress-loader.php`
   - Destination:
     `wp-content/mu-plugins/00-securepress-loader.php`

3. Confirm plugin is active:

   - Keep `SecurePress` active in standard plugins list.
   - The MU loader only bootstraps the main plugin earlier.

4. Verify:

   - In WordPress admin, go to **Plugins -> Installed Plugins**.
   - SecurePress row should show **MU Loader: Installed**.

## Troubleshooting

- If status still shows missing:
  - Confirm destination filename is exactly `00-securepress-loader.php`.
  - Confirm `secure-press` is the plugin folder name.
  - Confirm destination file is readable by PHP.

- If site has a custom plugins directory layout:
  - Update loader path inside `00-securepress-loader.php` to your actual `securepress.php` path.

## Notes

- MU loader only affects load timing.
- It does not replace the main SecurePress plugin files.
