<?php

declare(strict_types=1);

namespace SecurePress\Admin;

use SecurePress\Core\Support\WpHelper;
use SecurePress\Core\UrlDisguise\UrlDisguiseOptions;
use SecurePress\Core\View\View;

/**
 * SecurePress → URL disguise: custom login (and optional admin) path slugs.
 *
 * Uses the Settings API; saves through {@see UrlDisguiseOptions::sanitize()}.
 * {@see \SecurePress\Core\UrlDisguise\UrlDisguiseModule} registers rewrites when
 * {@see UrlDisguiseOptions::isActive()} is true; changing these options flushes
 * rewrite rules via {@see Plugin::boot()}.
 */
final class UrlDisguiseSettingsPage
{
    public const PAGE_SLUG = 'securepress-url-disguise';

    public const OPTION_GROUP = 'securepress_url_disguise_group';

    public const SECTION = 'securepress_section_url_disguise';

    public function __construct(
        private readonly UrlDisguiseOptions $options,
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
            SecurePressMenuPage::PARENT_SLUG,
            'SecurePress URL disguise',
            'URL disguise',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        WpHelper::registerSetting(
            self::OPTION_GROUP,
            UrlDisguiseOptions::OPTION_NAME,
            ['sanitize_callback' => [$this->options, 'sanitize']]
        );

        WpHelper::addSettingsSection(
            self::SECTION,
            'Login & admin URLs',
            [$this, 'renderIntro'],
            self::PAGE_SLUG
        );

        WpHelper::addSettingsField('ud_enabled', 'Enable URL disguise', [$this, 'renderEnabled'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('ud_login_slug', 'Login URL slug', [$this, 'renderLoginSlug'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('ud_admin_slug', 'Admin URL slug (optional)', [$this, 'renderAdminSlug'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('ud_block_default', 'Block direct wp-login.php', [$this, 'renderBlockDefault'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('ud_block_default_admin', 'Block default wp-admin entry', [$this, 'renderBlockDefaultAdmin'], self::PAGE_SLUG, self::SECTION);
    }

    public function renderPage(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $this->view->render('admin.settings.url-disguise', [
            'pageSlug' => self::PAGE_SLUG,
            'optionGroup' => self::OPTION_GROUP,
            'masterEnabled' => $this->options->isEnabled(),
            'isActive' => $this->options->isActive(),
            'loginSlug' => $this->options->loginSlug(),
            'adminSlug' => $this->options->adminSlug(),
        ]);
    }

    public function renderIntro(): void
    {
        echo '<p>Map the WordPress login screen to a custom path (for example <code>https://example.com/<strong>my-login</strong>/</code>) instead of <code>wp-login.php</code>. You can optionally add a second slug for the admin PHP entry points; static assets under <code>/wp-admin/</code> stay on the real path for reliability.</p>';
        echo '<p><strong>Important:</strong> use a unique, non-guessable slug and bookmark it. After saving, visit <strong>Settings → Permalinks</strong> and click <strong>Save</strong> once if pretty permalinks were never flushed on this site.</p>';
    }

    public function renderEnabled(): void
    {
        $checked = $this->options->isEnabled() ? ' checked' : '';
        $name = UrlDisguiseOptions::OPTION_NAME . '[enabled]';
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> Enable custom login URL</label>',
            WpHelper::escapeAttribute($name),
            $checked
        );
    }

    public function renderLoginSlug(): void
    {
        $name = UrlDisguiseOptions::OPTION_NAME . '[login_slug]';
        $value = $this->options->loginSlug();
        printf(
            '<input type="text" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" placeholder="e.g. my-secret-login">'
            . '<p class="description">3–64 characters: lowercase letters, digits, and hyphens only. Must not match a reserved path (<code>wp-admin</code>, <code>wp-json</code>, etc.).</p>',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeAttribute($value)
        );
    }

    public function renderAdminSlug(): void
    {
        $name = UrlDisguiseOptions::OPTION_NAME . '[admin_slug]';
        $value = $this->options->adminSlug();
        printf(
            '<input type="text" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" placeholder="Leave empty to keep /wp-admin/">'
            . '<p class="description">Optional. If set, generated admin links use this prefix; non-PHP assets still load from <code>/wp-admin/</code>. Cannot match the login slug.</p>',
            WpHelper::escapeAttribute($name),
            WpHelper::escapeAttribute($value)
        );
    }

    public function renderBlockDefault(): void
    {
        $checked = $this->options->shouldBlockDefaultWpLogin() ? ' checked' : '';
        $name = UrlDisguiseOptions::OPTION_NAME . '[block_default_wp_login]';
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> Answer direct <code>wp-login.php</code> requests with <strong>404 Not Found</strong> (recommended)</label>'
            . '<p class="description">Does not redirect to your custom URL, so scanners cannot discover it from the default path. Turn off only if something must load <code>wp-login.php</code> by URL (rare).</p>',
            WpHelper::escapeAttribute($name),
            $checked
        );
    }

    public function renderBlockDefaultAdmin(): void
    {
        $checked = $this->options->shouldBlockDefaultWpAdmin() ? ' checked' : '';
        $name = UrlDisguiseOptions::OPTION_NAME . '[block_default_wp_admin]';
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> Answer <code>/wp-admin/</code> and <code>/wp-admin/index.php</code> with <strong>404 Not Found</strong> when an admin slug is set (recommended)</label>'
            . '<p class="description">Does not redirect to your custom admin prefix. Deeper URLs such as <code>admin-ajax.php</code> and static assets under <code>/wp-admin/</code> stay reachable so the dashboard works.</p>',
            WpHelper::escapeAttribute($name),
            $checked
        );
    }
}
