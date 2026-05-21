<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use RuntimeException;
use PressSentinel\Core\Support\WpHelper;
use ZipArchive;

/**
 * `admin-post.php` handler that lets administrators download the MU loader
 * as a zip from the PressSentinel dashboard.
 *
 * Why a controller and not just a plain file link? Two reasons:
 *
 *  1. **Capability + nonce gating.** Serving from a URL inside `wp-admin/`
 *     gives us the standard cap check + nonce check that other admin
 *     actions already use, so we don't accidentally expose plugin internals
 *     over an unauthenticated GET.
 *  2. **Bundling.** The download includes both the loader file AND a short
 *     INSTALL.txt explaining where to drop it. That makes the zip
 *     self-documenting — an admin can hand it to their host's support team
 *     without also forwarding our docs URL.
 *
 * If `ZipArchive` isn't available (rare, but some lean shared-hosting PHP
 * builds skip it), the controller transparently falls back to streaming the
 * raw `.php` file as an attachment. The admin still gets the file; they
 * just need to copy it themselves rather than extracting from a zip.
 */
final class MuLoaderDownloadController
{
    /**
     * The `action` value that drives the `admin_post_{ACTION}` hook *and*
     * acts as the nonce action. Public so the dashboard form can render the
     * same string in both `<input name="action">` and `wp_nonce_field()`.
     */
    public const ACTION = 'presssentinel_mu_loader_download';

    public function __construct(private readonly MuLoaderStatus $status)
    {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * Entry point invoked by WordPress on POST to admin-post.php.
     *
     * In production this method emits headers + bytes and `exit`s. In tests
     * (where `PRESS_SENTINEL_TESTING` is defined) it returns early so the test
     * process keeps running and can assert what would have been sent.
     */
    public function handle(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            \call_user_func('wp_die', 'Insufficient permissions.', '', ['response' => 403]);

            return;
        }
        if (!WpHelper::verifyAdminNonce(self::ACTION)) {
            \call_user_func('wp_die', 'Security check failed. Please reload and retry.', '', ['response' => 403]);

            return;
        }

        $payload = $this->buildPayload();

        // In tests we skip header emission + exit so the assertions can run.
        if (\defined('PRESS_SENTINEL_TESTING')) {
            return;
        }

        if (!\headers_sent()) {
            \header('Content-Type: ' . $payload['contentType']); // @codeCoverageIgnore
            \header('Content-Disposition: attachment; filename="' . $payload['filename'] . '"'); // @codeCoverageIgnore
            \header('Content-Length: ' . \strlen($payload['body'])); // @codeCoverageIgnore
            \header('X-Content-Type-Options: nosniff'); // @codeCoverageIgnore
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary zip attachment payload.
        echo $payload['body']; // @codeCoverageIgnore

        exit; // @codeCoverageIgnore
    }

    /**
     * Builds the response body and headers without touching globals, so it
     * can be exercised directly in tests.
     *
     * @return array{filename: string, contentType: string, body: string, format: 'zip'|'raw'}
     */
    public function buildPayload(): array
    {
        $loaderSource = $this->status->templatePath();
        if ($loaderSource === '' || !is_readable($loaderSource)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Admin download diagnostic.
            throw new RuntimeException('PressSentinel MU loader template is not readable: ' . $loaderSource);
        }

        $loaderContents = (string) file_get_contents($loaderSource);
        $loaderFilename = $this->status->loaderFilename();

        if (class_exists(ZipArchive::class)) {
            return [
                'filename' => 'presssentinel-mu-loader.zip',
                'contentType' => 'application/zip',
                'body' => $this->buildZip($loaderFilename, $loaderContents),
                'format' => 'zip',
            ];
        }

        // Graceful fallback. The admin still gets the file they need; they
        // just need to drop it into wp-content/mu-plugins/ themselves
        // without an intermediate "unzip" step.
        return [
            'filename' => $loaderFilename,
            'contentType' => 'application/octet-stream',
            'body' => $loaderContents,
            'format' => 'raw',
        ];
    }

    /**
     * Assembles a small zip in memory containing the loader plus a plaintext
     * install note. We use `tempnam` rather than streaming to a `php://temp`
     * because `ZipArchive::open()` needs a real filesystem path on most PHP
     * builds.
     */
    private function buildZip(string $loaderFilename, string $loaderContents): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'sp-mu-');
        if (!is_string($tmp)) {
            throw new RuntimeException('Failed to allocate a temp file for the MU loader zip.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Failed to open ZipArchive for writing.');
            }

            $zip->addFromString($loaderFilename, $loaderContents);
            $zip->addFromString('INSTALL.txt', $this->installNotes());
            $zip->close();

            $bytes = (string) file_get_contents($tmp);

            return $bytes;
        } finally {
            if (is_file($tmp)) {
                if (\function_exists('wp_delete_file')) {
                    \call_user_func('wp_delete_file', $tmp);
                } else {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Temp zip outside uploads; wp_delete_file unavailable.
                    @\unlink($tmp);
                }
            }
        }
    }

    /**
     * Plain-text install steps bundled inside the zip alongside the loader.
     * Kept terse and absolute-path-free so it works regardless of where the
     * admin's mu-plugins folder lives.
     */
    private function installNotes(): string
    {
        $loaderFilename = $this->statusFilenameForReadme();

        return sprintf(
            "PressSentinel MU Loader\n"
            . "=====================\n\n"
            . "Why this file exists\n"
            . "--------------------\n"
            . "Standard WordPress plugins do not guarantee load order. Must-Use plugins\n"
            . "(in wp-content/mu-plugins/) are loaded BEFORE every regular plugin, so\n"
            . "PressSentinel can intercept requests earlier when bootstrapped via this\n"
            . "loader. That's the difference between checking a malicious request\n"
            . "before any theme/plugin code has run and checking it after.\n\n"
            . "How to install\n"
            . "--------------\n"
            . "1. Locate your wp-content/mu-plugins/ folder.\n"
            . "   If it does not exist yet, create it: wp-content/mu-plugins/\n\n"
            . "2. Copy the loader file from this zip into that folder:\n"
            . "   wp-content/mu-plugins/%s\n\n"
            . "3. Keep PressSentinel active in your normal plugin list. The MU loader\n"
            . "   only changes WHEN it boots; it does not replace the main plugin.\n\n"
            . "4. Verify:\n"
            . "   - Visit any wp-admin page.\n"
            . "   - Go to PressSentinel -> Dashboard.\n"
            . "   - The \"MU loader not installed\" callout should disappear.\n\n"
            . "Troubleshooting\n"
            . "---------------\n"
            . "- Filename must be exactly: %s\n"
            . "- File must be readable by the PHP user (typically www-data / nobody).\n"
            . "- If your plugins directory has a custom location, edit the loader\n"
            . "  file's `\$pressSentinelBootstrap = ...` line accordingly.\n",
            $loaderFilename,
            $loaderFilename
        );
    }

    /**
     * Tiny shim so the heredoc above stays a single string — calling a
     * method inside `{}` works, accessing `$this->status->...` directly
     * inside heredoc does not.
     */
    private function statusFilenameForReadme(): string
    {
        return $this->status->loaderFilename();
    }
}
