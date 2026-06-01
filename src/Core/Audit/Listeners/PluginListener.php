<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit\Listeners;

use NiyiGuard\Core\Audit\AuditEvent;
use NiyiGuard\Core\Audit\AuditEventCategory;
use NiyiGuard\Core\Audit\AuditEventLevel;
use NiyiGuard\Core\Audit\AuditLoggerInterface;
use NiyiGuard\Core\Support\WpHelper;

/**
 * Tracks lifecycle changes to WordPress plugins and themes.
 *
 * Plugin changes are high-impact events — installing or activating a malicious plugin is
 * one of the most common WP compromise vectors. We record them at `warning` level so they
 * stand out in level-based filters / SIEM exports without being alerts on every benign
 * activation.
 *
 * The `upgrader_process_complete` hook fires for installs / updates of both plugins and
 * themes; we branch on `$hookExtra['type']` to label the event correctly.
 */
final class PluginListener implements ListenerInterface
{
    public function __construct(private readonly AuditLoggerInterface $logger)
    {
    }

    public function register(): void
    {
        WpHelper::addAction('activated_plugin', [$this, 'onActivated'], 10, 2);
        WpHelper::addAction('deactivated_plugin', [$this, 'onDeactivated'], 10, 2);
        WpHelper::addAction('deleted_plugin', [$this, 'onDeleted'], 10, 2);
        WpHelper::addAction('upgrader_process_complete', [$this, 'onUpgradeComplete'], 10, 2);
        WpHelper::addAction('switch_theme', [$this, 'onThemeSwitched'], 10, 3);
    }

    public function onActivated(string $plugin, bool $networkWide = false): void
    {
        $event = AuditEvent::make('plugin.activated', AuditEventCategory::PLUGIN, AuditEventLevel::WARNING)
            ->withTarget('plugin', $plugin)
            ->withMessage(sprintf('Plugin activated: %s', $plugin))
            ->withContext(['network_wide' => (bool) $networkWide]);

        $this->logger->record($event);
    }

    public function onDeactivated(string $plugin, bool $networkWide = false): void
    {
        $event = AuditEvent::make('plugin.deactivated', AuditEventCategory::PLUGIN, AuditEventLevel::NOTICE)
            ->withTarget('plugin', $plugin)
            ->withMessage(sprintf('Plugin deactivated: %s', $plugin))
            ->withContext(['network_wide' => (bool) $networkWide]);

        $this->logger->record($event);
    }

    public function onDeleted(string $plugin, bool $deleted = true): void
    {
        if (!$deleted) {
            return;
        }

        $event = AuditEvent::make('plugin.deleted', AuditEventCategory::PLUGIN, AuditEventLevel::WARNING)
            ->withTarget('plugin', $plugin)
            ->withMessage(sprintf('Plugin deleted: %s', $plugin));

        $this->logger->record($event);
    }

    /**
     * @param array<string, mixed> $hookExtra
     */
    public function onUpgradeComplete(mixed $upgrader, array $hookExtra = []): void
    {
        $type = isset($hookExtra['type']) && is_string($hookExtra['type']) ? $hookExtra['type'] : 'unknown';
        $action = isset($hookExtra['action']) && is_string($hookExtra['action']) ? $hookExtra['action'] : 'update';

        if (!in_array($type, ['plugin', 'theme', 'core'], true)) {
            return;
        }

        $items = isset($hookExtra[$type . 's']) && is_array($hookExtra[$type . 's']) ? $hookExtra[$type . 's'] : [];
        if ($items === []) {
            $items = ['(none)'];
        }

        $category = $type === 'theme' ? AuditEventCategory::THEME : AuditEventCategory::PLUGIN;
        $eventName = sprintf('%s.%s', $type, $action === 'install' ? 'installed' : 'updated');

        foreach ($items as $slug) {
            $slug = (string) $slug;
            $event = AuditEvent::make($eventName, $category, AuditEventLevel::WARNING)
                ->withTarget($type, $slug)
                ->withMessage(sprintf('%s %s: %s', ucfirst($type), $action === 'install' ? 'installed' : 'updated', $slug))
                ->withContext(['action' => $action, 'type' => $type]);

            $this->logger->record($event);
        }
    }

    public function onThemeSwitched(string $newTheme, mixed $newThemeObj = null, mixed $oldTheme = null): void
    {
        $oldName = is_object($oldTheme) && method_exists($oldTheme, 'get')
            ? (string) $oldTheme->get('Name')
            : 'unknown';

        $event = AuditEvent::make('theme.switched', AuditEventCategory::THEME, AuditEventLevel::WARNING)
            ->withTarget('theme', $newTheme)
            ->withMessage(sprintf('Theme switched from "%s" to "%s".', $oldName, $newTheme))
            ->withContext(['old_theme' => $oldName, 'new_theme' => $newTheme]);

        $this->logger->record($event);
    }
}
