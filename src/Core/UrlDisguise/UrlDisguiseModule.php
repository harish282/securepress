<?php

declare(strict_types=1);

namespace SecurePress\Core\UrlDisguise;

use SecurePress\Core\Support\WpHelper;

/**
 * Registers rewrite rules, answers direct `wp-login.php` with HTTP 404 when
 * blocking is enabled (optional, avoids leaking the custom login URL), answers
 * direct default `wp-admin` entry with HTTP 404 when an admin slug is set and
 * blocking is enabled, loads the real login bootstrap on the custom slug, and
 * optionally proxies PHP entry points under a custom admin prefix.
 *
 * **Login** is implemented the same way as popular "hide login" plugins:
 * a top rewrite maps `/{login_slug}/` to `index.php?securepress_ud_login=1`,
 * then this module `require`s `wp-login.php` and exits.
 *
 * **Admin** is optional: when `admin_slug` is non-empty, rewrites map
 * `/{admin_slug}/…` to the same query-var bootstrap, which then `require`s
 * the matching file under `wp-admin/` for `*.php` requests. Static assets
 * (`.css`, `.js`, images) are redirected once to the real `/wp-admin/` URL so
 * we do not duplicate static file serving.
 *
 * After changing slugs or toggling the feature, WordPress rewrite rules must
 * be flushed — {@see \SecurePress\Core\Plugin} hooks `update_option_*` to
 * re-register rules and call {@see WpHelper::flushRewriteRules()}.
 */
final class UrlDisguiseModule
{
    public const QUERY_LOGIN = 'securepress_ud_login';

    public const QUERY_ADMIN = 'securepress_ud_admin';

    public const QUERY_ADMIN_PATH = 'securepress_ud_path';

    private static bool $loginBootstrapStarted = false;

    public function __construct(private readonly UrlDisguiseOptions $options)
    {
    }

    public function register(): void
    {
        WpHelper::addFilter('query_vars', [$this, 'registerQueryVars']);
        WpHelper::addAction('init', [$this, 'maybeBootstrapLoginFromRequestUri'], 1);
        WpHelper::addAction('init', [$this, 'addRewriteRules'], 0);
        WpHelper::addAction('init', [$this, 'maybeBlockDefaultWpLogin'], 3);
        WpHelper::addAction('init', [$this, 'maybeBlockDefaultWpAdmin'], 4);
        WpHelper::addFilter('pre_handle_404', [$this, 'filterPreHandle404'], 10, 2);
        WpHelper::addFilter('redirect_canonical', [$this, 'filterRedirectCanonical'], 0, 2);
        WpHelper::addFilter('auth_redirect_scheme', [$this, 'filterAuthRedirectScheme'], 10, 1);
        WpHelper::addAction('template_redirect', [$this, 'handleTemplateDisguise'], 0);

        WpHelper::addFilter('login_url', [$this, 'filterLoginUrl'], 10, 3);
        WpHelper::addFilter('logout_url', [$this, 'filterLogoutUrl'], 10, 2);
        WpHelper::addFilter('lostpassword_url', [$this, 'filterLostPasswordUrl'], 10, 2);
        WpHelper::addFilter('register_url', [$this, 'filterRegisterUrl'], 10, 1);
        WpHelper::addFilter('site_url', [$this, 'filterSiteUrl'], 10, 4);

        WpHelper::addFilter('admin_url', [$this, 'filterAdminUrl'], 10, 3);
        WpHelper::addFilter('network_admin_url', [$this, 'filterNetworkAdminUrl'], 10, 3);
    }

    /**
     * @param list<string>|string $vars
     * @return list<string>
     */
    public function registerQueryVars($vars): array
    {
        if (!is_array($vars)) {
            $vars = [];
        }
        $vars[] = self::QUERY_LOGIN;
        $vars[] = self::QUERY_ADMIN;
        $vars[] = self::QUERY_ADMIN_PATH;

        return $vars;
    }

