<?php

declare(strict_types=1);

namespace PressSentinel\Core\UrlDisguise;

use PressSentinel\Core\Support\WpHelper;

/**
 * Registers rewrite rules for a custom login path, optionally answers direct
 * `wp-login.php` with HTTP 404 when blocking is enabled, and loads the real
 * login bootstrap on the custom slug.
 *
 * A top rewrite maps `/{login_slug}/` to `index.php?presssentinel_ud_login=1`,
 * then this module `require`s `wp-login.php` and exits.
 *
 * After changing the slug or toggling the feature, WordPress rewrite rules must
 * be flushed — {@see \PressSentinel\Core\Plugin} hooks `update_option_*` to
 * re-register rules and call {@see WpHelper::flushRewriteRules()}.
 */
final class UrlDisguiseModule
{
    public const QUERY_LOGIN = 'presssentinel_ud_login';

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
        WpHelper::addFilter('pre_handle_404', [$this, 'filterPreHandle404'], 10, 2);
        WpHelper::addFilter('redirect_canonical', [$this, 'filterRedirectCanonical'], 0, 2);
        WpHelper::addAction('template_redirect', [$this, 'handleTemplateDisguise'], 0);

        WpHelper::addFilter('login_url', [$this, 'filterLoginUrl'], 10, 3);
        WpHelper::addFilter('logout_url', [$this, 'filterLogoutUrl'], 10, 2);
        WpHelper::addFilter('lostpassword_url', [$this, 'filterLostPasswordUrl'], 10, 2);
        WpHelper::addFilter('register_url', [$this, 'filterRegisterUrl'], 10, 1);
        WpHelper::addFilter('site_url', [$this, 'filterSiteUrl'], 10, 4);
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

        if ($this->isDisguisedLoginRequest()) {
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
        if ($this->isDisguisedLoginRequest()) {
            return false;
        }

        return $redirectUrl;
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

        $homePathRaw = WpHelper::parseUrl(WpHelper::homeUrl('/'), PHP_URL_PATH);
        $homePath = is_string($homePathRaw) ? trim($homePathRaw, '/') : '';
        if ($homePath !== '') {
            $pattern = '|^' . preg_quote($homePath, '|') . '|i';
            $reqUri = (string) preg_replace($pattern, '', $reqUri);
            $reqUri = trim($reqUri, '/');
        }

        $sitePathRaw = WpHelper::parseUrl(WpHelper::siteUrl('/'), PHP_URL_PATH);
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

        \do_action('presssentinel_url_disguise_before_wp_login');
        // Included `wp-login.php` runs in this method's scope; core omits these on plain GET.
        $user_login = '';
        $error = '';
        require_once $loginPhp;
        if (!\defined('PRESS_SENTINEL_TESTING')) {
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
        $parts = WpHelper::parseUrl($url);
        $query = [];
        if (is_array($parts) && isset($parts['query']) && is_string($parts['query'])) {
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

    private function isRequestForWpLoginPhp(): bool
    {
        $uri = WpHelper::requestUri();
        if ($uri === null) {
            return false;
        }
        $path = WpHelper::parseUrl($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }

        return str_ends_with($path, '/wp-login.php') || str_ends_with($path, 'wp-login.php');
    }

    private function respondBlockedUrlNotFound(): void
    {
        if (!\headers_sent()) {
            if (\function_exists('nocache_headers')) {
                \nocache_headers();
            }
            WpHelper::statusHeader(404);
            \header('Content-Type: text/html; charset=UTF-8');
        }
        if (!\defined('PRESS_SENTINEL_TESTING')) {
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Not Found</title></head>'
                . '<body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>';
            exit; // @codeCoverageIgnore
        }
    }
}
