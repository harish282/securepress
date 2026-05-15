<?php

declare(strict_types=1);

namespace SecurePress\Admin;

use SecurePress\Admin\Diagnostics\HealthDiagnosticsCollector;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\View\View;

/**
 * SecurePress → Health: read-only operational snapshot.
 */
final class HealthDiagnosticsPage
{
    public const PAGE_SLUG = 'securepress-health';

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
            SecurePressMenuPage::PARENT_SLUG,
            'SecurePress Health',
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
