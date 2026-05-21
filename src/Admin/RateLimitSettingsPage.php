<?php

declare(strict_types=1);

namespace PressSentinel\Admin;

use PressSentinel\Core\RateLimit\RateLimitOptions;
use PressSentinel\Core\Support\WpHelper;
use PressSentinel\Core\View\View;

/**
 * Settings → PressSentinel Rate Limiting admin page.
 *
 * Lets a site admin turn the global rate-limit middleware on/off and tune
 * its limit + window values without editing `config/plugin.php`. Mirrors
 * {@see AuthHardeningSettingsPage} / {@see SecurityHeadersSettingsPage} so
 * the admin UX is consistent across modules.
 *
 * Implementation notes:
 *  - Uses the standard WordPress Settings API for nonce + capability
 *    handling. POSTs go to `options.php` which calls
 *    {@see RateLimitOptions::sanitize()}, so the storage shape is always
 *    canonical and bounds-checked.
 *  - The page is a thin shell — form scaffolding lives in
 *    `resources/views/admin/settings/rate-limit.php`.
 *  - The same `enabled` flag is mirrored on the PressSentinel dashboard's
 *    feature-toggle form. Both surfaces read/write through `RateLimitOptions`
 *    so they never drift apart.
 */
final class RateLimitSettingsPage
{
    public const PAGE_SLUG = 'presssentinel-rate-limit';

    public const OPTION_GROUP = 'presssentinel_rate_limit_group';

    /**
     * Single section: there are only three fields and they're all part of
     * one logical concern. Splitting would be ceremonial.
     */
    public const SECTION = 'presssentinel_section_rate_limit';

    /** @var array{enabled: bool, limit: int, window: int}|null */
    private ?array $cachedOptions = null;

    public function __construct(
        private readonly RateLimitOptions $options,
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
            'PressSentinel Rate Limiting',
            'Rate Limiting',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        WpHelper::registerSetting(
            self::OPTION_GROUP,
            RateLimitOptions::OPTION_NAME,
            ['sanitize_callback' => [$this->options, 'sanitize']]
        );

        WpHelper::addSettingsSection(
            self::SECTION,
            'Rate Limiting',
            [$this, 'renderIntro'],
            self::PAGE_SLUG
        );

        WpHelper::addSettingsField('rl_enabled', 'Enable rate limiting', [$this, 'renderEnabled'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('rl_limit', 'Request limit', [$this, 'renderLimit'], self::PAGE_SLUG, self::SECTION);
        WpHelper::addSettingsField('rl_window', 'Window (seconds)', [$this, 'renderWindow'], self::PAGE_SLUG, self::SECTION);
    }

    public function renderPage(): void
    {
        if (!WpHelper::currentUserCan('manage_options')) {
            return;
        }

        $this->cachedOptions = null; // ensure a fresh read after a save

        $this->view->render('admin.settings.rate-limit', [
            'pageSlug' => self::PAGE_SLUG,
            'optionGroup' => self::OPTION_GROUP,
            'masterEnabled' => $this->options->isEnabled(),
        ]);
    }

    public function renderIntro(): void
    {
        echo '<p>Caps how many requests a single client may make in a fixed window. Authenticated users are bucketed by user id; anonymous clients by IP. When the cap is exceeded, PressSentinel responds with HTTP <code>429 Too Many Requests</code> and emits standard <code>Retry-After</code> / <code>X-RateLimit-*</code> headers.</p>';
        echo '<p>This is a <strong>global</strong> middleware. Per-route limits set via the <code>Security::rateLimit()</code> SDK still apply on top of this.</p>';
    }

    public function renderEnabled(): void
    {
        $checked = $this->valueOf('enabled') ? ' checked' : '';
        $name = $this->name('enabled');
        printf(
            '<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1"%2$s> Apply the global rate limit on every request</label>',
            esc_attr($name),
            esc_attr($checked)
        );
    }

    public function renderLimit(): void
    {
        $value = (int) $this->valueOf('limit');
        $name = $this->name('limit');
        $limitMin = (int) RateLimitOptions::LIMIT_MIN;
        $limitMax = (int) RateLimitOptions::LIMIT_MAX;
        printf(
            '<input type="number" name="%s" value="%s" min="%s" max="%s" class="small-text"> <span class="description">Requests allowed per window, per bucket. Range: %s&ndash;%s.</span>',
            esc_attr($name),
            esc_attr((string) $value),
            esc_attr((string) $limitMin),
            esc_attr((string) $limitMax),
            esc_html((string) $limitMin),
            esc_html((string) $limitMax)
        );
    }

    public function renderWindow(): void
    {
        $value = (int) $this->valueOf('window');
        $name = $this->name('window');
        $windowMin = (int) RateLimitOptions::WINDOW_MIN;
        $windowMax = (int) RateLimitOptions::WINDOW_MAX;
        printf(
            '<input type="number" name="%s" value="%s" min="%s" max="%s" class="small-text"> <span class="description">Seconds. Common: 60 (per minute), 3600 (per hour). Range: %s&ndash;%s.</span>',
            esc_attr($name),
            esc_attr((string) $value),
            esc_attr((string) $windowMin),
            esc_attr((string) $windowMax),
            esc_html((string) $windowMin),
            esc_html((string) $windowMax)
        );
    }

    private function name(string $key): string
    {
        return RateLimitOptions::OPTION_NAME . '[' . $key . ']';
    }

    private function valueOf(string $key): mixed
    {
        if ($this->cachedOptions === null) {
            $this->cachedOptions = $this->options->all();
        }

        return $this->cachedOptions[$key] ?? null;
    }
}
