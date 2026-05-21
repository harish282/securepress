<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Core\Audit\AuditLogRepositoryInterface;
use PressSentinel\Core\Auth\AuthHardeningOptions;
use PressSentinel\Core\Headers\SecurityHeadersOptions;
use PressSentinel\Core\Integrity\FindingRepositoryInterface;
use PressSentinel\Core\Integrity\IntegrityOptions;
use PressSentinel\Core\Licensing\LicenseManager;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * Top-level "Press Sentinel" admin menu controller.
 *
 * Owns the parent menu slug that every other PressSentinel admin page hangs off as a
 * submenu. The page itself renders a lightweight dashboard summarising the state of
 * each subsystem (license, auth hardening, security headers, file integrity, audit
 * log) so an admin can see "is everything still on?" at a glance and jump to the
 * relevant settings page in one click.
 *
 * Registration ordering matters: this class must register itself on `admin_menu`
 * BEFORE any of the submenu pages, otherwise WordPress drops the children. The
 * Plugin bootstrap takes care of that by calling `register()` here first inside the
 * shared `admin_menu` priority-1 callback.
 *
 * The dashboard is intentionally read-only — it never writes settings. Each tile
 * links to the canonical submenu page where toggles live, keeping the
 * "configure-one-place / one-source-of-truth" property of the existing pages.
 */
final class PressSentinelMenuPage
{
    /**
     * Slug used by every PressSentinel submenu as the `parent_slug` argument. Pinned
     * to a stable value because external code (custom plugins, redirects, deep
     * links to `admin.php?page=presssentinel-…`) may rely on it.
     */
    public const PARENT_SLUG = 'presssentinel';

    public const DASHBOARD_SLUG = 'presssentinel';

    /**
     * Nonce action for the dashboard feature-toggle form. Distinct from the
     * other admin pages' nonces so a leaked nonce from (say) the audit log
     * page can't be replayed against the feature-toggle endpoint.
     */
    public const NONCE_ACTION = 'presssentinel_features';

    /**
     * Query-string key the admin_post handler uses to surface the
     * "X features updated" notice after a successful save. Read in
     * {@see render()} and rendered to the page; never used for branching.
     */
    public const STATUS_QUERY_KEY = 'presssentinel_status';

    /**
     * Position 58 sits right under "Comments" (25) and above "Appearance" (60) on
     * a vanilla install — high enough to be discoverable for a security plugin
     * without colliding with the standard top-level menus.
     */
    private const MENU_POSITION = 58;

    public function __construct(
        private readonly LicenseManager $license,
        private readonly AuthHardeningOptions $authOptions,
        private readonly SecurityHeadersOptions $headersOptions,
        private readonly IntegrityOptions $integrityOptions,
        private readonly FindingRepositoryInterface $findings,
        private readonly AuditLogRepositoryInterface $auditLog,
        private readonly FeatureRegistry $features,
        private readonly MuLoaderStatus $muLoader,
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu'], 0);
        // admin_post_* handlers MUST be registered before wp-admin/admin-post.php
        // dispatches the action. The plugin bootstrap calls register() from a
        // priority-1 `init` hook, which fires comfortably before admin-post.php's
        // own admin_init / admin_post_* sequence — see Plugin::registerAdminHooks().
        WpHelper::addAction('admin_post_' . self::NONCE_ACTION, [$this, 'handleSaveFeatures']);
    }

    /**
     * Registers the top-level menu and renames the auto-created first submenu so
     * the user sees "Dashboard" rather than the parent label repeated.
     *
     * We register at priority 0 (via {@see register()}) so this fires before every
     * submenu page that hooks in at priority 1 — WordPress requires the parent to
     * exist before any child can be attached.
     */
    public function addMenu(): void
    {
        WpHelper::addMenuPage(
            'Press Sentinel',
            'Press Sentinel',
            'manage_options',
            self::PARENT_SLUG,
            [$this, 'render'],
            'dashicons-shield-alt',
            self::MENU_POSITION
        );

        WpHelper::addSubmenuPage(
            self::PARENT_SLUG,
            'Press Sentinel Dashboard',
            'Dashboard',
            'manage_options',
            self::DASHBOARD_SLUG,
            [$this, 'render'],
            0
        );
    }

