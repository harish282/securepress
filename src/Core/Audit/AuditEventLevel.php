<?php

declare(strict_types=1);

namespace NiyiGuard\Core\Audit;

/**
 * PSR-3 compatible severity levels for audit events.
 *
 * Stored as plain strings (not a backed enum) for forward-compat with PHP < 8.1 fixtures
 * and easier WP-DB persistence. The static `all()` method returns the canonical ordered
 * list (most-severe first), which the admin UI uses to populate the level filter.
 */
final class AuditEventLevel
{
    public const EMERGENCY = 'emergency';
    public const ALERT = 'alert';
    public const CRITICAL = 'critical';
    public const ERROR = 'error';
    public const WARNING = 'warning';
    public const NOTICE = 'notice';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::EMERGENCY,
            self::ALERT,
            self::CRITICAL,
            self::ERROR,
            self::WARNING,
            self::NOTICE,
            self::INFO,
            self::DEBUG,
        ];
    }

    public static function isValid(string $level): bool
    {
        return in_array($level, self::all(), true);
    }

    public static function normalize(string $level, string $fallback = self::INFO): string
    {
        $candidate = strtolower(trim($level));

        return self::isValid($candidate) ? $candidate : $fallback;
    }

    /**
     * PSR-3-style numeric rank (higher = more severe). Used to compare whether an
     * event meets the configured minimum level for database storage.
     */
    public static function severityRank(string $level): int
    {
        return match (self::normalize($level)) {
            self::EMERGENCY => 800,
            self::ALERT => 700,
            self::CRITICAL => 600,
            self::ERROR => 500,
            self::WARNING => 400,
            self::NOTICE => 300,
            self::INFO => 200,
            self::DEBUG => 100,
            default => 200,
        };
    }

    /**
     * True when `$level` is at least as severe as `$minimum` (e.g. `error` meets `warning`).
     */
    public static function isAtLeast(string $level, string $minimum): bool
    {
        return self::severityRank($level) >= self::severityRank($minimum);
    }
}
