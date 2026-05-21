<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

/**
 * Single source of truth for "is the PressSentinel MU loader installed?".
 *
 * The MU (Must-Use) loader is a tiny shim under `wp-content/mu-plugins/` that
 * requires PressSentinel's main bootstrap. WordPress loads mu-plugins before
 * regular plugins, so when the shim is present PressSentinel can intercept
 * requests earlier in the lifecycle — useful for catching attacks that
 * target plugins-loaded-but-init-not-yet timing windows.
 *
 * Two admin surfaces consume this status: the dashboard's prominent
 * "loader missing" callout, and the inline notice on the wp-admin Plugins
 * screen. Centralising the path resolution here means we never have two
 * implementations drifting out of sync.
 *
 * Paths are resolved from constants set in `bootstrap/constants.php`, with
 * constructor overrides so unit tests can point at fixture directories
 * without touching globals.
 */
final class MuLoaderStatus
{
    private readonly string $templatePath;

    private readonly string $expectedDirectory;

    private readonly string $loaderFilename;

    public function __construct(
        ?string $templatePath = null,
        ?string $muPluginsDir = null,
        ?string $loaderFilename = null,
    ) {
        $this->loaderFilename = $loaderFilename ?? (\defined('PRESS_SENTINEL_MU_LOADER_FILENAME')
            ? (string) \constant('PRESS_SENTINEL_MU_LOADER_FILENAME')
            : '00-press-sentinel-loader.php');

        $this->templatePath = $templatePath ?? (\defined('PRESS_SENTINEL_MU_LOADER_TEMPLATE_PATH')
            ? (string) \constant('PRESS_SENTINEL_MU_LOADER_TEMPLATE_PATH')
            : '');

        $this->expectedDirectory = rtrim(
            $muPluginsDir
                ?? (\defined('WPMU_PLUGIN_DIR')
                    ? (string) \constant('WPMU_PLUGIN_DIR')
                    : (\defined('PRESS_SENTINEL_PATH')
                        ? \dirname((string) \constant('PRESS_SENTINEL_PATH')) . '/mu-plugins'
                        : '')),
            '/'
        );
    }

    /**
     * True when the loader file is readable at its expected mu-plugins path.
     * `is_readable` rather than `file_exists` so a directory with no PHP
     * read perms reports the same status the WP runtime would actually see.
     */
    public function isInstalled(): bool
    {
        $path = $this->expectedPath();
        if ($path === '') {
            return false;
        }

        return is_readable($path);
    }

    /**
     * Absolute path to the canonical loader file shipped inside this plugin.
     * Used as the source for both manual copy-paste instructions and the
     * download-zip controller.
     */
    public function templatePath(): string
    {
        return $this->templatePath;
    }

    /**
     * Absolute path where the loader file is *expected* to live for it to be
     * picked up by WordPress.
     */
    public function expectedPath(): string
    {
        if ($this->expectedDirectory === '') {
            return '';
        }

        return $this->expectedDirectory . '/' . $this->loaderFilename;
    }

    public function expectedDirectory(): string
    {
        return $this->expectedDirectory;
    }

    public function loaderFilename(): string
    {
        return $this->loaderFilename;
    }
}