    public function render(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $licenseStatus = $this->license->status();
        $headers = $this->headersOptions->all();

        // Count only the per-header `enabled` flags — skip the top-level
        // `enabled` master switch, which has no `policy`/`value` sub-key and
        // would otherwise inflate the on-count by 1 even when every header
        // is off.
        $enabledHeaderCount = 0;
        $totalHeaderCount = 0;
        foreach ($headers as $key => $group) {
            if ($key === 'enabled' || !is_array($group)) {
                continue;
            }
            $totalHeaderCount++;
            if ((bool) ($group['enabled'] ?? false)) {
                $enabledHeaderCount++;
            }
        }

        $this->view->render('admin.dashboard.index', [
            'license' => [
                'isPro' => $this->license->isPro(),
                'status' => $licenseStatus,
                'menuVisible' => LicensePage::shouldShowAdminMenu($licenseStatus),
            ],
            'auth' => [
                'enabled' => $this->authOptions->isEnabled(),
            ],
            'headers' => [
                // Show "0 of N" when the master is off — explicit beats
                // "All off" which is ambiguous between "no headers selected"
                // and "feature disabled entirely".
                'enabledCount' => $this->headersOptions->isEnabled() ? $enabledHeaderCount : 0,
                'totalCount' => $totalHeaderCount,
                'masterEnabled' => $this->headersOptions->isEnabled(),
            ],
            'integrity' => [
                'enabled' => $this->integrityOptions->isEnabled(),
                'openFindings' => $this->findings->countOpen(),
            ],
            'audit' => [
                'totalEvents' => $this->auditLog->count(),
            ],
            'features' => [
                'list' => $this->features->all(),
                'isPro' => $this->features->isPro(),
                'formAction' => WpHelper::adminUrl('admin-post.php'),
                'nonceAction' => self::NONCE_ACTION,
                'actionName' => self::NONCE_ACTION,
            ],
            'status' => WpHelper::getQueryString(self::STATUS_QUERY_KEY, '') !== ''
                ? WpHelper::getQueryString(self::STATUS_QUERY_KEY)
                : null,
            'links' => [
                'authentication' => $this->submenuUrl(AuthHardeningSettingsPage::PAGE_SLUG),
                'headers' => $this->submenuUrl(SecurityHeadersSettingsPage::PAGE_SLUG),
                'integrity' => $this->submenuUrl(FileIntegrityPage::PAGE_SLUG),
                'auditLog' => $this->submenuUrl(AuditLogPage::PAGE_SLUG),
                'license' => $this->submenuUrl(LicensePage::PAGE_SLUG),
            ],
            // MU loader status drives a prominent setup callout when missing.
            // We resolve the download URL + nonce here rather than in the
            // view so the template stays a dumb renderer, and so tests can
            // assert the exact action + nonce strings without re-parsing
            // hidden inputs.
            'muLoader' => [
                'isInstalled' => $this->muLoader->isInstalled(),
                'expectedPath' => $this->muLoader->expectedPath(),
                'expectedDirectory' => $this->muLoader->expectedDirectory(),
                'loaderFilename' => $this->muLoader->loaderFilename(),
                'downloadAction' => MuLoaderDownloadController::ACTION,
                'downloadUrl' => WpHelper::adminUrl('admin-post.php'),
            ],
        ]);
    }

    /**
     * admin-post.php handler for the dashboard feature-toggle form.
     *
     * Flow (POST/Redirect/GET):
     *  1. Hard-gate on `manage_options` — log-only side effects mean any
     *     lesser cap would let lower-privileged admins disable security.
     *  2. Hard-gate on the dashboard's own nonce so CSRF can't flip toggles.
     *  3. Resolve the desired state per feature from `$_POST['features']`.
     *     Missing keys count as "off" — that's how an unchecked checkbox is
     *     submitted by the browser.
     *  4. Hand off to FeatureRegistry::apply() which only touches options
     *     that actually changed. The returned `$changed` count drives the
     *     redirect status flag.
     *  5. 303-redirect back to the dashboard so the page doesn't replay the
     *     form on refresh.
     */
    public function handleSaveFeatures(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            \call_user_func('wp_die', 'Insufficient permissions.', '', ['response' => 403]);
        }
        if (!WpHelper::verifyAdminNonce(self::NONCE_ACTION)) {
            \call_user_func('wp_die', 'Security check failed. Please reload and retry.', '', ['response' => 403]);
        }

        // The form submits `features[key] = "1"` for every checked toggle and
        // omits the key entirely when unchecked. Build the desired map by
        // iterating the canonical feature list rather than the POST payload —
        // that way an attacker can't smuggle in keys we don't recognise.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checkbox map; keys validated against FeatureRegistry below.
        $posted = isset($_POST['features']) && is_array($_POST['features']) ? $_POST['features'] : [];
        $desired = [];
        foreach ($this->features->all() as $feature) {
            $desired[$feature->key] = isset($posted[$feature->key]) && (string) $posted[$feature->key] === '1';
        }

        $changed = $this->features->apply($desired);

        $this->redirect([self::STATUS_QUERY_KEY => 'saved:' . count($changed)]);
    }

    /**
     * @param array<string, string> $extraQuery
     */
    private function redirect(array $extraQuery): void
    {
        $base = WpHelper::adminUrl('admin.php');
        $args = ['page' => self::DASHBOARD_SLUG] + $extraQuery;
        $url = $base . '?' . http_build_query($args);

        WpHelper::safeRedirect($url);
        if (!\defined('PRESS_SENTINEL_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }

    /**
     * Builds the canonical admin URL for a PressSentinel submenu page. Centralising
     * this lets every page (and the dashboard tiles) move to a different parent
     * slug in future without touching every call site.
     */
    public static function submenuUrl(string $slug): string
    {
        return WpHelper::adminUrl('admin.php?page=' . $slug);
    }
}
