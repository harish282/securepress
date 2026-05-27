<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Core\Audit\AuditLogRepositoryInterface;
use PressSentinel\Core\Auth\AuthHardeningOptions;
use PressSentinel\Core\Config\Config;
use PressSentinel\Core\Headers\SecurityHeadersOptions;
use PressSentinel\Core\Integrity\FindingRepositoryInterface;
use PressSentinel\Core\Integrity\IntegrityOptions;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * Top-level "Press Sentinel" admin menu controller.
 */
final class PressSentinelMenuPage
{
    public const PARENT_SLUG = 'presssentinel';

    public const DASHBOARD_SLUG = 'presssentinel';

    public const NONCE_ACTION = 'presssentinel_features';

    public const STATUS_QUERY_KEY = 'presssentinel_status';

    private const MENU_POSITION = 58;

    public function __construct(
        private readonly Config $config,
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
        $this->registerPostHandler();
        $this->registerMenu();
    }

    public function registerPostHandler(): void
    {
        WpHelper::addAction('admin_post_' . self::NONCE_ACTION, [$this, 'handleSaveFeatures']);
    }

    public function registerMenu(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu'], 0);
    }

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

        $headers = $this->headersOptions->all();

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

        $support = $this->resolveSupportConfig();

        $this->view->render('admin.dashboard.index', [
            'auth' => [
                'enabled' => $this->authOptions->isEnabled(),
            ],
            'headers' => [
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
                'formAction' => WpHelper::adminUrl('admin-post.php'),
                'nonceAction' => self::NONCE_ACTION,
                'actionName' => self::NONCE_ACTION,
            ],
            'support' => $support,
            'status' => WpHelper::getQueryString(self::STATUS_QUERY_KEY, '') !== ''
                ? WpHelper::getQueryString(self::STATUS_QUERY_KEY)
                : null,
            'links' => [
                'authentication' => $this->submenuUrl(AuthHardeningSettingsPage::PAGE_SLUG),
                'headers' => $this->submenuUrl(SecurityHeadersSettingsPage::PAGE_SLUG),
                'integrity' => $this->submenuUrl(FileIntegrityPage::PAGE_SLUG),
                'auditLog' => $this->submenuUrl(AuditLogPage::PAGE_SLUG),
            ],
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
     * @return array{review_url:string,donation_url:string,donation_label:string}
     */
    private function resolveSupportConfig(): array
    {
        return [
            'review_url' => trim((string) $this->config->get('support.review_url', '')),
            'donation_url' => trim((string) $this->config->get('support.donation_url', '')),
            'donation_label' => trim((string) $this->config->get('support.donation_label', 'Support on Ko-fi')),
        ];
    }

    public function handleSaveFeatures(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            \call_user_func('wp_die', 'Insufficient permissions.', '', ['response' => 403]);
        }
        if (!WpHelper::verifyAdminNonce(self::NONCE_ACTION)) {
            \call_user_func('wp_die', 'Security check failed. Please reload and retry.', '', ['response' => 403]);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above.
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

    public static function submenuUrl(string $slug): string
    {
        return WpHelper::adminUrl('admin.php?page=' . $slug);
    }
}
