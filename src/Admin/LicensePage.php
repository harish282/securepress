<?php

declare(strict_types=1);

namespace SecurePress\Admin;

use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Support\WpHelper;

/**
 * Settings → SecurePress License admin page.
 *
 * Three concerns:
 *  - **Show** the current status (active/expired/invalid/none) with a masked key.
 *  - **Submit** a new key (POST, nonce-checked, capability-gated).
 *  - **Clear** the key — useful for moving a license to a different site.
 *
 * The page intentionally does NOT contact the vendor server. Validation goes through
 * the injected {@see \SecurePress\Core\Licensing\LicenseValidatorInterface}, so the
 * default offline-HMAC validator works fully air-gapped. Operators who want online
 * checks bind a different validator in `Plugin.php`.
 *
 * UI is deliberately minimal — no marketing, no upgrade comparison table. Pages
 * across SecurePress link to this one for license management, and this stays the
 * single source of truth for that workflow.
 */
final class LicensePage
{
    public const PAGE_SLUG = 'securepress-license';
    public const NONCE_ACTION = 'securepress_license';
    public const STATUS_QUERY_KEY = 'securepress_license_status';

    public function __construct(private readonly LicenseManager $license)
    {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu']);
        WpHelper::addAction('admin_post_securepress_license_save', [$this, 'handleSave']);
        WpHelper::addAction('admin_post_securepress_license_clear', [$this, 'handleClear']);
    }

    public function addMenu(): void
    {
        WpHelper::addSubmenuPage(
            SecurePressMenuPage::PARENT_SLUG,
            'SecurePress License',
            'License',
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
        $status = $this->license->status();
        $isPro = $this->license->isPro();
        $adminUrl = WpHelper::adminUrl('admin-post.php');
        $statusFlag = isset($_GET[self::STATUS_QUERY_KEY]) && is_string($_GET[self::STATUS_QUERY_KEY])
            ? (string) $_GET[self::STATUS_QUERY_KEY]
            : '';

        $nonceField = static function (string $action): string {
            if (\function_exists('wp_nonce_field')) {
                ob_start();
                \call_user_func('wp_nonce_field', $action);
                return (string) ob_get_clean();
            }
            return '';
        };

        echo '<div class="wrap">';
        echo '<h1>SecurePress &mdash; License</h1>';

        if ($statusFlag !== '') {
            echo '<div class="notice notice-info is-dismissible"><p>'
                . WpHelper::escapeHtml($statusFlag) . '</p></div>';
        }

        $this->renderStatusBanner($status, $isPro);

        echo '<h2>Enter your license key</h2>';
        echo '<form method="post" action="' . WpHelper::escapeUrl($adminUrl) . '">';
        echo '<input type="hidden" name="action" value="securepress_license_save" />';
        echo $nonceField(self::NONCE_ACTION);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="securepress-license-key">License key</label></th><td>';
        echo '<input type="text" id="securepress-license-key" name="license_key" value="" class="regular-text" autocomplete="off" placeholder="SP-PRO-1714780800-1746316800-………" />';
        echo '<p class="description">Keys are signed offline. Paste the key you received during purchase.</p>';
        echo '</td></tr></tbody></table>';
        \call_user_func('submit_button', 'Save license');
        echo '</form>';

        if ($status->state !== LicenseStatus::STATE_NONE) {
            echo '<form method="post" action="' . WpHelper::escapeUrl($adminUrl) . '" style="margin-top: 1em;">';
            echo '<input type="hidden" name="action" value="securepress_license_clear" />';
            echo $nonceField(self::NONCE_ACTION);
            echo '<button type="submit" class="button" onclick="return confirm(\'Remove the current license?\');">Remove license</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    public function handleSave(): void
    {
        $this->guardWriteRequest();
        $key = isset($_POST['license_key']) && is_string($_POST['license_key']) ? trim($_POST['license_key']) : '';
        $status = $this->license->setLicense($key);
        $msg = $status->isActive()
            ? 'License activated.'
            : 'License rejected: ' . $status->reason;
        $this->redirect($msg);
    }

    public function handleClear(): void
    {
        $this->guardWriteRequest();
        $this->license->clearLicense();
        $this->redirect('License removed.');
    }

    private function renderStatusBanner(LicenseStatus $status, bool $isPro): void
    {
        if ($isPro) {
            echo '<div class="notice notice-success inline" style="margin-bottom:1em;"><p>'
                . '<strong>Pro license active.</strong> Tier: <code>'
                . WpHelper::escapeHtml($status->tier) . '</code>'
                . ($status->expiresAt ? ' &mdash; expires ' . WpHelper::escapeHtml(gmdate('Y-m-d', $status->expiresAt)) : '')
                . '</p></div>';
            return;
        }
        $label = match ($status->state) {
            LicenseStatus::STATE_EXPIRED => 'License expired',
            LicenseStatus::STATE_INVALID => 'License invalid',
            default => 'No license',
        };
        $detail = $status->reason !== '' ? ' &mdash; ' . WpHelper::escapeHtml($status->reason) : '';
        $masked = $status->maskedKey !== null ? ' (' . WpHelper::escapeHtml((string) $status->maskedKey) . ')' : '';
        echo '<div class="notice notice-warning inline" style="margin-bottom:1em;"><p>'
            . '<strong>' . WpHelper::escapeHtml($label) . '.</strong>' . $detail . $masked . '</p></div>';
    }

    private function guardWriteRequest(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            \call_user_func('wp_die', 'Insufficient permissions.', '', ['response' => 403]);
        }
        if (!WpHelper::verifyAdminNonce(self::NONCE_ACTION)) {
            \call_user_func('wp_die', 'Security check failed.', '', ['response' => 403]);
        }
    }

    private function redirect(string $message): void
    {
        $base = WpHelper::adminUrl('admin.php');
        $url = $base . '?' . http_build_query([
            'page' => self::PAGE_SLUG,
            self::STATUS_QUERY_KEY => $message,
        ]);
        WpHelper::safeRedirect($url);
        if (!\defined('SECUREPRESS_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }
}
