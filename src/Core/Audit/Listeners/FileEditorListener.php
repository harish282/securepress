<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit\Listeners;

// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- Core file-editor requests; save path verifies editor nonce.
use NiyiGuard\Core\Audit\AuditEvent;
use NiyiGuard\Core\Audit\AuditEventCategory;
use NiyiGuard\Core\Audit\AuditEventLevel;
use NiyiGuard\Core\Audit\AuditLoggerInterface;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Detects access to the WordPress file editors (`theme-editor.php`, `plugin-editor.php`).
 *
 * The file editors are one of the most dangerous pages in WP admin — anyone with
 * `edit_themes` / `edit_plugins` can rewrite live PHP. There is no first-party hook for
 * "file was modified", so we use two heuristics:
 *
 *  1. **Visit detection.** On `current_screen` if the screen ID is one of the editor
 *     screens we record `file_editor.viewed` at `notice` (somebody opened the page).
 *
 *  2. **Save detection.** On `admin_init`, if the request is a POST to `theme-editor.php`
 *     / `plugin-editor.php` with `action=update`, we record `file_editor.modified` at
 *     `critical` — the file content has been changed.
 *
 * False-positive risk for #1 is low (the only way to land on the editor screen is to be
 * an admin and click the link). For #2 we additionally verify the WP nonce field that the
 * editor pages emit, so background scrapers that POST without a valid nonce don't pollute
 * the log.
 */
final class FileEditorListener implements ListenerInterface
{
    private const SCREENS = [
        'theme-editor' => 'theme',
        'plugin-editor' => 'plugin',
    ];

    public function __construct(private readonly AuditLoggerInterface $logger)
    {
    }

    public function register(): void
    {
        WpHelper::addAction('current_screen', [$this, 'onCurrentScreen'], 10, 1);
        WpHelper::addAction('admin_init', [$this, 'onAdminInit'], 1);
    }

    public function onCurrentScreen(mixed $screen = null): void
    {
        $screenId = is_object($screen) && isset($screen->id) && is_string($screen->id) ? $screen->id : '';
        if (!isset(self::SCREENS[$screenId])) {
            return;
        }

        $type = self::SCREENS[$screenId];
        $fileRaw = WpHelper::getRequestString('file', '');
        $file = $fileRaw !== '' ? $fileRaw : null;

        $event = AuditEvent::make('file_editor.viewed', AuditEventCategory::FILE_EDITOR, AuditEventLevel::NOTICE)
            ->withTarget($type, $file ?? '(index)')
            ->withMessage(sprintf('Built-in %s editor was opened.', $type))
            ->withContext(['type' => $type, 'file' => $file]);

        $this->logger->record($event);
    }

    public function onAdminInit(): void
    {
        if (WpHelper::getRequestMethod() !== 'POST') {
            return;
        }

        $scriptName = WpHelper::getServerString('SCRIPT_NAME');
        $script = $scriptName !== null ? basename($scriptName) : '';

        $type = match ($script) {
            'theme-editor.php' => 'theme',
            'plugin-editor.php' => 'plugin',
            default => null,
        };
        if ($type === null) {
            return;
        }

        if (WpHelper::getPostString('action') !== 'update') {
            return;
        }

        $nonceAction = $type === 'theme' ? 'edit-theme_' : 'edit-plugin_';
        $file = WpHelper::getPostString('file');
        if ($file === '' || WpHelper::verifyNonce(WpHelper::getPostString('_wpnonce'), $nonceAction . $file) <= 0) {
            return;
        }

        $event = AuditEvent::make('file_editor.modified', AuditEventCategory::FILE_EDITOR, AuditEventLevel::CRITICAL)
            ->withTarget($type, $file)
            ->withMessage(sprintf('A %s file was modified via the built-in editor: %s', $type, $file))
            ->withContext([
                'type' => $type,
                'file' => $file,
            ]);

        $this->logger->record($event);
    }
}
