<?php

declare(strict_types=1);

namespace PressSentinel\Core\Audit\Listeners;

use PressSentinel\Core\Audit\AuditEvent;
use PressSentinel\Core\Audit\AuditEventCategory;
use PressSentinel\Core\Audit\AuditEventLevel;
use PressSentinel\Core\Audit\AuditLoggerInterface;
use PressSentinel\Core\Support\WpHelper;

/**
 * Records changes to a curated allowlist of WordPress options.
 *
 * `updated_option` fires for *every* option write — many of which are routine cache /
 * transient bookkeeping that would drown the audit log. We listen but filter to a
 * security-relevant allowlist (siteurl, admin email, default role, …). Plugins that need
 * to track additional options should add to the allowlist via the `audit_log.option_allowlist`
 * config key rather than registering parallel listeners.
 *
 * Old/new values are stored in context. For long values we truncate to 500 chars so the
 * audit log row doesn't balloon — the original change is still recoverable from WP backups
 * if needed, this log only needs enough to spot suspicious changes.
 */
final class OptionsListener implements ListenerInterface
{
    private const TRUNCATE_LENGTH = 500;

    /**
     * @param list<string> $allowlist
     */
    public function __construct(
        private readonly AuditLoggerInterface $logger,
        private readonly array $allowlist,
    ) {
    }

    public function register(): void
    {
        WpHelper::addAction('updated_option', [$this, 'onOptionUpdated'], 10, 3);
    }

    public function onOptionUpdated(string $option, mixed $oldValue, mixed $newValue): void
    {
        if (!in_array($option, $this->allowlist, true)) {
            return;
        }

        $level = $this->levelFor($option);

        $event = AuditEvent::make('option.updated', AuditEventCategory::OPTIONS, $level)
            ->withTarget('option', $option)
            ->withMessage(sprintf('Option "%s" was updated.', $option))
            ->withContext([
                'option' => $option,
                'old' => $this->normalizeValue($oldValue),
                'new' => $this->normalizeValue($newValue),
            ]);

        $this->logger->record($event);
    }

    private function levelFor(string $option): string
    {
        $critical = ['siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'wp_user_roles'];

        return in_array($option, $critical, true) ? AuditEventLevel::WARNING : AuditEventLevel::NOTICE;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::TRUNCATE_LENGTH
                ? mb_substr($value, 0, self::TRUNCATE_LENGTH) . '…[truncated]'
                : $value;
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            $encoded = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return mb_strlen($encoded) > self::TRUNCATE_LENGTH
                ? mb_substr($encoded, 0, self::TRUNCATE_LENGTH) . '…[truncated]'
                : $encoded;
        }

        return '[non-scalar]';
    }
}
