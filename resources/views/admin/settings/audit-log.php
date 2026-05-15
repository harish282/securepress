<?php

declare(strict_types=1);

use SecurePress\Admin\SecurePressMenuPage;
use SecurePress\Core\Support\WpHelper;

/**
 * @var string $pageSlug
 * @var string $optionGroup
 * @var bool $masterEnabled
 * @var int $retentionDays
 * @var bool $autoPruneEnabled
 * @var string $minStorageLevel
 * @var string $logsPageSlug
 */
?>
<div class="wrap">
    <h1>SecurePress audit log settings</h1>
    <p>Manage database growth for the security audit trail before large deployments.</p>

    <?php if (!$masterEnabled): ?>
        <div class="notice notice-warning">
            <p>
                <strong>Audit logging is off.</strong>
                Events are not recorded until you enable the feature on the
                <a href="<?= WpHelper::escapeAttribute(
                    \function_exists('admin_url')
                        ? (string) \call_user_func('admin_url', 'admin.php?page=' . SecurePressMenuPage::PARENT_SLUG)
                        : '#'
                ) ?>">SecurePress dashboard</a>.
                Retention and level settings below still apply once logging is turned back on.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($retentionDays === 0): ?>
        <div class="notice notice-info">
            <p><strong>Retention is set to 0 days</strong> — rows are kept indefinitely unless you clear or prune them manually.</p>
        </div>
    <?php elseif ($autoPruneEnabled): ?>
        <div class="notice notice-info">
            <p>
                Automatic pruning is on: entries older than <strong><?= (int) $retentionDays ?></strong> days are deleted daily.
                Minimum stored level: <code><?= WpHelper::escapeHtml($minStorageLevel) ?></code>.
            </p>
        </div>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php
        if (\function_exists('settings_fields')) {
            \call_user_func('settings_fields', $optionGroup);
        }
        if (\function_exists('do_settings_sections')) {
            \call_user_func('do_settings_sections', $pageSlug);
        }
        if (\function_exists('submit_button')) {
            \call_user_func('submit_button');
        }
        ?>
    </form>

    <hr>
    <p class="description">
        <a href="<?= WpHelper::escapeAttribute(
            \function_exists('admin_url')
                ? (string) \call_user_func('admin_url', 'admin.php?page=' . $logsPageSlug)
                : '#'
        ) ?>">View audit logs</a>
        · Run an immediate prune from that page when you need to reclaim space without waiting for cron.
    </p>
</div>
