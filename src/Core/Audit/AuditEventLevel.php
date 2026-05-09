<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit;

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
}
