<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Admin\Diagnostics\HealthDiagnosticsCollector;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * PressSentinel → Health: read-only operational snapshot.
 */
final class HealthDiagnosticsPage
{
    public const PAGE_SLUG = 'presssentinel-health';

    public function __construct(
        private readonly HealthDiagnosticsCollector $collector,
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu']);
    }

    public function addMenu(): void
    {
        WpHelper::addSubmenuPage(
            PressSentinelMenuPage::PARENT_SLUG,
            'PressSentinel Health',
            'Health',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $this->view->render('admin.health.diagnostics', [
            'report' => $this->collector->collect(),
            'pageSlug' => self::PAGE_SLUG,
        ]);
    }
}
