<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

use PressSentinel\Admin\AuditLogPage;
use PressSentinel\Core\Audit\AuditEvent;
use PressSentinel\Core\Audit\AuditLogPage as PageResult;
use PressSentinel\Core\Audit\AuditLogQuery;
use PressSentinel\Core\Support\WpHelper;

/**
 * @var PageResult       $page
 * @var AuditLogQuery    $query
 * @var int              $totalAll
 * @var string           $pageSlug
 * @var string           $nonceAction
 * @var list<string>     $categories
 * @var list<string>     $levels
 * @var string|null      $status
 * @var int|null         $detailId
 * @var AuditEvent|null  $detail
 */

$pageBase = WpHelper::adminUrl('admin.php?page=' . $pageSlug);
$nonceField = static function (string $action): string {
    if (\function_exists('wp_nonce_field')) {
        ob_start();
        \call_user_func('wp_nonce_field', $action);

        return (string) ob_get_clean();
    }

    return '';
};
$buildLink = static function (array $params) use ($pageBase, $query): string {
    $defaults = [
        'page' => isset($_GET['page']) ? (string) $_GET['page'] : '',
        'category' => $query->category,
        'level' => $query->level,
        's' => $query->search,
        'date_from' => isset($_GET['date_from']) ? (string) $_GET['date_from'] : '',
        'date_to' => isset($_GET['date_to']) ? (string) $_GET['date_to'] : '',
        'per_page' => $query->perPage,
        'paged' => $query->page,
    ];
    $merged = array_filter(array_replace($defaults, $params), static fn ($v): bool => $v !== null && $v !== '');

    return esc_url(WpHelper::adminUrl('admin.php') . '?' . http_build_query($merged));
};

$levelClass = static function (string $level): string {
    return match ($level) {
        'emergency', 'alert', 'critical' => 'notice notice-error inline',
        'error', 'warning' => 'notice notice-warning inline',
        'notice', 'info' => 'notice notice-info inline',
        default => 'notice inline',
    };
};

