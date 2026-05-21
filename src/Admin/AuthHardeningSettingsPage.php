<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Core\Auth\AuthHardeningOptions;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * Settings → PressSentinel Authentication admin page.
 *
 * Lets a site admin enable / disable the entire authentication-hardening subsystem and
 * its sub-features (login lockout, sessions, suspicion alerts, 2FA challenge TTL,
 * notifications) without editing `config/plugin.php`. Mirrors
 * {@see SecurityHeadersSettingsPage} so the admin UX is consistent.
 *
 * Implementation notes:
 *  - Uses the standard WordPress Settings API for nonce + capability handling.
 *  - All values flow through {@see AuthHardeningOptions::sanitize()} as the
 *    `register_setting` callback, so the storage shape is always canonical.
 *  - The page is a thin shell — the actual form scaffolding lives in
 *    `resources/views/admin/settings/auth-hardening.php` so visual changes don't require
 *    editing the controller.
 *  - **Rebuild on save**: when the option is updated, `option_*` hooks ensure the value
 *    is freshly read on the next request. Because every service that consumes these
 *    flags resolves them at container-resolution time, a single page reload is enough
 *    for the new settings to take effect.
 */
final class AuthHardeningSettingsPage
{
    public const PAGE_SLUG = 'presssentinel-authentication';
    public const OPTION_GROUP = 'presssentinel_auth_hardening_group';

    public const SECTION_MASTER = 'presssentinel_section_auth_master';
    public const SECTION_LOCKOUT = 'presssentinel_section_auth_lockout';
    public const SECTION_SESSIONS = 'presssentinel_section_auth_sessions';
    public const SECTION_SUSPICION = 'presssentinel_section_auth_suspicion';
    public const SECTION_TWO_FACTOR = 'presssentinel_section_auth_two_factor';
    public const SECTION_NOTIFICATIONS = 'presssentinel_section_auth_notifications';

    /** @var array<string, mixed>|null */
    private ?array $cachedOptions = null;

