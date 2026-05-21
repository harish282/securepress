<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

/**
 * Severity buckets attached to every {@see Finding}.
 *
 * Used to drive default sort order in the admin UI (`critical` first) and to gate
 * "send me an email" notifications — operators usually only want to know about
 * `critical` / `high` immediately and review the rest weekly.
 *
 * The order intentionally mirrors PSR-3 logging levels so callers can map findings
 * straight into the audit/file logger without translation.
 */
final class FindingSeverity
{
    public const INFO = 'info';
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    /**
     * @return list<string> Levels from least to most severe.
     */
    public static function all(): array
    {
        return [self::INFO, self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];
    }

    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::all(), true);
    }

    /**
     * Numeric weight (0..4) so callers can compare severities without bespoke maps.
     */
    public static function weight(string $severity): int
    {
        return array_search($severity, self::all(), true) === false
            ? 0
            : (int) array_search($severity, self::all(), true);
    }

    public static function atLeast(string $candidate, string $threshold): bool
    {
        return self::weight($candidate) >= self::weight($threshold);
    }
}
