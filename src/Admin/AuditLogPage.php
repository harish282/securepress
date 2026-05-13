<?php

declare(strict_types=1);

namespace SecurePress\Admin;

use SecurePress\Core\Audit\AuditEventCategory;
use SecurePress\Core\Audit\AuditEventLevel;
use SecurePress\Core\Audit\AuditLogPruner;
use SecurePress\Core\Audit\AuditLogQuery;
use SecurePress\Core\Audit\AuditLogRepositoryInterface;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\View\View;

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
    public const PAGE_SLUG = 'securepress-audit-logs';

    public const NONCE_ACTION = 'securepress_audit_logs';

    public const STATUS_QUERY_KEY = 'securepress_status';

    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
        private readonly AuditLogPruner $pruner,
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu']);
        WpHelper::addAction('admin_post_securepress_clear_audit_logs', [$this, 'handleClear']);
        WpHelper::addAction('admin_post_securepress_prune_audit_logs', [$this, 'handlePrune']);
    }

    public function addMenu(): void
    {
        WpHelper::addSubmenuPage(
            SecurePressMenuPage::PARENT_SLUG,
            'SecurePress Audit Logs',
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

        $this->view->render('admin.audit.log-list', [
            'page' => $page,
            'query' => $query,
            'totalAll' => $totalAll,
            'pageSlug' => self::PAGE_SLUG,
            'nonceAction' => self::NONCE_ACTION,
            'categories' => AuditEventCategory::all(),
            'levels' => AuditEventLevel::all(),
            'status' => isset($_GET[self::STATUS_QUERY_KEY]) && is_string($_GET[self::STATUS_QUERY_KEY])
                ? $_GET[self::STATUS_QUERY_KEY]
                : null,
            'detailId' => isset($_GET['detail']) && is_numeric($_GET['detail'])
                ? (int) $_GET['detail']
                : null,
            'detail' => isset($_GET['detail']) && is_numeric($_GET['detail'])
                ? $this->repository->findById((int) $_GET['detail'])
                : null,
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
        $deleted = $this->pruner->prune();
        $this->redirect([self::STATUS_QUERY_KEY => 'pruned:' . $deleted]);
    }

    private function buildQuery(): AuditLogQuery
    {
        $query = new AuditLogQuery();

        $category = isset($_GET['category']) && is_string($_GET['category']) ? trim($_GET['category']) : '';
        $level = isset($_GET['level']) && is_string($_GET['level']) ? trim($_GET['level']) : '';
        $search = isset($_GET['s']) && is_string($_GET['s']) ? trim($_GET['s']) : '';

        $query->category = ($category !== '' && in_array($category, AuditEventCategory::all(), true)) ? $category : null;
        $query->level = ($level !== '' && AuditEventLevel::isValid($level)) ? $level : null;
        $query->search = $search === '' ? null : $search;
        $query->page = isset($_GET['paged']) && is_numeric($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $query->perPage = isset($_GET['per_page']) && is_numeric($_GET['per_page'])
            ? max(5, min(200, (int) $_GET['per_page']))
            : 25;

        $query->dateFrom = $this->parseDate($_GET['date_from'] ?? null, false);
        $query->dateTo = $this->parseDate($_GET['date_to'] ?? null, true);

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
        if (!\defined('SECUREPRESS_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }
}
