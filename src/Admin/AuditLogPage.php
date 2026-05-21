<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Core\Audit\AuditEventCategory;
use PressSentinel\Core\Audit\AuditEventLevel;
use PressSentinel\Core\Audit\AuditLogPruner;
use PressSentinel\Core\Audit\AuditLogQuery;
use PressSentinel\Core\Audit\AuditLogRepositoryInterface;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * Admin "Tools → Audit Logs" page.
 *
 * The controller is intentionally thin: it parses `$_GET` into an {@see AuditLogQuery},
 * lets the repository materialise the page, and hands the result to the view template.
 *
 * Bulk operations (`Clear all logs`, `Run prune now`) are POST-only and gated by:
 *  - `manage_options` capability (set when registering the page).
 *  - WordPress nonce field via {@see WpHelper::verifyAdminNonce()}.
 *
 * After a write we always 302 back to the GET URL with a status flag so refreshing the
 * results page doesn't re-submit the form (Post/Redirect/Get).
 */
final class AuditLogPage
{
    public const PAGE_SLUG = 'presssentinel-audit-logs';

    public const NONCE_ACTION = 'presssentinel_audit_logs';

    public const STATUS_QUERY_KEY = 'presssentinel_status';

    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
        private readonly AuditLogPruner $pruner,
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu']);
        WpHelper::addAction('admin_post_presssentinel_clear_audit_logs', [$this, 'handleClear']);
        WpHelper::addAction('admin_post_presssentinel_prune_audit_logs', [$this, 'handlePrune']);
    }

    public function addMenu(): void
    {
        WpHelper::addSubmenuPage(
            PressSentinelMenuPage::PARENT_SLUG,
            'PressSentinel Audit Logs',
            'Audit Logs',
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

        $query = $this->buildQuery();
        $page = $this->repository->paginate($query);
        $totalAll = $this->repository->count();
        $detailRaw = WpHelper::getQueryString('detail', '');
        $detailId = is_numeric($detailRaw) ? max(0, (int) $detailRaw) : 0;

        $this->view->render('admin.audit.log-list', [
            'page' => $page,
            'query' => $query,
            'totalAll' => $totalAll,
            'pageSlug' => self::PAGE_SLUG,
            'nonceAction' => self::NONCE_ACTION,
            'categories' => AuditEventCategory::all(),
            'levels' => AuditEventLevel::all(),
            'status' => WpHelper::getQueryString(self::STATUS_QUERY_KEY, '') !== ''
                ? WpHelper::getQueryString(self::STATUS_QUERY_KEY)
                : null,
            'detailId' => $detailId > 0 ? $detailId : null,
            'detail' => $detailId > 0 ? $this->repository->findById($detailId) : null,
            'filterDateFrom' => WpHelper::getQueryString('date_from'),
            'filterDateTo' => WpHelper::getQueryString('date_to'),
        ]);
    }

    public function handleClear(): void
    {
        $this->guardWriteRequest();
        $deleted = $this->repository->deleteAll();
        $this->redirect([self::STATUS_QUERY_KEY => 'cleared:' . $deleted]);
    }

    public function handlePrune(): void
    {
        $this->guardWriteRequest();
        $deleted = $this->pruner->prune(manual: true);
        $this->redirect([self::STATUS_QUERY_KEY => 'pruned:' . $deleted]);
    }

    private function buildQuery(): AuditLogQuery
    {
        $query = new AuditLogQuery();

        $category = trim(WpHelper::getQueryString('category'));
        $level = trim(WpHelper::getQueryString('level'));
        $search = trim(WpHelper::getQueryString('s'));

        $query->category = ($category !== '' && in_array($category, AuditEventCategory::all(), true)) ? $category : null;
        $query->level = ($level !== '' && AuditEventLevel::isValid($level)) ? $level : null;
        $query->search = $search === '' ? null : $search;
        $query->page = WpHelper::getQueryInt('paged', 1, 1, 999_999);
        $query->perPage = WpHelper::getQueryInt('per_page', 25, 5, 200);

        $query->dateFrom = $this->parseDate(WpHelper::getQueryString('date_from', ''), false);
        $query->dateTo = $this->parseDate(WpHelper::getQueryString('date_to', ''), true);

        return $query;
    }

    private function parseDate(mixed $value, bool $endOfDay): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = strtotime($value . ($endOfDay ? ' 23:59:59 UTC' : ' 00:00:00 UTC'));

        return $parsed === false ? null : $parsed;
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
        if (!\defined('PRESS_SENTINEL_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }
}
