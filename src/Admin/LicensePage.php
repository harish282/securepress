<?php

declare(strict_types=1);

namespace SecurePress\Admin;

use SecurePress\Core\Licensing\LicenseHmacSecretProvisioner;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Support\WpHelper;

/**
 * Settings → SecurePress License admin page.
 *
 * Three concerns:
 *  - **Show** the current status (active/expired/invalid/none) with a masked key.
 *  - **Submit** a new key (POST, nonce-checked, capability-gated). Persisted in
 *    the WordPress options table (`securepress_pro_license`).
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

    /**
     * The License submenu is hidden during an active beta trial so admins are not
     * prompted for keys before the evaluation window ends. POST handlers stay
     * registered; the screen remains reachable by direct URL if needed.
     */
    public static function shouldShowAdminMenu(LicenseStatus $status): bool
    {
        return $status->state !== LicenseStatus::STATE_BETA_TRIAL;
    }

    public function addMenu(): void
    {
        if (!self::shouldShowAdminMenu($this->license->status())) {
            return;
        }

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

        $this->renderStatusBanner($status);

        $this->renderInstallSigningSecretPanel();

        $isEarly = $status->state === LicenseStatus::STATE_EARLY_ACCESS;
        $isBetaTrial = $status->state === LicenseStatus::STATE_BETA_TRIAL;
        echo '<h2>' . ($isEarly ? 'License key (optional)' : 'Enter your license key') . '</h2>';
        echo '<form method="post" action="' . WpHelper::escapeUrl($adminUrl) . '">';
        echo '<input type="hidden" name="action" value="securepress_license_save" />';
        echo $nonceField(self::NONCE_ACTION);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="securepress-license-key">License key</label></th><td>';
        echo '<input type="text" id="securepress-license-key" name="license_key" value="" class="regular-text" autocomplete="off" placeholder="SP-PRO-1714780800-1746316800-………" />';
        $keyHelp = 'Keys are signed offline. Paste a valid key when your organization issues one.';
        if ($isEarly) {
            $keyHelp = 'Commercial licensing is not required at this stage. If you already have a signed preview key, paste it here; a valid key takes over from early access automatically.';
        } elseif ($isBetaTrial) {
            $keyHelp = 'Paste a valid key below any time before the trial ends; it takes over automatically when accepted.';
        }
        echo '<p class="description">' . WpHelper::escapeHtml($keyHelp) . '</p>';
        echo '</td></tr></tbody></table>';
        \call_user_func('submit_button', 'Save license');
        echo '</form>';

        // Only offer "remove license" when a key is actually stored (or the
        // validator surfaced invalid/expired material tied to that key). Early
        // access and beta trial use an empty option — there is nothing to clear
        // from the database in those cases.
        $storedKey = trim((string) WpHelper::getOption(LicenseManager::OPTION_NAME, ''));
        $mayClearStoredKey = $storedKey !== ''
            || $status->state === LicenseStatus::STATE_INVALID
            || $status->state === LicenseStatus::STATE_EXPIRED;

        if ($mayClearStoredKey) {
            echo '<form method="post" action="' . WpHelper::escapeUrl($adminUrl) . '" style="margin-top: 1em;">';
            echo '<input type="hidden" name="action" value="securepress_license_clear" />';
            echo $nonceField(self::NONCE_ACTION);
            echo '<button type="submit" class="button" onclick="return confirm(\'Remove the current license?\');">Remove license</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    private function renderInstallSigningSecretPanel(): void
    {
        $raw = WpHelper::getOption(LicenseHmacSecretProvisioner::OPTION_NAME, '');
        $secret = is_string($raw) ? trim($raw) : '';
        if (!LicenseHmacSecretProvisioner::isStoredSecretStrong($secret)) {
            return;
        }

        echo '<h2>Install signing secret</h2>';
        echo '<p class="description">This random value is stored in your WordPress database and is used to verify '
            . 'offline <code>SP-…</code> license keys for <strong>this site only</strong>. When you issue paid keys, '
            . 'your signing tool must use the same secret.</p>';
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">Secret</th><td>';
        echo '<input type="text" readonly class="large-text code" style="font-size:12px;" value="'
            . WpHelper::escapeAttribute($secret) . '" onclick="this.select();" />';
        echo '<p class="description">Click the field to select, then copy. Anyone with this string can forge keys that '
            . 'validate on this install — treat it like a password.</p>';
        echo '</td></tr></tbody></table>';
    }

    public function handleSave(): void
    {
        $this->guardWriteRequest();
        $key = isset($_POST['license_key']) && is_string($_POST['license_key']) ? trim($_POST['license_key']) : '';

        if ($key === '') {
            $this->license->clearLicense();
            $status = $this->license->status();
            $msg = match (true) {
                $status->state === LicenseStatus::STATE_EARLY_ACCESS => 'No key stored. Early access remains active.',
                $status->state === LicenseStatus::STATE_BETA_TRIAL => 'No key stored. Beta trial remains active.',
                default => 'License cleared.',
            };
            $this->redirect($msg);

            return;
        }

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

    private function renderStatusBanner(LicenseStatus $status): void
    {
        if ($status->isActive()) {
            echo '<div class="notice notice-success inline" style="margin-bottom:1em;"><p>'
                . '<strong>Pro license active.</strong> Tier: <code>'
                . WpHelper::escapeHtml($status->tier) . '</code>'
                . ($status->expiresAt ? ' &mdash; expires ' . WpHelper::escapeHtml(gmdate('Y-m-d', $status->expiresAt)) : '')
                . '</p></div>';

            return;
        }

        if ($status->state === LicenseStatus::STATE_EARLY_ACCESS) {
            echo '<div class="notice notice-info inline" style="margin-bottom:1em;"><p>'
                . '<strong>Early access build.</strong> '
                . 'All Pro features are unlocked without a commercial license or remote license server. '
                . 'You can still paste a signed key below if your organization issued one; a valid key replaces this mode automatically.'
                . '</p></div>';

            return;
        }

        if ($status->state === LicenseStatus::STATE_BETA_TRIAL) {
            $days = $status->daysRemaining();
            $until = $status->expiresAt !== null
                ? WpHelper::escapeHtml(gmdate('Y-m-d', $status->expiresAt))
                : '';
            echo '<div class="notice notice-info inline" style="margin-bottom:1em;"><p>'
                . '<strong>Public beta trial active.</strong> '
                . 'All Pro features are unlocked without a license key until '
                . ($until !== '' ? '<strong>' . $until . '</strong> (UTC)' : 'the trial end date')
                . ($days !== null ? ' &mdash; about <strong>' . (int) $days . '</strong> day(s) remaining.' : '.')
                . ' Enter a valid key below any time; it takes over automatically when accepted.'
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