    public function addRewriteRules(): void
    {
        if (!$this->options->isActive()) {
            return;
        }

        $login = $this->options->loginSlug();
        if ($login === '') {
            return;
        }

        $quoted = preg_quote($login, '#');
        WpHelper::addRewriteRule('^' . $quoted . '/?$', 'index.php?' . self::QUERY_LOGIN . '=1', 'top');

        $admin = $this->options->adminSlug();
        if ($admin !== '' && strcasecmp($admin, $login) !== 0) {
            $qa = preg_quote($admin, '#');
            WpHelper::addRewriteRule('^' . $qa . '/?$', 'index.php?' . self::QUERY_ADMIN . '=1&' . self::QUERY_ADMIN_PATH . '=index.php', 'top');
            WpHelper::addRewriteRule('^' . $qa . '/(.*)$', 'index.php?' . self::QUERY_ADMIN . '=1&' . self::QUERY_ADMIN_PATH . '=$matches[1]', 'top');
        }
    }

    /**
     * Loads `wp-login.php` on `init` (priority 1) when the raw request path matches
     * the login slug. Runs after {@see wp_functionality_constants()} (so constants
     * such as {@see AUTOSAVE_INTERVAL} exist for {@see wp-login.php}) and before
     * {@see wp()} / {@see \WP::main()} from {@see wp-blog-header.php}, so the main
     * query and theme 404 never run. Still safe if invoked again from
     * {@see handleTemplateDisguise()} thanks to {@see $loginBootstrapStarted}.
     */
    public function maybeBootstrapLoginFromRequestUri(): void
    {
        if (!$this->options->isActive()) {
            return;
        }
        $slug = $this->options->loginSlug();
        if ($slug === '' || !$this->requestPathMatchesLoginSlug($slug)) {
            return;
        }
        $this->bootstrapLogin();
    }

    public function handleTemplateDisguise(): void
    {
        if ($this->isDisguisedLoginRequest()) {
            $this->bootstrapLogin();

            return;
        }
        if (!$this->options->isActive()) {
            return;
        }
        if ((int) WpHelper::getQueryVar(self::QUERY_ADMIN) === 1) {
            $this->bootstrapAdmin();
        }
    }

    /**
     * Stops WordPress from issuing HTTP 404 for the disguised login path when
     * the main query has no posts — common for logout links with query args.
     *
     * @param mixed $preempt
     */
    public function filterPreHandle404(mixed $preempt, mixed $wpQuery): mixed
    {
        unset($wpQuery);

        if ($this->isDisguisedLoginRequest() || $this->isDisguisedAdminRequest()) {
            return true;
        }

        return $preempt;
    }

    /**
     * Prevents {@see redirect_canonical()} from stripping `action`, `_wpnonce`,
     * etc. from the disguised login URL (which would break logout).
     *
     * @param mixed $redirectUrl
     * @param mixed $requestedUrl
     * @return mixed
     */
    public function filterRedirectCanonical(mixed $redirectUrl, mixed $requestedUrl): mixed
    {
        unset($requestedUrl);
        if ($this->isDisguisedLoginRequest() || $this->isDisguisedAdminRequest()) {
            return false;
        }

        return $redirectUrl;
    }

    /**
     * Auth cookies for the admin area use {@see ADMIN_COOKIE_PATH} (typically
     * `/wp-admin`), so the browser does not send them on the disguised
     * admin prefix. {@see auth_redirect()} defaults to the auth cookie; use the
     * logged-in cookie (path is usually {@see COOKIEPATH} / site root) instead.
     *
     * @param mixed $scheme Empty string means pick auth vs secure_auth from SSL.
     * @return mixed
     */
    public function filterAuthRedirectScheme(mixed $scheme): mixed
    {
        if (is_string($scheme) && $scheme !== '') {
            return $scheme;
        }
        if (!$this->options->isActive() || $this->options->adminSlug() === '') {
            return $scheme;
        }
        if ($this->isDisguisedAdminRequest()) {
            return 'logged_in';
        }

        return $scheme;
    }