?>
<div class="wrap">
    <h1>PressSentinel Audit Logs</h1>
    <p class="description">
        <?php echo esc_html(sprintf(
            'Tracking %d events across %d categories. Use the filters below to narrow the view.',
            $totalAll,
            count($categories)
        )); ?>
    </p>

    <?php if (is_string($status) && $status !== ''): ?>
        <?php
        [$type, $count] = array_pad(explode(':', $status, 2), 2, '0');
        $msg = match ($type) {
            'cleared' => sprintf('All audit logs cleared (%d entries removed).', (int) $count),
            'pruned' => sprintf('Pruned %d entries beyond the retention window.', (int) $count),
            default => 'Action completed.',
        };
        ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($msg); ?></p></div>
    <?php endif; ?>

    <form method="get" style="margin: 16px 0;">
        <input type="hidden" name="page" value="<?php echo esc_attr($pageSlug); ?>">

        <select name="category">
            <option value="">All categories</option>
            <?php foreach ($categories as $cat): ?>
                <option value="<?php echo esc_attr($cat); ?>"<?php selected($query->category, $cat, false); ?>>
                    <?php echo esc_html($cat); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="level">
            <option value="">All levels</option>
            <?php foreach ($levels as $lvl): ?>
                <option value="<?php echo esc_attr($lvl); ?>"<?php selected($query->level, $lvl, false); ?>>
                    <?php echo esc_html($lvl); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="text" name="s" value="<?php echo esc_attr((string) ($query->search ?? '')); ?>" placeholder="Search action / message / actor" style="min-width: 260px;">

        <label style="margin-left: 8px;">
            From
            <input type="date" name="date_from" value="<?php echo esc_attr(isset($_GET['date_from']) ? (string) $_GET['date_from'] : ''); ?>">
        </label>
        <label>
            To
            <input type="date" name="date_to" value="<?php echo esc_attr(isset($_GET['date_to']) ? (string) $_GET['date_to'] : ''); ?>">
        </label>

        <select name="per_page">
            <?php foreach ([25, 50, 100, 200] as $pp): ?>
                <option value="<?php echo esc_attr((string) $pp); ?>"<?php selected($query->perPage, $pp, false); ?>><?php echo esc_html((string) $pp); ?> per page</option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="button">Filter</button>
        <a href="<?php echo esc_url($pageBase); ?>" class="button">Reset</a>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 160px;">Time (UTC)</th>
                <th style="width: 90px;">Level</th>
                <th style="width: 120px;">Category</th>
                <th>Action</th>
                <th style="width: 160px;">Actor</th>
                <th style="width: 140px;">Target</th>
                <th style="width: 130px;">IP</th>
                <th style="width: 80px;">Details</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($page->isEmpty()): ?>
                <tr><td colspan="8"><em>No matching audit log entries.</em></td></tr>
            <?php else: ?>
                <?php foreach ($page->items as $event): ?>
                    <tr>
                        <td><code><?php echo esc_html(gmdate('Y-m-d H:i:s', $event->occurredAt)); ?></code></td>
                        <td><span class="<?php echo esc_attr($levelClass($event->level)); ?>" style="margin: 0; padding: 2px 8px; border-left-width: 3px;"><?php echo esc_html($event->level); ?></span></td>
                        <td><?php echo esc_html($event->category); ?></td>
                        <td>
                            <strong><?php echo esc_html($event->action); ?></strong>
                            <?php if ($event->message !== null && $event->message !== ''): ?>
                                <br><span class="description"><?php echo esc_html($event->message); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($event->actorName !== null): ?>
                                <?php echo esc_html($event->actorName); ?>
                                <?php if ($event->actorId !== null): ?> <small>(#<?php echo (int) $event->actorId; ?>)</small><?php endif; ?>
                            <?php elseif ($event->actorId !== null): ?>
                                <small>user #<?php echo (int) $event->actorId; ?></small>
                            <?php else: ?>
                                <em class="description">system</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($event->targetType !== null): ?>
                                <code><?php echo esc_html($event->targetType); ?></code>
                                <?php if ($event->targetId !== null && $event->targetId !== ''): ?>
                                    <br><small><?php echo esc_html($event->targetId); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <em class="description">—</em>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html((string) ($event->ip ?? '—')); ?></code></td>
                        <td>
                            <?php if ($event->id !== null): ?>
                                <a href="<?php echo esc_url($buildLink(['detail' => (string) $event->id]); ?>">View</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php $totalPages = $page->totalPages(); ?>
    <?php if ($totalPages > 1): ?>
        <div class="tablenav"><div class="tablenav-pages">
            <span class="displaying-num">
                <?php echo esc_html(sprintf('%d items', $page->total)); ?>
            </span>
            <span class="pagination-links">
                <?php if ($page->page > 1): ?>
                    <a class="button" href="<?php echo esc_url($buildLink(['paged' => '1']); ?>">&laquo;</a>
                    <a class="button" href="<?php echo esc_url($buildLink(['paged' => (string) ($page->page - 1)]); ?>">&lsaquo;</a>
                <?php endif; ?>
                <span class="paging-input">
                    <?php echo esc_html(sprintf('%d of %d', $page->page, $totalPages)); ?>
                </span>
                <?php if ($page->page < $totalPages): ?>
                    <a class="button" href="<?php echo esc_url($buildLink(['paged' => (string) ($page->page + 1)]); ?>">&rsaquo;</a>
                    <a class="button" href="<?php echo esc_url($buildLink(['paged' => (string) $totalPages]); ?>">&raquo;</a>
                <?php endif; ?>
            </span>
        </div></div>
    <?php endif; ?>

    <?php if ($detail instanceof AuditEvent): ?>
        <hr>
        <h2>Event detail #<?php echo (int) $detail->id; ?></h2>
        <table class="form-table" role="presentation">
            <tr><th>Time (UTC)</th><td><code><?php echo esc_html(gmdate('Y-m-d H:i:s', $detail->occurredAt)); ?></code></td></tr>
            <tr><th>Level</th><td><?php echo esc_html($detail->level); ?></td></tr>
            <tr><th>Category</th><td><?php echo esc_html($detail->category); ?></td></tr>
            <tr><th>Action</th><td><code><?php echo esc_html($detail->action); ?></code></td></tr>
            <tr><th>Message</th><td><?php echo esc_html((string) ($detail->message ?? '—')); ?></td></tr>
            <tr><th>Actor</th><td><?php echo esc_html(($detail->actorName ?? '') . ($detail->actorId !== null ? ' (#' . $detail->actorId . ')' : '') ?: 'system'); ?></td></tr>
            <tr><th>Target</th><td><code><?php echo esc_html(($detail->targetType ?? '—') . ($detail->targetId !== null ? ':' . $detail->targetId : '')); ?></code></td></tr>
            <tr><th>IP / UA</th><td><code><?php echo esc_html((string) ($detail->ip ?? '—')); ?></code><br><small><?php echo esc_html((string) ($detail->userAgent ?? '')); ?></small></td></tr>
            <tr><th>Request URI</th><td><code><?php echo esc_html((string) ($detail->requestUri ?? '—')); ?></code></td></tr>
            <tr><th>Context</th><td><pre style="white-space: pre-wrap; word-break: break-all;"><?php echo esc_html((string) json_encode($detail->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre></td></tr>
        </table>
        <p><a href="<?php echo esc_url($pageBase); ?>" class="button">Back to list</a></p>
    <?php endif; ?>

    <hr>
    <h2>Maintenance</h2>
    <p class="description">
        Configure <a href="<?php echo esc_url(
            \function_exists('admin_url')
                ? (string) \call_user_func('admin_url', 'admin.php?page=presssentinel-audit-settings')
                : '#'
        ) ?>">retention, auto-prune, and minimum log level</a> before large deployments.
        All actions below require the <code>manage_options</code> capability and a valid WordPress nonce. Clearing logs is permanent.
    </p>

    <form method="post" action="<?php echo esc_url(WpHelper::adminUrl('admin-post.php')); ?>" style="display: inline-block; margin-right: 8px;" onsubmit="return confirm('Run pruner now? Entries beyond the configured retention window will be deleted.');">
        <input type="hidden" name="action" value="presssentinel_prune_audit_logs">
        <?php echo wp_kses_post($nonceField($nonceAction); ?>
        <button type="submit" class="button">Run prune now</button>
    </form>

    <form method="post" action="<?php echo esc_url(WpHelper::adminUrl('admin-post.php')); ?>" style="display: inline-block;" onsubmit="return confirm('Permanently delete every audit log entry? This cannot be undone.');">
        <input type="hidden" name="action" value="presssentinel_clear_audit_logs">
        <?php echo wp_kses_post($nonceField($nonceAction); ?>
        <button type="submit" class="button button-link-delete">Clear all logs</button>
    </form>
</div>
