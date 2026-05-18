<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Core\Headers\CspHeader;
use PressSentinel\Core\Headers\ReferrerPolicyHeader;
use PressSentinel\Core\Headers\SecurityHeadersOptions;
use PressSentinel\Core\Headers\XFrameOptionsHeader;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * Settings → PressSentinel Headers admin page.
 *
 * Uses the standard WordPress Settings API so we get nonce protection, capability checks,
 * and persistence into `wp_options` for free. Each header is rendered as a section with a
 * single "Enabled" toggle plus any header-specific fields (HSTS max-age, CSP policy, etc.).
 *
 * The page is a thin shell: actual rendering of the form fields lives in this class (so each
 * field's HTML is co-located with its `add_settings_field` registration), while the page
 * scaffolding (header, form open/close, save button) is delegated to a view template for
 * easier customization.
 */
final class SecurityHeadersSettingsPage
{
    public const PAGE_SLUG = 'presssentinel-security-headers';

    public const OPTION_GROUP = 'presssentinel_security_headers_group';

    /**
     * Section that hosts the master `enabled` toggle. Comes first on the page
     * so admins immediately see whether the feature is on, and so the same
     * variable the dashboard's feature-toggle form writes is also editable
     * here — preventing the two views from desyncing.
     */
    public const SECTION_MASTER = 'presssentinel_section_master';

    public const SECTION_HSTS = 'presssentinel_section_hsts';

    public const SECTION_CSP = 'presssentinel_section_csp';

    public const SECTION_XFO = 'presssentinel_section_xfo';

    public const SECTION_REFERRER = 'presssentinel_section_referrer';

    public const SECTION_PERMISSIONS = 'presssentinel_section_permissions';

    public const SECTION_XCTO = 'presssentinel_section_xcto';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $cachedOptions = null;

    public function __construct(
        private readonly SecurityHeadersOptions $options,
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
            'PressSentinel Security Headers',
            'Security Headers',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        WpHelper::registerSetting(
            self::OPTION_GROUP,
            SecurityHeadersOptions::OPTION_NAME,
            ['sanitize_callback' => [$this->options, 'sanitize']]
        );

        // Master switch FIRST so it's the most prominent control on the page —
        // and so the page form submits the same `enabled` key the dashboard's
        // feature-toggle form writes to. Without this, partial submissions
        // would silently re-enable the master each time an admin saved a
        // sub-section here (the historic source of "dashboard says off but
        // headers are still being sent").
        WpHelper::addSettingsSection(
            self::SECTION_MASTER,
            'Security Headers',
            [$this, 'renderMasterSectionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('master_enabled', 'Emit security headers', [$this, 'renderMasterEnabled'], self::PAGE_SLUG, self::SECTION_MASTER);

        WpHelper::addSettingsSection(
            self::SECTION_HSTS,
            'Strict-Transport-Security (HSTS)',
            [$this, 'renderHstsSectionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('hsts_enabled', 'Enable HSTS', [$this, 'renderHstsEnabled'], self::PAGE_SLUG, self::SECTION_HSTS);
        WpHelper::addSettingsField('hsts_max_age', 'max-age (seconds)', [$this, 'renderHstsMaxAge'], self::PAGE_SLUG, self::SECTION_HSTS);
        WpHelper::addSettingsField('hsts_subdomains', 'Include subdomains', [$this, 'renderHstsSubdomains'], self::PAGE_SLUG, self::SECTION_HSTS);
        WpHelper::addSettingsField('hsts_preload', 'preload', [$this, 'renderHstsPreload'], self::PAGE_SLUG, self::SECTION_HSTS);

        WpHelper::addSettingsSection(
            self::SECTION_CSP,
            'Content-Security-Policy (CSP)',
            [$this, 'renderCspSectionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('csp_enabled', 'Enable CSP', [$this, 'renderCspEnabled'], self::PAGE_SLUG, self::SECTION_CSP);
        WpHelper::addSettingsField('csp_report_only', 'Report-Only mode', [$this, 'renderCspReportOnly'], self::PAGE_SLUG, self::SECTION_CSP);
        WpHelper::addSettingsField('csp_policy', 'Policy', [$this, 'renderCspPolicy'], self::PAGE_SLUG, self::SECTION_CSP);

        WpHelper::addSettingsSection(
            self::SECTION_XFO,
            'X-Frame-Options',
            [$this, 'renderXfoSectionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('xfo_enabled', 'Enable X-Frame-Options', [$this, 'renderXfoEnabled'], self::PAGE_SLUG, self::SECTION_XFO);
        WpHelper::addSettingsField('xfo_value', 'Value', [$this, 'renderXfoValue'], self::PAGE_SLUG, self::SECTION_XFO);

        WpHelper::addSettingsSection(
            self::SECTION_REFERRER,
            'Referrer-Policy',
            [$this, 'renderReferrerSectionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('referrer_enabled', 'Enable Referrer-Policy', [$this, 'renderReferrerEnabled'], self::PAGE_SLUG, self::SECTION_REFERRER);
        WpHelper::addSettingsField('referrer_policy', 'Policy', [$this, 'renderReferrerPolicy'], self::PAGE_SLUG, self::SECTION_REFERRER);

        WpHelper::addSettingsSection(
            self::SECTION_PERMISSIONS,
            'Permissions-Policy',
            [$this, 'renderPermissionsSectionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('permissions_enabled', 'Enable Permissions-Policy', [$this, 'renderPermissionsEnabled'], self::PAGE_SLUG, self::SECTION_PERMISSIONS);
        WpHelper::addSettingsField('permissions_policy', 'Policy', [$this, 'renderPermissionsPolicy'], self::PAGE_SLUG, self::SECTION_PERMISSIONS);

        WpHelper::addSettingsSection(
            self::SECTION_XCTO,
            'X-Content-Type-Options',
            [$this, 'renderXctoSectionIntro'],
            self::PAGE_SLUG
        );
        WpHelper::addSettingsField('xcto_enabled', 'Enable nosniff', [$this, 'renderXctoEnabled'], self::PAGE_SLUG, self::SECTION_XCTO);
    }

    public function renderPage(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $this->cachedOptions = null; // ensure fresh read after a save

        $this->view->render('admin.settings.security-headers', [
            'pageSlug' => self::PAGE_SLUG,
            'optionGroup' => self::OPTION_GROUP,
            'masterEnabled' => $this->options->isEnabled(),
        ]);
    }

    public function renderMasterSectionIntro(): void
    {
        echo '<p>Master switch for the entire feature. When off, no header below is emitted regardless of its per-section toggle &mdash; useful when you want to pause everything without losing your per-header configuration. The same toggle is mirrored on the <strong>PressSentinel</strong> dashboard.</p>';
    }

    public function renderMasterEnabled(): void
    {
        // Top-level key (no group), so this can't go through renderCheckbox()
        // which assumes a nested `[group][key]` shape. The hidden 0 + checked
        // 1 pattern is the standard WP-checkbox workaround so unchecking
        // submits a 0 rather than the field disappearing entirely.
        $checked = $this->masterValue() ? ' checked' : '';
        $name = sprintf('%s[enabled]', SecurityHeadersOptions::OPTION_NAME);
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> Send all enabled security headers on every response</label>',
            WpHelper::escapeAttribute($name),
            $checked
        );
    }

    public function renderHstsSectionIntro(): void
    {
        echo '<p>Tells browsers to load this site over HTTPS only. <strong>Verify HTTPS works site-wide before enabling.</strong> The <code>preload</code> flag is permanent and effectively irreversible — only enable after submitting your domain to <a href="https://hstspreload.org" target="_blank" rel="noopener noreferrer">hstspreload.org</a>.</p>';
    }

    public function renderHstsEnabled(): void
    {
        $this->renderCheckbox('hsts', 'enabled');
    }

    public function renderHstsMaxAge(): void
    {
        $value = (int) $this->valueOf('hsts', 'max_age');
        $name = $this->name('hsts', 'max_age');
        printf(
            '<input type="number" min="0" step="1" name="%s" value="%s" class="regular-text"> <span class="description">e.g. <code>31536000</code> = 1 year</span>',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeAttribute((string) $value)
        );
    }

    public function renderHstsSubdomains(): void
    {
        $this->renderCheckbox('hsts', 'include_subdomains', 'Apply to all subdomains');
    }

    public function renderHstsPreload(): void
    {
        $this->renderCheckbox('hsts', 'preload', 'Include in browser preload list (irreversible)');
    }

    public function renderCspSectionIntro(): void
    {
        echo '<p>Restricts the sources from which the browser may load scripts, styles, fonts, etc. <strong>Highly likely to break a site that hasn\'t been audited.</strong> Always start with Report-Only mode, watch the browser console / your reporting endpoint for violations, then flip to enforce.</p>';
    }

    public function renderCspEnabled(): void
    {
        $this->renderCheckbox('csp', 'enabled');
    }

    public function renderCspReportOnly(): void
    {
        $this->renderCheckbox('csp', 'report_only', 'Send <code>Content-Security-Policy-Report-Only</code> instead of enforcing');
    }

    public function renderCspPolicy(): void
    {
        $value = (string) $this->valueOf('csp', 'policy');
        $name = $this->name('csp', 'policy');
        printf(
            '<textarea name="%s" rows="5" class="large-text code">%s</textarea>',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeTextarea($value)
        );
    }

    public function renderXfoSectionIntro(): void
    {
        echo '<p>Clickjacking protection. <code>SAMEORIGIN</code> is recommended for WordPress so the customizer / preview iframes still work.</p>';
    }

    public function renderXfoEnabled(): void
    {
        $this->renderCheckbox('x_frame_options', 'enabled');
    }

    public function renderXfoValue(): void
    {
        $value = (string) $this->valueOf('x_frame_options', 'value');
        $name = $this->name('x_frame_options', 'value');
        echo '<select name="' . WpHelper::escapeAttribute($name) . '">';
        foreach (XFrameOptionsHeader::VALID_VALUES as $option) {
            printf(
                '<option value="%s"%s>%s</option>',
                WpHelper::escapeAttribute($option),
                $option === $value ? ' selected' : '',
                WpHelper::escapeHtml($option)
            );
        }
        echo '</select>';
    }

    public function renderReferrerSectionIntro(): void
    {
        echo '<p>Controls how much of the URL is sent in the <code>Referer</code> header on outbound navigations.</p>';
    }

    public function renderReferrerEnabled(): void
    {
        $this->renderCheckbox('referrer_policy', 'enabled');
    }

    public function renderReferrerPolicy(): void
    {
        $value = (string) $this->valueOf('referrer_policy', 'policy');
        $name = $this->name('referrer_policy', 'policy');
        echo '<select name="' . WpHelper::escapeAttribute($name) . '">';
        foreach (ReferrerPolicyHeader::VALID_POLICIES as $option) {
            printf(
                '<option value="%s"%s>%s</option>',
                WpHelper::escapeAttribute($option),
                $option === $value ? ' selected' : '',
                WpHelper::escapeHtml($option)
            );
        }
        echo '</select>';
    }

    public function renderPermissionsSectionIntro(): void
    {
        echo '<p>Opts the site out of browser features it does not use. Format: <code>feature=(allowlist)</code>, comma-separated. <code>()</code> denies everywhere.</p>';
    }

    public function renderPermissionsEnabled(): void
    {
        $this->renderCheckbox('permissions_policy', 'enabled');
    }

    public function renderPermissionsPolicy(): void
    {
        $value = (string) $this->valueOf('permissions_policy', 'policy');
        $name = $this->name('permissions_policy', 'policy');
        printf(
            '<textarea name="%s" rows="3" class="large-text code">%s</textarea>',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeTextarea($value)
        );
    }

    public function renderXctoSectionIntro(): void
    {
        echo '<p>Disables MIME sniffing. Safe to leave on; emits <code>X-Content-Type-Options: nosniff</code>.</p>';
    }

    public function renderXctoEnabled(): void
    {
        $this->renderCheckbox('x_content_type_options', 'enabled');
    }

    private function renderCheckbox(string $group, string $key, string $label = 'Enabled'): void
    {
        $checked = $this->valueOf($group, $key) ? ' checked' : '';
        $name = $this->name($group, $key);
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
            WpHelper::escapeAttribute($name),
            $checked,
            $label
        );
    }

    private function name(string $group, string $key): string
    {
        return sprintf('%s[%s][%s]', SecurityHeadersOptions::OPTION_NAME, $group, $key);
    }

    private function valueOf(string $group, string $key): mixed
    {
        if ($this->cachedOptions === null) {
            $this->cachedOptions = $this->options->all();
        }

        return $this->cachedOptions[$group][$key] ?? null;
    }

    /**
     * Resolves the top-level master `enabled` flag without going through
     * valueOf(), which is shaped for nested `[group][key]` paths.
     */
    private function masterValue(): bool
    {
        if ($this->cachedOptions === null) {
            $this->cachedOptions = $this->options->all();
        }

        return (bool) ($this->cachedOptions['enabled'] ?? true);
    }
}
