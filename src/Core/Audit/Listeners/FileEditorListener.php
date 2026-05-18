<?php

declare(strict_types=1);

namespace PressSentinel\Core\Audit\Listeners;

use PressSentinel\Core\Audit\AuditEvent;
use PressSentinel\Core\Audit\AuditEventCategory;
use PressSentinel\Core\Audit\AuditEventLevel;
use PressSentinel\Core\Audit\AuditLoggerInterface;
use PressSentinel\Core\Support\WpHelper;

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
        $file = isset($_REQUEST['file']) && is_string($_REQUEST['file']) ? $_REQUEST['file'] : null;

        $event = AuditEvent::make('file_editor.viewed', AuditEventCategory::FILE_EDITOR, AuditEventLevel::NOTICE)
            ->withTarget($type, $file ?? '(index)')
            ->withMessage(sprintf('Built-in %s editor was opened.', $type))
            ->withContext(['type' => $type, 'file' => $file]);

        $this->logger->record($event);
    }

    public function onAdminInit(): void
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper($_SERVER['REQUEST_METHOD'])
            : 'GET';
        if ($method !== 'POST') {
            return;
        }

        $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
            ? basename($_SERVER['SCRIPT_NAME'])
            : '';

        $type = match ($script) {
            'theme-editor.php' => 'theme',
            'plugin-editor.php' => 'plugin',
            default => null,
        };
        if ($type === null) {
            return;
        }

        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        if ($action !== 'update') {
            return;
        }

        $nonceAction = $type === 'theme' ? 'edit-theme_' : 'edit-plugin_';
        $file = isset($_POST['file']) && is_string($_POST['file']) ? $_POST['file'] : '';
        if ($file === '' || WpHelper::verifyNonce((string) ($_POST['_wpnonce'] ?? ''), $nonceAction . $file) <= 0) {
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
