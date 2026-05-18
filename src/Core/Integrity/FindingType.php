<?php

declare(strict_types=1);

namespace PressSentinel\Core\Integrity;

/**
 * The set of finding categories the integrity scanner can produce.
 *
 * A "finding" is anything worth showing on the admin dashboard:
 *  - manifest-diff findings ({@see FILE_ADDED} / {@see FILE_MODIFIED} / {@see FILE_DELETED})
 *    surface when the current filesystem disagrees with the stored baseline;
 *  - checksum-diff findings ({@see CORE_TAMPERED}) surface when a core file's content
 *    no longer matches the official WP.org checksum for the running WordPress version;
 *  - heuristic findings ({@see SUSPICIOUS_PHP} / {@see PHP_IN_UPLOADS} /
 *    {@see DOUBLE_EXTENSION} / {@see WEBSHELL_SIGNATURE}) surface when one of the
 *    {@see Heuristics\HeuristicInterface}s matches a PHP file under a scanned root.
 *
 * Kept as a plain class with string constants (not a native enum) so persisted values
 * stay stable through PHP enum API changes and remain `array<string, …>` JSON-friendly
 * for context payloads.
 */
final class FindingType
{
    public const FILE_ADDED = 'file_added';
    public const FILE_MODIFIED = 'file_modified';
    public const FILE_DELETED = 'file_deleted';
    public const CORE_TAMPERED = 'core_tampered';
    public const SUSPICIOUS_PHP = 'suspicious_php';
    public const PHP_IN_UPLOADS = 'php_in_uploads';
    public const DOUBLE_EXTENSION = 'double_extension';
    public const WEBSHELL_SIGNATURE = 'webshell_signature';
    public const PLUGIN_ADDED = 'plugin_added';
    public const PLUGIN_REMOVED = 'plugin_removed';
    public const PLUGIN_CHANGED = 'plugin_changed';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FILE_ADDED,
            self::FILE_MODIFIED,
            self::FILE_DELETED,
            self::CORE_TAMPERED,
            self::SUSPICIOUS_PHP,
            self::PHP_IN_UPLOADS,
            self::DOUBLE_EXTENSION,
            self::WEBSHELL_SIGNATURE,
            self::PLUGIN_ADDED,
            self::PLUGIN_REMOVED,
            self::PLUGIN_CHANGED,
        ];
    }

    public static function isValid(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    public static function label(string $type): string
    {
        return match ($type) {
            self::FILE_ADDED => 'New file',
            self::FILE_MODIFIED => 'Modified file',
            self::FILE_DELETED => 'Deleted file',
            self::CORE_TAMPERED => 'Core file tampered',
            self::SUSPICIOUS_PHP => 'Suspicious PHP',
            self::PHP_IN_UPLOADS => 'PHP file in uploads',
            self::DOUBLE_EXTENSION => 'Double-extension file',
            self::WEBSHELL_SIGNATURE => 'Webshell signature',
            self::PLUGIN_ADDED => 'New plugin',
            self::PLUGIN_REMOVED => 'Plugin removed',
            self::PLUGIN_CHANGED => 'Plugin changed',
            default => $type,
        };
    }
}
