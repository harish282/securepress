<?php

declare(strict_types=1);

namespace NiyiGuard\Admin;

use NiyiGuard\Core\Integrity\FindingRepositoryInterface;
use NiyiGuard\Core\Integrity\FindingSeverity;
use NiyiGuard\Core\Integrity\FindingType;
use NiyiGuard\Core\Integrity\IntegrityScheduler;
use NiyiGuard\Core\Integrity\IntegrityService;
use NiyiGuard\Core\Integrity\ManifestRepositoryInterface;
use NiyiGuard\Core\Support\WpHelper;
use NiyiGuard\Core\View\View;

/**
 * Admin "Tools → File Integrity" page.
 *
 * The controller is a thin POST/Redirect/GET wrapper:
 *  - GET renders the list of open findings (filtered/grouped) and the scanner summary.
 *  - POST handlers ("re-scan now", "mark reviewed", "delete finding", "clear all
 *    findings", "reset baseline") are admin-post actions, each guarded by:
 *    1. `manage_options` capability;
 *    2. WordPress nonce via {@see WpHelper::verifyAdminNonce()};
 *    3. POST-only redispatch back to GET so refreshes don't replay the action.
 *
 * Why a Tools-menu page (not Settings): findings are operational data, not config — the
 * page surfaces "what changed?" answers, which fits Tools far better than Settings. The
 * `Settings → File Integrity` companion page lives separately for the toggle config.
 */
final class FileIntegrityPage
{
    public const PAGE_SLUG = 'niyiguard-file-integrity';
    public const NONCE_ACTION = 'niyiguard_file_integrity';
    public const STATUS_QUERY_KEY = 'niyiguard_status';

    public function __construct(
        private readonly FindingRepositoryInterface $findings,
        private readonly IntegrityScheduler $scheduler,
        private readonly IntegrityService $service,
        private readonly ManifestRepositoryInterface $manifests,
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu']);
        WpHelper::addAction('admin_post_niyiguard_integrity_rescan', [$this, 'handleRescan']);
        WpHelper::addAction('admin_post_niyiguard_integrity_review', [$this, 'handleReview']);
        WpHelper::addAction('admin_post_niyiguard_integrity_delete', [$this, 'handleDelete']);
        WpHelper::addAction('admin_post_niyiguard_integrity_clear', [$this, 'handleClear']);
        WpHelper::addAction('admin_post_niyiguard_integrity_reset_baseline', [$this, 'handleResetBaseline']);
    }

    public function addMenu(): void
    {
        WpHelper::addSubmenuPage(
            NiyiGuardMenuPage::PARENT_SLUG,
            'NiyiGuard File Integrity',
            'File Integrity',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $findings = $this->findings->all();
        $open = array_filter($findings, static fn ($f): bool => !$f->isReviewed());

        $this->view->render('admin.integrity.scan', [
            'findings' => $findings,
            'open' => array_values($open),
            'openCount' => count($open),
            'allCount' => count($findings),
            'severities' => FindingSeverity::all(),
            'pageSlug' => self::PAGE_SLUG,
            'nonceAction' => self::NONCE_ACTION,
            'severityLabels' => $this->severityLabels(),
            'typeLabels' => $this->typeLabels(),
            'scannerSummary' => $this->scannerSummary(),
            'status' => WpHelper::getQueryString(self::STATUS_QUERY_KEY, '') !== ''
                ? WpHelper::getQueryString(self::STATUS_QUERY_KEY)
                : null,
        ]);
    }

    public function handleRescan(): void
    {
        $this->guardWriteRequest();
        $result = $this->scheduler->run();
        $status = $result === null
            ? 'rescan_disabled'
            : 'rescan:' . $result->totalFindings();
        $this->redirect([self::STATUS_QUERY_KEY => $status]);
    }

    public function handleReview(): void
    {
        $this->guardWriteRequest();
        $id = max(0, (int) WpHelper::getPostString('id', '0'));
        $ok = $id > 0 && $this->findings->markReviewed($id);
        $this->redirect([self::STATUS_QUERY_KEY => $ok ? 'reviewed:' . $id : 'review_failed']);
    }

    public function handleDelete(): void
    {
        $this->guardWriteRequest();
        $id = max(0, (int) WpHelper::getPostString('id', '0'));
        $ok = $id > 0 && $this->findings->delete($id);
        $this->redirect([self::STATUS_QUERY_KEY => $ok ? 'deleted:' . $id : 'delete_failed']);
    }

    public function handleClear(): void
    {
        $this->guardWriteRequest();
        $deleted = $this->findings->deleteAll();
        $this->redirect([self::STATUS_QUERY_KEY => 'cleared:' . $deleted]);
    }

    public function handleResetBaseline(): void
    {
        $this->guardWriteRequest();
        $scope = WpHelper::getPostString('scope');
        if ($scope === '') {
            foreach ($this->manifests->scopes() as $known) {
                $this->manifests->delete($known);
            }
            $this->redirect([self::STATUS_QUERY_KEY => 'baseline_reset:all']);
            return;
        }

        $this->manifests->delete($scope);
        $this->redirect([self::STATUS_QUERY_KEY => 'baseline_reset:' . $scope]);
    }

    /**
     * @return array<string, string>
     */
    private function severityLabels(): array
    {
        return [
            FindingSeverity::INFO => 'Info',
            FindingSeverity::LOW => 'Low',
            FindingSeverity::MEDIUM => 'Medium',
            FindingSeverity::HIGH => 'High',
            FindingSeverity::CRITICAL => 'Critical',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function typeLabels(): array
    {
        $labels = [];
        foreach (FindingType::all() as $type) {
            $labels[$type] = FindingType::label($type);
        }

        return $labels;
    }

    /**
     * @return list<array{name:string}>
     */
    private function scannerSummary(): array
    {
        $names = [];
        foreach ($this->service->scanners() as $scanner) {
            $names[] = ['name' => $scanner->name()];
        }

        return $names;
    }

    private function guardWriteRequest(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            \call_user_func('wp_die', 'Insufficient permissions.', '', ['response' => 403]);
        }
        if (!WpHelper::verifyAdminNonce(self::NONCE_ACTION)) {
            \call_user_func('wp_die', 'Security check failed. Please reload and retry.', '', ['response' => 403]);
        }
    }

    /**
     * @param array<string, string> $extraQuery
     */
    private function redirect(array $extraQuery): void
    {
        $base = WpHelper::adminUrl('admin.php');
        $args = ['page' => self::PAGE_SLUG] + $extraQuery;
        $url = $base . '?' . http_build_query($args);

        WpHelper::safeRedirect($url);
        if (!\defined('NIYIGUARD_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }
}
