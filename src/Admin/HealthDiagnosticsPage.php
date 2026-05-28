<?php

declare(strict_types=1);

namespace NiyiGuard\Admin;

use NiyiGuard\Admin\Diagnostics\HealthDiagnosticsCollector;
use NiyiGuard\Core\Support\WpHelper;
use NiyiGuard\Core\View\View;

/**
 * NiyiGuard → Health: read-only operational snapshot.
 */
final class HealthDiagnosticsPage
{
    public const PAGE_SLUG = 'niyiguard-health';

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
            NiyiGuardMenuPage::PARENT_SLUG,
            'NiyiGuard Health',
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