    /**
     * True when this request should load `wp-login.php` for the disguised slug.
     * Uses the raw {@see $_SERVER['REQUEST_URI']} path (relative to the site home
     * path) so it works even when {@see \WP::$request} is unset or rewrite rules are stale.
     */
    private function isDisguisedLoginRequest(): bool
    {
        if (!$this->options->isActive()) {
            return false;
        }
        $slug = $this->options->loginSlug();
        if ($slug === '') {
            return false;
        }
        if ((int) WpHelper::getQueryVar(self::QUERY_LOGIN) === 1) {
            return true;
        }

        return $this->requestPathMatchesLoginSlug($slug);
    }

    /**
     * True when the request targets the custom admin prefix (rewrite var or path).
     */
    private function isDisguisedAdminRequest(): bool
    {
        if (!$this->options->isActive()) {
            return false;
        }
        $admin = $this->options->adminSlug();
        if ($admin === '') {
            return false;
        }
        if ((int) WpHelper::getQueryVar(self::QUERY_ADMIN) === 1) {
            return true;
        }
        $rel = $this->getRequestPathRelativeToHome();
        $a = strtolower($admin);

        return $rel === $a || str_starts_with($rel, $a . '/');
    }

    /**
     * Path after stripping the `home_url` and (if different) `site_url` path
     * prefixes, then `index.php/`, lowercased, no leading slash.
     */
    private function getRequestPathRelativeToHome(): string
    {
        $rawPath = $this->rawRequestPathWithoutQuery();
        if ($rawPath === '') {
            return '';
        }
        $reqUri = rawurldecode($rawPath);
        $reqUri = trim($reqUri, '/');

        $homePathRaw = parse_url(WpHelper::homeUrl('/'), PHP_URL_PATH);
        $homePath = is_string($homePathRaw) ? trim($homePathRaw, '/') : '';
        if ($homePath !== '') {
            $pattern = '|^' . preg_quote($homePath, '|') . '|i';
            $reqUri = (string) preg_replace($pattern, '', $reqUri);
            $reqUri = trim($reqUri, '/');
        }

        $sitePathRaw = parse_url(WpHelper::siteUrl('/'), PHP_URL_PATH);
        $sitePath = is_string($sitePathRaw) ? trim($sitePathRaw, '/') : '';
        if ($sitePath !== '' && $sitePath !== $homePath) {
            if (str_starts_with($reqUri, $sitePath . '/')) {
                $reqUri = substr($reqUri, strlen($sitePath) + 1);
            } elseif ($reqUri === $sitePath) {
                $reqUri = '';
            }
            $reqUri = trim($reqUri, '/');
        }

        $index = 'index.php';
        if (isset($GLOBALS['wp_rewrite']) && is_object($GLOBALS['wp_rewrite'])) {
            $ix = $GLOBALS['wp_rewrite']->index ?? null;
            if (is_string($ix) && $ix !== '') {
                $index = $ix;
            }
        }
        if ($index !== '' && str_starts_with($reqUri, $index . '/')) {
            $reqUri = substr($reqUri, strlen($index) + 1);
        } elseif ($reqUri === $index) {
            $reqUri = '';
        }

        return strtolower(trim($reqUri, '/'));
    }