    public function __construct(
        private readonly AuthHardeningOptions $options,
        private readonly View $view,
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
            PressSentinelMenuPage::PARENT_SLUG,
            'PressSentinel Authentication',
            'Authentication',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        WpHelper::registerSetting(
            self::OPTION_GROUP,
            AuthHardeningOptions::OPTION_NAME,
            ['sanitize_callback' => [$this->options, 'sanitize']]
        );

        WpHelper::addSettingsSection(
            self::SECTION_MASTER,
            'Authentication Hardening',
            [$this, 'renderMasterIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField(
            'auth_master_enabled',
            'Enable',
            [$this, 'renderMasterEnabled'],
            self::PAGE_SLUG,
            self::SECTION_MASTER
        );

        WpHelper::addSettingsSection(
            self::SECTION_LOCKOUT,
            'Login lockout',
            [$this, 'renderLockoutIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('lockout_enabled', 'Enable lockout', [$this, 'renderLockoutEnabled'], self::PAGE_SLUG, self::SECTION_LOCKOUT);
        WpHelper::addSettingsField('lockout_max_attempts', 'Max attempts', [$this, 'renderLockoutAttempts'], self::PAGE_SLUG, self::SECTION_LOCKOUT);
        WpHelper::addSettingsField('lockout_window_seconds', 'Counting window', [$this, 'renderLockoutWindow'], self::PAGE_SLUG, self::SECTION_LOCKOUT);
        WpHelper::addSettingsField('lockout_lock_seconds', 'Lock duration', [$this, 'renderLockoutDuration'], self::PAGE_SLUG, self::SECTION_LOCKOUT);

        WpHelper::addSettingsSection(
            self::SECTION_SESSIONS,
            'Sessions',
            [$this, 'renderSessionsIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('sessions_enabled', 'Track sessions', [$this, 'renderSessionsEnabled'], self::PAGE_SLUG, self::SECTION_SESSIONS);
        WpHelper::addSettingsField('sessions_retention', 'Retention (days)', [$this, 'renderSessionsRetention'], self::PAGE_SLUG, self::SECTION_SESSIONS);

        WpHelper::addSettingsSection(
            self::SECTION_SUSPICION,
            'Suspicious-login detection',
            [$this, 'renderSuspicionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('suspicion_enabled', 'Enable detection', [$this, 'renderSuspicionEnabled'], self::PAGE_SLUG, self::SECTION_SUSPICION);
        WpHelper::addSettingsField('suspicion_threshold', 'Alert threshold', [$this, 'renderSuspicionThreshold'], self::PAGE_SLUG, self::SECTION_SUSPICION);
        WpHelper::addSettingsField('suspicion_new_device', 'Rule: new device', [$this, 'renderSuspicionNewDevice'], self::PAGE_SLUG, self::SECTION_SUSPICION);

        WpHelper::addSettingsSection(
            self::SECTION_TWO_FACTOR,
            'Two-factor authentication',
            [$this, 'renderTwoFactorIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('two_factor_issuer', 'Issuer label', [$this, 'renderTwoFactorIssuer'], self::PAGE_SLUG, self::SECTION_TWO_FACTOR);
        WpHelper::addSettingsField('two_factor_ttl', 'Challenge TTL (seconds)', [$this, 'renderTwoFactorTtl'], self::PAGE_SLUG, self::SECTION_TWO_FACTOR);

        WpHelper::addSettingsSection(
            self::SECTION_NOTIFICATIONS,
            'Email notifications',
            [$this, 'renderNotificationsIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('notifications_enabled', 'Send security emails', [$this, 'renderNotificationsEnabled'], self::PAGE_SLUG, self::SECTION_NOTIFICATIONS);
    }

    public function renderPage(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $this->cachedOptions = null;

        $this->view->render('admin.settings.auth-hardening', [
            'pageSlug' => self::PAGE_SLUG,
            'optionGroup' => self::OPTION_GROUP,
        ]);
    }

    public function renderMasterIntro(): void
    {
        echo '<p>Globally turn the authentication-hardening features on or off. When disabled, login lockout, 2FA enforcement, session tracking, and suspicious-login alerts are <strong>not</strong> registered for this site.</p>';
    }

    public function renderMasterEnabled(): void
    {
        $this->renderCheckbox(['enabled'], 'Authentication hardening is active');
    }

    public function renderLockoutIntro(): void
    {
        echo '<p>Temporarily blocks repeated failed sign-in attempts. Counts are tracked per username <em>and</em> per IP, separately.</p>';
    }

    public function renderLockoutEnabled(): void
    {
        $this->renderCheckbox(['lockout', 'enabled']);
    }

    public function renderLockoutAttempts(): void
    {
        $this->renderInt(['lockout', 'max_attempts'], 1, 100, 'Failed attempts allowed inside the counting window before locking.');
    }

    public function renderLockoutWindow(): void
    {
        $this->renderInt(['lockout', 'window_seconds'], 60, 86_400, 'Seconds — failures older than this drop off the rolling counter.');
    }

    public function renderLockoutDuration(): void
    {
        $this->renderInt(['lockout', 'lock_seconds'], 60, 86_400, 'Seconds — how long the lock stays in place once triggered.');
    }

    public function renderSessionsIntro(): void
    {
        echo '<p>Records each authenticated session into <code>wp_presssentinel_sessions</code> so users can review and revoke devices from <strong>Account Security</strong>. A daily cron prunes rows older than the configured retention.</p>';
    }

    public function renderSessionsEnabled(): void
    {
        $this->renderCheckbox(['sessions', 'enabled']);
    }

    public function renderSessionsRetention(): void
    {
        $this->renderInt(['sessions', 'retention_days'], 1, 3650, 'Sessions whose <code>last_seen</code> / <code>revoked_at</code> is older than this are pruned.');
    }

    public function renderSuspicionIntro(): void
    {
        echo '<p>After a successful login, evaluates configured rules and emails the user when the score crosses the threshold. Detection is <em>passive</em> — it never blocks logins.</p>';
    }

    public function renderSuspicionEnabled(): void
    {
        $this->renderCheckbox(['suspicion', 'enabled']);
    }

    public function renderSuspicionThreshold(): void
    {
        $this->renderInt(['suspicion', 'alert_threshold'], 0, 200, 'Minimum score across all rules before an email is sent. The shipped <code>new_device</code> rule contributes 60.');
    }

    public function renderSuspicionNewDevice(): void
    {
        $this->renderCheckbox(['suspicion', 'rules', 'new_device'], 'Compare device fingerprint against prior sessions');
    }

    public function renderTwoFactorIntro(): void
    {
        echo '<p>Per-user enrolment lives at <strong>Account Security</strong>. The settings below apply to <em>all</em> 2FA flows.</p>';
    }

    public function renderTwoFactorIssuer(): void
    {
        $value = (string) $this->valueOf(['two_factor', 'issuer']);
        $name = $this->name(['two_factor', 'issuer']);
        printf(
            '<input type="text" name="%s" value="%s" class="regular-text" maxlength="64"> <span class="description">Shown inside authenticator apps next to each enrolment.</span>',
            esc_attr($name),
            esc_attr($value)
        );
    }

    public function renderTwoFactorTtl(): void
    {
        $this->renderInt(['two_factor', 'challenge_ttl_seconds'], 60, 3600, 'How long a pending password→2FA challenge stays valid.');
    }

    public function renderNotificationsIntro(): void
    {
        echo '<p>Controls outbound mail for: OTP delivery, 2FA enable / disable, recovery-code use, account lockout, and suspicious-login alerts. Disable for staging environments where you don\'t want test emails leaving the box.</p>';
    }

    public function renderNotificationsEnabled(): void
    {
        $this->renderCheckbox(['notifications', 'enabled']);
    }

    /**
     * @param list<string> $path
     */
    private function renderCheckbox(array $path, string $label = 'Enabled'): void
    {
        $checked = $this->valueOf($path) ? ' checked' : '';
        $name = $this->name($path);
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
            esc_attr($name),
            esc_attr($checked),
            esc_html($label)
        );
    }

    /**
     * @param list<string> $path
     */
    private function renderInt(array $path, int $min, int $max, string $description): void
    {
        $value = (int) $this->valueOf($path);
        $name = $this->name($path);
        printf(
            '<input type="number" name="%s" value="%s" min="%s" max="%s" class="small-text"> <span class="description">%s</span>',
            esc_attr($name),
            esc_attr((string) $value),
            esc_attr((string) $min),
            esc_attr((string) $max),
            esc_html($description)
        );
    }

    /**
     * @param list<string> $path
     */
    private function name(array $path): string
    {
        return AuthHardeningOptions::OPTION_NAME . '[' . implode('][', $path) . ']';
    }

    /**
     * @param list<string> $path
     */
    private function valueOf(array $path): mixed
    {
        if ($this->cachedOptions === null) {
            $this->cachedOptions = $this->options->all();
        }

        $cursor = $this->cachedOptions;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }
}
