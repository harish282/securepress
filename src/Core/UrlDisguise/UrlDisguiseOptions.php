<?php

declare(strict_types=1);

namespace SecurePress\Core\UrlDisguise;

use SecurePress\Core\Config\Config;
use SecurePress\Core\Support\WpHelper;

/**
 * Configuration for disguising the default WordPress login and (optionally)
 * admin URLs behind custom path slugs.
 *
 * Storage: single autoloaded option {@see OPTION_NAME}, merged on top of
 * `config/plugin.php` (`url_disguise.*`). The Settings API save path calls
 * {@see sanitize()} which merges with the current effective values so
 * partial form posts cannot reset unrelated keys.
 */
final class UrlDisguiseOptions
{
    public const OPTION_NAME = 'securepress_url_disguise';

    /** @var list<string> */
    public const RESERVED_SLUGS = [
        'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json',
        'feed', 'page', 'comments', 'search', 'author', 'category', 'tag',
        'securepress', 'admin', 'login', 'xmlrpc', 'robots',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{enabled: bool, login_slug: string, admin_slug: string, block_default_wp_login: bool, block_default_wp_admin: bool}
     */
    public function all(): array
    {
        $defaults = $this->normalize(
            is_array($this->config->get('url_disguise')) ? $this->config->get('url_disguise') : []
        );

        $stored = WpHelper::getOption(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return $this->normalize(array_replace($defaults, $stored));
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->all()['enabled'] ?? false);
    }

    /**
     * True when rewrite rules and request handlers should register. Requires
     * a non-empty login slug so we never register a catch-all with an empty
     * pattern.
     */
    public function isActive(): bool
    {
        $a = $this->all();

        return $a['enabled'] && $a['login_slug'] !== '';
    }

    public function loginSlug(): string
    {
        return $this->all()['login_slug'];
    }

    public function adminSlug(): string
    {
        return $this->all()['admin_slug'];
    }

    /**
     * When URL disguise is active and this is true, requests for the real
     * wp-login.php URL receive HTTP 404 (no redirect to the custom slug).
     */
    public function shouldBlockDefaultWpLogin(): bool
    {
        return (bool) ($this->all()['block_default_wp_login'] ?? true);
    }

    /**
     * When an admin URL slug is set and disguise is active, direct hits to the
     * default wp-admin entry (root or index.php) get HTTP 404 (no redirect).
     */
    public function shouldBlockDefaultWpAdmin(): bool
    {
        return (bool) ($this->all()['block_default_wp_admin'] ?? true);
    }

    public function setEnabled(bool $enabled): void
    {
        $current = $this->all();
        $current['enabled'] = $enabled;
        WpHelper::updateOption(self::OPTION_NAME, $current);
    }

    /**
     * @param mixed $input
     * @return array{enabled: bool, login_slug: string, admin_slug: string, block_default_wp_login: bool, block_default_wp_admin: bool}
     */
    public function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        return $this->normalize(array_replace($this->all(), $input));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, login_slug: string, admin_slug: string, block_default_wp_login: bool, block_default_wp_admin: bool}
     */
    private function normalize(array $raw): array
    {
        $login = $this->normalizeSlug($raw['login_slug'] ?? '');
        $admin = $this->normalizeSlug($raw['admin_slug'] ?? '');

        if ($login !== '' && $admin !== '' && strcasecmp($login, $admin) === 0) {
            $admin = '';
        }

        return [
            'enabled' => $this->toBool($raw['enabled'] ?? false),
            'login_slug' => $login,
            'admin_slug' => $admin,
            'block_default_wp_login' => $this->toBool($raw['block_default_wp_login'] ?? true),
            'block_default_wp_admin' => $this->toBool($raw['block_default_wp_admin'] ?? true),
        ];
    }

    /**
     * Lowercase letters, digits, hyphen only; 3–64 chars; not reserved.
     */
    public function normalizeSlug(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $s = strtolower(trim($value));
        if ($s === '' || strlen($s) < 3 || strlen($s) > 64) {
            return '';
        }
        if (!preg_match('/^[a-z0-9-]+$/', $s)) {
            return '';
        }
        foreach (self::RESERVED_SLUGS as $reserved) {
            if ($s === $reserved) {
                return '';
            }
        }

        return $s;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
