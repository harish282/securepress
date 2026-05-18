<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Core\Audit\AuditEventLevel;
use PressSentinel\Core\Audit\AuditLogOptions;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * PressSentinel → Audit log settings: retention, automatic pruning, and minimum DB level.
 */
final class AuditLogSettingsPage
{
    public const PAGE_SLUG = 'presssentinel-audit-settings';

    public const OPTION_GROUP = 'presssentinel_audit_log_group';

    public const SECTION = 'presssentinel_section_audit_log';

    public function __construct(
        private readonly AuditLogOptions $options,
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu']);
        WpHelper::addAction('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        WpHelper::addSubmenuPage(
            PressSentinelMenuPage::PARENT_SLUG,
            'PressSentinel Audit Log Settings',
            'Audit log settings',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        WpHelper::registerSetting(
            self::OPTION_GROUP,
            AuditLogOptions::OPTION_NAME,
            ['sanitize_callback' => [$this->options, 'sanitize']]
        );

        WpHelper::addSettingsSection(
            self::SECTION,
            'Database retention & levels',
            [$this, 'renderIntro'],
            self::PAGE_SLUG
        );

        WpHelper::addSettingsField('al_retention', 'Retention (days)', [$this, 'renderRetention'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('al_auto_prune', 'Automatic pruning', [$this, 'renderAutoPrune'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('al_min_level', 'Minimum level stored in database', [$this, 'renderMinLevel'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('al_mirror', 'Mirror to file log', [$this, 'renderMirror'], self::PAGE_SLUG, self::SECTION);
    }

    public function renderPage(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $this->view->render('admin.settings.audit-log', [
            'pageSlug' => self::PAGE_SLUG,
            'optionGroup' => self::OPTION_GROUP,
            'masterEnabled' => $this->options->isEnabled(),
            'retentionDays' => $this->options->retentionDays(),
            'autoPruneEnabled' => $this->options->isAutoPruneEnabled(),
            'minStorageLevel' => $this->options->minStorageLevel(),
            'logsPageSlug' => AuditLogPage::PAGE_SLUG,
        ]);
    }

    public function renderIntro(): void
    {
        echo '<p>Control how many audit events are kept in the database and which severities are stored. '
            . 'Use a higher minimum level and shorter retention before high-traffic deployments to limit table growth.</p>';
    }

    public function renderRetention(): void
    {
        $name = AuditLogOptions::OPTION_NAME . '[retention_days]';
        $value = $this->options->retentionDays();
        printf(
            '<input type="number" class="small-text" name="%1$s" value="%2$d" min="%3$d" max="%4$d" step="1">'
            . '<p class="description">Entries older than this are removed when pruning runs. Use <strong>0</strong> to keep all rows (disables age-based deletion). Maximum %4$d days.</p>',
            WpHelper::escapeAttribute($name),
            $value,
            AuditLogOptions::RETENTION_MIN,
            AuditLogOptions::RETENTION_MAX
        );
    }

    public function renderAutoPrune(): void
    {
        $checked = $this->options->isAutoPruneEnabled() ? ' checked' : '';
        $name = AuditLogOptions::OPTION_NAME . '[auto_prune_enabled]';
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> Run the daily prune cron</label>'
            . '<p class="description">Requires retention &gt; 0. You can still prune manually from the <a href="%3$s">audit log viewer</a>.</p>',
            WpHelper::escapeAttribute($name),
            $checked,
            WpHelper::escapeAttribute(
                \function_exists('admin_url')
                    ? (string) \call_user_func('admin_url', 'admin.php?page=' . AuditLogPage::PAGE_SLUG)
                    : '#'
            )
        );
    }

    public function renderMinLevel(): void
    {
        $name = AuditLogOptions::OPTION_NAME . '[min_storage_level]';
        $current = $this->options->minStorageLevel();
        echo '<select name="' . WpHelper::escapeAttribute($name) . '">';
        foreach (AuditEventLevel::all() as $level) {
            $selected = $level === $current ? ' selected' : '';
            printf(
                '<option value="%1$s"%2$s>%1$s</option>',
                WpHelper::escapeAttribute($level),
                $selected
            );
        }
        echo '</select>';
        echo '<p class="description">Events below this severity are not written to <code>wp_presssentinel_audit_logs</code> '
            . '(recommended: <code>notice</code> or higher for production). They can still be mirrored to the file log below.</p>';
    }

    public function renderMirror(): void
    {
        $checked = $this->options->mirrorToFileLogger() ? ' checked' : '';
        $name = AuditLogOptions::OPTION_NAME . '[mirror_to_file_logger]';
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> Also write stored (and sub-threshold) events to <code>storage/logs/presssentinel.log</code></label>',
            WpHelper::escapeAttribute($name),
            $checked
        );
    }
}
