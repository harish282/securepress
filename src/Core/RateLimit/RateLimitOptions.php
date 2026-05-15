<?php

declare(strict_types=1);

namespace SecurePress\Core\RateLimit;

use SecurePress\Core\Config\Config;
use SecurePress\Core\Recovery\SafeMode;
use SecurePress\Core\Support\WpHelper;

/**
 * Resolves the effective rate-limit configuration for the current request.
 *
 * Defaults come from `config/plugin.php` (`rate_limit.*`); the admin Settings
 * page persists overrides into the `wp_option` named by {@see self::OPTION_NAME}.
 * This class reads both and returns a fully-merged, type-coerced array so every
 * consumer (the global middleware binding in `Plugin.php`, the FeatureRegistry,
 * the dedicated settings page) sees the same canonical shape.
 *
 * Why route through one option instead of one-per-toggle:
 *  - Single autoloaded read on boot rather than three separate `get_option`
 *    calls.
 *  - Atomic save semantics — partial writes can't leave the system in an
 *    inconsistent "enabled but limit=0" state.
 *  - Mirrors the {@see \SecurePress\Core\Auth\AuthHardeningOptions} contract
 *    so the settings-page glue is familiar.
 *
 * Storage shape (single autoloaded option):
 *
 *     [
 *         'enabled' => bool,
 *         'limit'   => int,   // 1..100_000 requests per window
 *         'window'  => int,   // 1..86_400 seconds
 *     ]
 *
 * Bounds are wide enough to allow both "API gateway" tuning (e.g. 1000/min)
 * and "lock down everything" tuning (e.g. 2/60) without anything pathological
 * (zero divides, negative windows) ever reaching the limiter.
 */
final class RateLimitOptions
{
    public const OPTION_NAME = 'securepress_rate_limit';

    /** Hard limit bounds — `limit` is requests per window. */
    public const LIMIT_MIN = 1;

    public const LIMIT_MAX = 100_000;

    /** Hard window bounds — seconds. 1 = 1 second; 86_400 = 24 hours. */
    public const WINDOW_MIN = 1;

    public const WINDOW_MAX = 86_400;

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @return array{enabled: bool, limit: int, window: int}
     */
    public function all(): array
    {
        $defaults = $this->normalize(
            is_array($this->config->get('rate_limit')) ? $this->config->get('rate_limit') : []
        );

        $stored = WpHelper::getOption(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            return $defaults;
        }

        return $this->normalize(array_replace($defaults, $stored));
    }

    public function isEnabled(): bool
    {
        if (SafeMode::bypasses(SafeMode::BYPASS_RATE_LIMIT)) {
            return false;
        }

        return $this->all()['enabled'];
    }

    public function limit(): int
    {
        return $this->all()['limit'];
    }

    public function window(): int
    {
        return $this->all()['window'];
    }

    /**
     * Flips just the master `enabled` flag, preserving the configured limit
     * and window. Used by the SecurePress dashboard's feature-toggle form so
     * the admin doesn't have to dig into the dedicated settings page to
     * temporarily disable the limiter.
     */
    public function setEnabled(bool $enabled): void
    {
        $current = $this->all();
        $current['enabled'] = $enabled;
        WpHelper::updateOption(self::OPTION_NAME, $current);
    }

    /**
     * Sanitises the raw POST payload from the Settings page into the
     * canonical shape. Used as the `register_setting()` sanitize callback.
     *
     * The currently-stored values are read first and the submitted fields
     * are layered on top — so a future form that posts only a subset (e.g.
     * the dashboard's master-only toggle path) cannot silently reset the
     * other fields to their config defaults.
     *
     * @param mixed $input
     * @return array{enabled: bool, limit: int, window: int}
     */
    public function sanitize(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }
        $current = $this->all();
        $merged = array_replace($current, $input);

        return $this->normalize($merged);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, limit: int, window: int}
     */
    private function normalize(array $raw): array
    {
        return [
            'enabled' => $this->toBool($raw['enabled'] ?? true),
            'limit' => $this->clampInt($raw['limit'] ?? 60, self::LIMIT_MIN, self::LIMIT_MAX),
            'window' => $this->clampInt($raw['window'] ?? 60, self::WINDOW_MIN, self::WINDOW_MAX),
        ];
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

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $candidate = is_numeric($value) ? (int) $value : $min;

        return max($min, min($max, $candidate));
    }
}
