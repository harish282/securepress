<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Admin;

use SecurePress\Admin\LicensePage;
use SecurePress\Admin\SecurePressMenuPage;
use SecurePress\Core\Licensing\LicenseManager;
use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Support\WpHelper;
use SecurePress\WooCommerce\Middleware\Checkout\BotCheckoutMiddleware;

/**
 * Settings → WooCommerce Protection admin page.
 *
 * Uses the WordPress Settings API so we inherit nonce / capability handling, and
 * keeps the UI deliberately minimal — four sections (Checkout, Registration, API,
 * Cart), each with an "Enabled" toggle and the three or four numeric thresholds
 * that matter most. Power-users can still hand-edit the JSON in
 * `config/plugin.php` for finer control.
 *
 * Pro gating: when the license isn't active, the page renders a "Upgrade to Pro"
 * panel instead of the form. We still register the menu item so admins can SEE the
 * feature exists — discovery beats hiding it altogether for conversion reasons.
 */
final class WooCommerceProtectionPage
{
    public const PAGE_SLUG = 'securepress-woocommerce';
    public const OPTION_GROUP = 'securepress_wc_protection_group';

    public function __construct(
        private readonly WooCommerceProtectionOptions $options,
        private readonly LicenseManager $license,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('admin_menu', [$this, 'addMenu']);
        WpHelper::addAction('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        WpHelper::addSubmenuPage(
            SecurePressMenuPage::PARENT_SLUG,
            'SecurePress WooCommerce Protection',
            'WooCommerce Protection',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        WpHelper::registerSetting(
            self::OPTION_GROUP,
            WooCommerceProtectionOptions::OPTION_NAME,
            ['sanitize_callback' => [$this->options, 'sanitize']]
        );
    }

    public function renderPage(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $status = $this->license->status();
        $isPro = $this->license->isPro();
        $values = $this->options->all();

        echo '<div class="wrap">';
        echo '<h1>SecurePress &mdash; WooCommerce Protection</h1>';

        $this->renderLicenseBanner($status, $isPro);

        if (!$isPro) {
            $this->renderUpgradePrompt();
            echo '</div>';
            return;
        }

        if (!class_exists('WooCommerce', false)) {
            echo '<div class="notice notice-warning"><p>WooCommerce does not appear to be active. Activate WooCommerce to enable this module.</p></div>';
        }

        echo '<form method="post" action="options.php">';
        \call_user_func('settings_fields', self::OPTION_GROUP);

        $this->renderMasterToggle($values);
        $this->renderCheckoutSection($values);
        $this->renderRegistrationSection($values);
        $this->renderApiSection($values);
        $this->renderCartSection($values);

        \call_user_func('submit_button');
        echo '</form>';
        echo '</div>';
    }

    private function renderLicenseBanner(LicenseStatus $status, bool $isPro): void
    {
        if ($isPro) {
            if ($status->state === LicenseStatus::STATE_EARLY_ACCESS) {
                echo '<div class="notice notice-success inline" style="margin-bottom:1em;"><p>'
                    . '<strong>Early access.</strong> WooCommerce Protection and the rest of the Pro tier are '
                    . 'unlocked without a commercial license. Optional keys are managed on the '
                    . '<a href="' . WpHelper::escapeUrl(SecurePressMenuPage::submenuUrl(LicensePage::PAGE_SLUG)) . '">License</a> page.</p></div>';

                return;
            }

            if ($status->state === LicenseStatus::STATE_BETA_TRIAL) {
                $days = $status->daysRemaining();
                echo '<div class="notice notice-success inline" style="margin-bottom:1em;"><p>'
                    . '<strong>Beta trial active.</strong> WooCommerce Protection and the rest of the Pro tier are '
                    . 'unlocked during your evaluation period'
                    . ($days !== null ? ' (about <strong>' . (int) $days . '</strong> day(s) remaining)' : '')
                    . '.</p></div>';

                return;
            }

            $remaining = $status->daysRemaining();
            $msg = '<strong>Pro license active.</strong> Tier: <code>' . WpHelper::escapeHtml($status->tier) . '</code>';
            if ($remaining !== null) {
                $msg .= ' &mdash; renews in ' . (int) $remaining . ' day(s).';
            }
            echo '<div class="notice notice-success inline" style="margin-bottom:1em;"><p>' . $msg . '</p></div>';
            return;
        }
        $reason = $status->reason !== '' ? $status->reason : 'No active Pro license.';
        $licenseHint = LicensePage::shouldShowAdminMenu($status)
            ? ' Configure a license on the <a href="'
                . WpHelper::escapeUrl(SecurePressMenuPage::submenuUrl(LicensePage::PAGE_SLUG))
                . '">License</a> page.'
            : '';
        echo '<div class="notice notice-warning inline" style="margin-bottom:1em;"><p>'
            . '<strong>' . WpHelper::escapeHtml($reason) . '</strong>' . $licenseHint . '</p></div>';
    }

    private function renderUpgradePrompt(): void
    {
        echo '<div class="notice notice-info"><p>'
            . 'WooCommerce Protection is part of the <strong>SecurePress Pro</strong> tier. '
            . 'It includes behavioural fake-checkout, registration spam, API abuse, and cart abuse defences.'
            . '</p></div>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderMasterToggle(array $values): void
    {
        $name = WooCommerceProtectionOptions::OPTION_NAME;
        echo '<h2>Module</h2><table class="form-table" role="presentation"><tbody>';
        $this->toggleRow("{$name}[enabled]", 'Enable WooCommerce Protection', (bool) $values['enabled']);
        echo '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderCheckoutSection(array $values): void
    {
        $name = WooCommerceProtectionOptions::OPTION_NAME;
        $v = $values['checkout'];
        echo '<h2>Fake checkout protection</h2><table class="form-table" role="presentation"><tbody>';
        $this->toggleRow("{$name}[checkout][enabled]", 'Enable checkout protection', (bool) $v['enabled']);
        $this->numberRow("{$name}[checkout][velocity_soft]", 'Velocity: soft threshold', (int) $v['velocity_soft']);
        $this->numberRow("{$name}[checkout][velocity_hard]", 'Velocity: hard threshold', (int) $v['velocity_hard']);
        $this->numberRow("{$name}[checkout][velocity_window]", 'Velocity window (seconds)', (int) $v['velocity_window']);
        $this->numberRow(
            "{$name}[checkout][min_seconds_to_submit]",
            'Minimum seconds on checkout form',
            (int) $v['min_seconds_to_submit'],
            'Use <strong>0</strong> to disable timing checks. If you enable timing, start with at least <strong>8</strong> seconds — mobile, autofill, password managers, and express wallets often finish faster than desktop.'
        );
        $this->numberRow(
            "{$name}[checkout][bot][weight_impossible_timing]",
            'Fraud score: fast-checkout timing weight',
            (int) ($v['bot']['weight_impossible_timing'] ?? 30),
            'Points added when checkout is faster than the minimum. Does not block by itself unless you choose <strong>Block checkout</strong> below or the combined fraud score reaches the deny threshold.'
        );
        $this->timingActionRow(
            "{$name}[checkout][timing_action]",
            'When checkout is suspiciously fast',
            (string) ($v['timing_action'] ?? BotCheckoutMiddleware::TIMING_REPORT)
        );
        $this->numberRow("{$name}[checkout][fraud][challenge_threshold]", 'Fraud score: challenge threshold', (int) $v['fraud']['challenge_threshold']);
        $this->numberRow("{$name}[checkout][fraud][deny_threshold]", 'Fraud score: deny threshold', (int) $v['fraud']['deny_threshold']);
        echo '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderRegistrationSection(array $values): void
    {
        $name = WooCommerceProtectionOptions::OPTION_NAME;
        $v = $values['registration'];
        echo '<h2>Registration spam protection</h2><table class="form-table" role="presentation"><tbody>';
        $this->toggleRow("{$name}[registration][enabled]", 'Enable registration protection', (bool) $v['enabled']);
        $this->numberRow("{$name}[registration][rate_limit]", 'Max registrations per IP', (int) $v['rate_limit']);
        $this->numberRow("{$name}[registration][window]", 'Window (seconds)', (int) $v['window']);
        $this->toggleRow("{$name}[registration][deny_disposable_emails]", 'Deny disposable email domains', (bool) $v['deny_disposable_emails']);
        $this->numberRow("{$name}[registration][min_seconds_to_submit]", 'Minimum seconds to submit', (int) $v['min_seconds_to_submit']);
        echo '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderApiSection(array $values): void
    {
        $name = WooCommerceProtectionOptions::OPTION_NAME;
        $v = $values['api'];
        echo '<h2>API abuse protection</h2><table class="form-table" role="presentation"><tbody>';
        $this->toggleRow("{$name}[api][enabled]", 'Enable API protection', (bool) $v['enabled']);
        $this->numberRow("{$name}[api][default_limit]", 'Default per-IP limit', (int) $v['default_limit']);
        $this->numberRow("{$name}[api][default_window]", 'Default window (seconds)', (int) $v['default_window']);
        $this->toggleRow("{$name}[api][pass_when_authenticated]", 'Skip checks for logged-in requests', (bool) $v['pass_when_authenticated']);
        $this->toggleRow("{$name}[api][deny_on_scanner_ua]", 'Block known scanner User-Agents', (bool) $v['deny_on_scanner_ua']);
        echo '</tbody></table>';
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderCartSection(array $values): void
    {
        $name = WooCommerceProtectionOptions::OPTION_NAME;
        $v = $values['cart'];
        echo '<h2>Cart abuse protection</h2><table class="form-table" role="presentation"><tbody>';
        $this->toggleRow("{$name}[cart][enabled]", 'Enable cart abuse detection', (bool) $v['enabled']);
        $this->numberRow("{$name}[cart][velocity_soft]", 'Add-to-cart: soft threshold', (int) $v['velocity_soft']);
        $this->numberRow("{$name}[cart][velocity_hard]", 'Add-to-cart: hard threshold', (int) $v['velocity_hard']);
        $this->numberRow("{$name}[cart][window]", 'Window (seconds)', (int) $v['window']);
        $this->numberRow("{$name}[cart][coupon_soft]", 'Coupon failures: soft', (int) $v['coupon_soft']);
        $this->numberRow("{$name}[cart][coupon_hard]", 'Coupon failures: hard', (int) $v['coupon_hard']);
        echo '</tbody></table>';
    }

    private function toggleRow(string $name, string $label, bool $value): void
    {
        echo '<tr><th scope="row">' . WpHelper::escapeHtml($label) . '</th><td>';
        echo '<label><input type="checkbox" name="' . WpHelper::escapeAttribute($name) . '" value="1"' . ($value ? ' checked' : '') . ' /> Enabled</label>';
        echo '</td></tr>';
    }

    private function numberRow(string $name, string $label, int $value, string $description = ''): void
    {
        echo '<tr><th scope="row">' . WpHelper::escapeHtml($label) . '</th><td>';
        echo '<input type="number" min="0" name="' . WpHelper::escapeAttribute($name) . '" value="' . (int) $value . '" class="small-text" />';
        if ($description !== '') {
            echo '<p class="description">' . $description . '</p>';
        }
        echo '</td></tr>';
    }

    private function timingActionRow(string $name, string $label, string $value): void
    {
        $isReport = $value !== BotCheckoutMiddleware::TIMING_BLOCK;
        echo '<tr><th scope="row">' . WpHelper::escapeHtml($label) . '</th><td>';
        printf(
            '<label style="display:block;margin-bottom:6px;"><input type="radio" name="%1$s" value="%2$s"%3$s> <strong>Report only (recommended)</strong> — log to audit trail and increase fraud score. Does not block checkout by itself.</label>',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeAttribute(BotCheckoutMiddleware::TIMING_REPORT),
            $isReport ? ' checked' : ''
        );
        printf(
            '<label style="display:block;"><input type="radio" name="%1$s" value="%2$s"%3$s> <strong>Block checkout</strong> — immediately reject the order.</label>',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeAttribute(BotCheckoutMiddleware::TIMING_BLOCK),
            $isReport ? '' : ' checked'
        );
        echo '<p class="description">Requires minimum seconds &gt; 0. Mobile, autofill, password managers, and Shop Pay can be fast — start with <strong>Report only</strong> and review the audit log before enabling blocking.</p>';
        echo '</td></tr>';
    }
}
