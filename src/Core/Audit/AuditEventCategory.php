<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit;

/**
 * Logical grouping for audit events.
 *
 * Categories are intentionally coarse — one per "subsystem" — so the admin UI can offer a
 * tractable filter dropdown. Use the action string for finer-grained taxonomy
 * (e.g. category=`auth`, action=`user.login.failed`).
 *
 * `OTHER` is the fallback for events that don't naturally fit one of the named buckets;
 * developers writing application-specific events can either use `OTHER` or a custom string
 * (the repository accepts any non-empty string up to 40 chars).
 */
final class AuditEventCategory
{
    public const AUTH = 'auth';
    public const PLUGIN = 'plugin';
    public const THEME = 'theme';
    public const USER = 'user';
    public const ROLE = 'role';
    public const OPTIONS = 'options';
    public const FILE_EDITOR = 'file_editor';
    public const WOOCOMMERCE = 'woocommerce';
    public const CONTENT = 'content';
    public const SECURITY = 'security';
    public const SYSTEM = 'system';
    public const OTHER = 'other';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::AUTH,
            self::PLUGIN,
            self::THEME,
            self::USER,
            self::ROLE,
            self::OPTIONS,
            self::FILE_EDITOR,
            self::WOOCOMMERCE,
            self::CONTENT,
            self::SECURITY,
            self::SYSTEM,
            self::OTHER,
        ];
    }

    public static function normalize(string $category): string
    {
        $candidate = strtolower(trim($category));
        if ($candidate === '') {
            return self::OTHER;
        }

        return $candidate;
    }
}
