<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

/**
 * Logical area of the filesystem a finding / manifest entry belongs to.
 *
 * Scopes drive both the storage partitioning (rows in the baseline table are scoped so a
 * core re-scan doesn't trip findings against the plugin manifest) and the admin UI
 * grouping. New scopes can be added without schema changes — the column is a varchar.
 */
final class IntegrityScope
{
    public const CORE = 'core';
    public const PLUGINS = 'plugins';
    public const THEMES = 'themes';
    public const UPLOADS = 'uploads';
    public const CUSTOM = 'custom';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CORE, self::PLUGINS, self::THEMES, self::UPLOADS, self::CUSTOM];
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    public static function label(string $scope): string
    {
        return match ($scope) {
            self::CORE => 'WordPress core',
            self::PLUGINS => 'Plugins',
            self::THEMES => 'Themes',
            self::UPLOADS => 'Uploads',
            self::CUSTOM => 'Custom path',
            default => $scope,
        };
    }
}
