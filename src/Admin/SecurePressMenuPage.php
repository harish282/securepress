<?php

declare(strict_types=1);

namespace SecurePress\Admin;

use SecurePress\Core\Audit\AuditLogRepositoryInterface;
use SecurePress\Core\Auth\AuthHardeningOptions;
use SecurePress\Core\Headers\SecurityHeadersOptions;
use SecurePress\Core\Integrity\FindingRepositoryInterface;
use SecurePress\Core\Integrity\IntegrityOptions;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\View\View;

/**
 * Top-level "Secure Press" admin menu controller.
 *
 * Owns the parent menu slug that every other SecurePress admin page hangs off as a
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
final class SecurePressMenuPage
{
    /**
     * Slug used by every SecurePress submenu as the `parent_slug` argument. Pinned
     * to a stable value because external code (custom plugins, redirects, deep
     * links to `admin.php?page=securepress-…`) may rely on it.
     */
    public const PARENT_SLUG = 'securepress';

    public const DASHBOARD_SLUG = 'securepress';

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
        private readonly View $view,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu'], 0);
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
            'Secure Press',
            'Secure Press',
            'manage_options',
            self::PARENT_SLUG,
            [$this, 'render'],
            'dashicons-shield-alt',
            self::MENU_POSITION
        );

        WpHelper::addSubmenuPage(
            self::PARENT_SLUG,
            'Secure Press Dashboard',
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

        $enabledHeaderCount = 0;
        foreach ($headers as $group) {
            if (is_array($group) && (bool) ($group['enabled'] ?? false)) {
                $enabledHeaderCount++;
            }
        }

        $this->view->render('admin.dashboard.index', [
            'license' => [
                'isPro' => $this->license->isPro(),
                'status' => $licenseStatus,
            ],
            'auth' => [
                'enabled' => $this->authOptions->isEnabled(),
            ],
            'headers' => [
                'enabledCount' => $enabledHeaderCount,
                'totalCount' => count($headers),
            ],
            'integrity' => [
                'enabled' => $this->integrityOptions->isEnabled(),
                'openFindings' => $this->findings->countOpen(),
            ],
            'audit' => [
                'totalEvents' => $this->auditLog->count(),
            ],
            'links' => [
                'authentication' => $this->submenuUrl(AuthHardeningSettingsPage::PAGE_SLUG),
                'headers' => $this->submenuUrl(SecurityHeadersSettingsPage::PAGE_SLUG),
                'integrity' => $this->submenuUrl(FileIntegrityPage::PAGE_SLUG),
                'auditLog' => $this->submenuUrl(AuditLogPage::PAGE_SLUG),
                'license' => $this->submenuUrl(LicensePage::PAGE_SLUG),
            ],
        ]);
    }

    /**
     * Builds the canonical admin URL for a SecurePress submenu page. Centralising
     * this lets every page (and the dashboard tiles) move to a different parent
     * slug in future without touching every call site.
     */
    public static function submenuUrl(string $slug): string
    {
        return WpHelper::adminUrl('admin.php?page=' . $slug);
    }
}