    /**
     * Path portion of the current HTTP request (no query string). Some stacks
     * leave {@see $_SERVER['REQUEST_URI']} empty but set {@see $_SERVER['REDIRECT_URL']}.
     */
    private function rawRequestPathWithoutQuery(): string
    {
        foreach (['REQUEST_URI', 'REDIRECT_URL', 'HTTP_X_ORIGINAL_URL'] as $key) {
            $v = $_SERVER[$key] ?? null;
            if (!is_string($v) || $v === '') {
                continue;
            }
            $path = explode('?', $v, 2)[0];
            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    private function requestPathMatchesLoginSlug(string $slug): bool
    {
        $rel = $this->getRequestPathRelativeToHome();

        return $rel !== '' && $rel === strtolower($slug);
    }

    private function bootstrapLogin(): void
    {
        if (!\defined('ABSPATH')) {
            return;
        }
        $loginPhp = ABSPATH . 'wp-login.php';
        if (!is_file($loginPhp) || !is_readable($loginPhp)) {
            return;
        }
        if (self::$loginBootstrapStarted) {
            return;
        }
        self::$loginBootstrapStarted = true;

        \do_action('securepress_url_disguise_before_wp_login');
        // Included `wp-login.php` runs in this method's scope; core omits these on plain GET.
        $user_login = '';
        $error = '';
        require_once $loginPhp;
        if (!\defined('SECUREPRESS_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }

    public function maybeBlockDefaultWpLogin(): void
    {
        if (!$this->options->isActive() || !$this->options->shouldBlockDefaultWpLogin()) {
            return;
        }
        if (WpHelper::isDoingAjax() || WpHelper::isRestRequest()) {
            return;
        }
        if (\defined('DOING_CRON') && \constant('DOING_CRON')) {
            return;
        }
        if (\defined('WP_CLI') && \constant('WP_CLI')) {
            return;
        }
        if (!$this->isRequestForWpLoginPhp()) {
            return;
        }

        $this->respondBlockedUrlNotFound();
    }

    public function maybeBlockDefaultWpAdmin(): void
    {
        if (!$this->options->isActive() || !$this->options->shouldBlockDefaultWpAdmin()) {
            return;
        }
        if ($this->options->adminSlug() === '') {
            return;
        }
        if (WpHelper::isDoingAjax() || WpHelper::isRestRequest()) {
            return;
        }
        if (\defined('DOING_CRON') && \constant('DOING_CRON')) {
            return;
        }
        if (\defined('WP_CLI') && \constant('WP_CLI')) {
            return;
        }
        if (!$this->isRequestForDefaultWpAdminEntry()) {
            return;
        }

        $this->respondBlockedUrlNotFound();
    }

    private function bootstrapAdmin(): void
    {
        if (!\defined('ABSPATH')) {
            return;
        }

        $rel = (string) WpHelper::getQueryVar(self::QUERY_ADMIN_PATH);
        $rel = str_replace('\\', '/', $rel);
        $rel = ltrim($rel, '/');
        if ($rel === '' || $rel === '0') {
            $rel = 'index.php';
        }
        if (str_contains($rel, '..')) {
            $this->respondNotFound();

            return;
        }

        $full = ABSPATH . 'wp-admin/' . $rel;
        if (!is_file($full) || !is_readable($full)) {
            $this->respondNotFound();

            return;
        }

        $realAdmin = realpath(\dirname(ABSPATH . 'wp-admin/index.php'));
        $realFile = realpath($full);
        if ($realAdmin === false || $realFile === false || !str_starts_with($realFile, $realAdmin)) {
            $this->respondNotFound();

            return;
        }

        if (!str_ends_with(strtolower($full), '.php')) {
            $public = WpHelper::siteUrl('wp-admin/' . $rel);
            WpHelper::safeRedirect($public);
            if (!\defined('SECUREPRESS_TESTING')) {
                exit; // @codeCoverageIgnore
            }

            return;
        }

        if (!\defined('WP_ADMIN')) {
            \define('WP_ADMIN', true);
        }
        // WooCommerce only pulls in `class-wc-admin.php` from `WC::includes()` when
        // `is_admin()` is true at bootstrap. On disguised admin we define `WP_ADMIN`
        // here (after `WC` has already constructed), so load the admin layer now so
        // helpers such as `wc_get_page_screen_id()` exist before `admin_init`.
        $this->loadWooCommerceAdminLayerIfMissing();
        // Included wp-admin scripts run in this method's scope. `extract()` only
        // affects the function it runs in — must be here, not in a helper, so
        // admin.php / upgrade.php see $wp_db_version et al.
        $wpVersionSubset = \array_intersect_key(
            $GLOBALS,
            \array_flip([
                'wp_version',
                'wp_db_version',
                'tinymce_version',
                'required_php_version',
                'required_php_extensions',
                'required_mysql_version',
            ])
        );
        \extract($wpVersionSubset, \EXTR_SKIP);
        $this->bindWpAdminMenuGlobalsAsReferences();
        \do_action('securepress_url_disguise_before_wp_admin', $rel);
        require $full;
        if (!\defined('SECUREPRESS_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }

    /**
     * Ensures WooCommerce admin helpers are loaded when this request only became an
     * admin context at {@see bootstrapAdmin()} time (after WooCommerce bootstrapped
     * as a front-end request).
     */
    private function loadWooCommerceAdminLayerIfMissing(): void
    {
        if (!\function_exists('WC') || \function_exists('wc_get_page_screen_id')) {
            return;
        }
        if (\class_exists('WC_Admin', false)) {
            $path = \WC()->plugin_path() . '/includes/admin/wc-admin-functions.php';

            if (\is_readable($path)) {
                require_once $path;
            }

            return;
        }
        $adminFile = \WC()->plugin_path() . '/includes/admin/class-wc-admin.php';
        if (!\is_readable($adminFile)) {
            return;
        }
        $wcAdmin = require $adminFile;
        if (\is_object($wcAdmin) && \method_exists($wcAdmin, 'includes')) {
            $wcAdmin->includes();
        }
        $legacy = \dirname($adminFile) . '/woocommerce-legacy-reports.php';
        if (\is_readable($legacy)) {
            include_once $legacy;
        }
    }

    /**
     * wp-admin/menu.php and includes/menu.php assign $menu and $_wp_* without
     * `global`; functions such as {@see user_can_access_admin_page()} read
     * `global $menu`. Aliasing onto $GLOBALS keeps both in sync when those
     * files run from this method's scope.
     */
    private function bindWpAdminMenuGlobalsAsReferences(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $GLOBALS['_wp_submenu_nopriv'] = [];
        $GLOBALS['_wp_menu_nopriv'] = [];
        $GLOBALS['admin_page_hooks'] = [];
        $GLOBALS['_wp_real_parent_file'] = [];
        $GLOBALS['_registered_pages'] = [];
        $GLOBALS['_parent_pages'] = [];
        $GLOBALS['menu_order'] = [];
        $GLOBALS['default_menu_order'] = [];

        $menu = &$GLOBALS['menu'];
        $submenu = &$GLOBALS['submenu'];
        $_wp_submenu_nopriv = &$GLOBALS['_wp_submenu_nopriv'];
        $_wp_menu_nopriv = &$GLOBALS['_wp_menu_nopriv'];
        $admin_page_hooks = &$GLOBALS['admin_page_hooks'];
        $_wp_real_parent_file = &$GLOBALS['_wp_real_parent_file'];
        $_registered_pages = &$GLOBALS['_registered_pages'];
        $_parent_pages = &$GLOBALS['_parent_pages'];
        $menu_order = &$GLOBALS['menu_order'];
        $default_menu_order = &$GLOBALS['default_menu_order'];
    }

    public function filterLoginUrl(string $loginUrl, string $redirect, bool $forceHttps): string
    {
        unset($forceHttps);
        if (!$this->options->isActive()) {
            return $loginUrl;
        }
        $query = $this->parseQueryFromUrl($loginUrl);
        if ($redirect !== '') {
            $query['redirect_to'] = $redirect;
        }

        return $this->loginUrlWithQuery($query);
    }

    public function filterLogoutUrl(string $logoutUrl, string $redirect): string
    {
        if (!$this->options->isActive()) {
            return $logoutUrl;
        }
        $query = $this->parseQueryFromUrl($logoutUrl);
        if ($redirect !== '') {
            $query['redirect_to'] = $redirect;
        }

        return $this->loginUrlWithQuery($query);
    }

    public function filterLostPasswordUrl(string $lostpasswordUrl, string $redirect): string
    {
        unset($redirect);
        if (!$this->options->isActive()) {
            return $lostpasswordUrl;
        }

        return $this->loginUrlWithQuery($this->parseQueryFromUrl($lostpasswordUrl));
    }

    public function filterRegisterUrl(string $registerUrl): string
    {
        if (!$this->options->isActive()) {
            return $registerUrl;
        }

        return $this->loginUrlWithQuery($this->parseQueryFromUrl($registerUrl));
    }

    /**
     * Rewrites `site_url( 'wp-login.php?…' )` style URLs (e.g. logout links) to
     * the disguised login path while preserving the query string.
     *
     * @param mixed $scheme
     * @param mixed $blogId
     */
    public function filterSiteUrl(string $url, string $path, mixed $scheme = null, mixed $blogId = null): string
    {
        unset($scheme, $blogId);
        if (!$this->options->isActive()) {
            return $url;
        }
        $norm = ltrim((string) $path, '/');
        if ($norm === '' || !str_starts_with($norm, 'wp-login.php')) {
            return $url;
        }
        $query = [];
        if (str_contains($norm, '?')) {
            $q = substr($norm, (int) strpos($norm, '?') + 1);
            parse_str($q, $query);
        } else {
            $query = $this->parseQueryFromUrl($url);
        }

        return $this->loginUrlWithQuery($query);
    }

    public function filterAdminUrl(string $url, string $path, ?int $blogId): string
    {
        unset($blogId);
        if (!$this->options->isActive()) {
            return $url;
        }
        $slug = $this->options->adminSlug();
        if ($slug === '') {
            return $url;
        }

        return $this->replaceWpAdminSegment($url, $slug);
    }

    public function filterNetworkAdminUrl(string $url, string $path, ?int $blogId): string
    {
        return $this->filterAdminUrl($url, $path, $blogId);
    }

    private function replaceWpAdminSegment(string $url, string $slug): string
    {
        $parts = parse_url($url);
        if (!isset($parts['path']) || !is_string($parts['path'])) {
            return $url;
        }
        $path = $parts['path'];
        $needle = '/wp-admin';
        $pos = strpos($path, $needle);
        if ($pos === false) {
            return $url;
        }
        $newPath = substr_replace($path, '/' . $slug, $pos, strlen($needle));
        $parts['path'] = $newPath;

        return $this->buildUrlFromParts($parts);
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function buildUrlFromParts(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $frag = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        return $scheme . $host . $port . $path . $query . $frag;
    }

    /**
     * Query args extracted from a URL string. Decodes HTML entities first
     * because {@see wp_nonce_url()} wraps URLs with {@see esc_html()}, so the
     * `logout_url` filter receives `&amp;` between parameters — parsing without
     * decoding drops `_wpnonce` and breaks logout.
     *
     * @return array<string, mixed>
     */
    private function parseQueryFromUrl(string $url): array
    {
        if (\function_exists('wp_specialchars_decode')) {
            $url = (string) \wp_specialchars_decode($url, ENT_QUOTES);
        } else {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $parts = parse_url($url);
        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function loginUrlWithQuery(array $query): string
    {
        $base = rtrim(WpHelper::homeUrl('/' . $this->options->loginSlug() . '/'), '/') . '/';
        if ($query === []) {
            return $base;
        }

        return $base . '?' . http_build_query($query);
    }

    /**
     * True when the request targets only the default wp-admin directory entry
     * (root, trailing slash, or index.php), not deep paths such as admin-ajax.php
     * or static assets under /wp-admin/.
     */
    private function isRequestForDefaultWpAdminEntry(): bool
    {
        $uri = WpHelper::requestUri();
        if ($uri === null) {
            return false;
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        return (bool) preg_match('#/wp-admin(/index\.php)?/?$#i', $path);
    }

    private function isRequestForWpLoginPhp(): bool
    {
        $uri = WpHelper::requestUri();
        if ($uri === null) {
            return false;
        }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        return str_ends_with($path, '/wp-login.php') || str_ends_with($path, 'wp-login.php');
    }

    private function respondNotFound(): void
    {
        if (!\headers_sent()) {
            WpHelper::statusHeader(404);
        }
        if (!\defined('SECUREPRESS_TESTING')) {
            exit; // @codeCoverageIgnore
        }
    }

    /**
     * HTTP 404 for blocked default WordPress URLs so custom slugs are not leaked
     * via a redirect Location header.
     */
    private function respondBlockedUrlNotFound(): void
    {
        if (!\headers_sent()) {
            if (\function_exists('nocache_headers')) {
                \nocache_headers();
            }
            WpHelper::statusHeader(404);
            \header('Content-Type: text/html; charset=UTF-8');
        }
        if (!\defined('SECUREPRESS_TESTING')) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not Found</title></head>'
                . '<body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>';
            exit; // @codeCoverageIgnore
        }
    }
}
