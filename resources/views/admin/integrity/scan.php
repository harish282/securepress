<?php

declare(strict_types=1);

use SecurePress\Admin\FileIntegrityPage;
use SecurePress\Core\Integrity\Finding;
use SecurePress\Core\Integrity\FindingSeverity;
use SecurePress\Core\Support\WpHelper;

/**
 * @var list<Finding>          $findings
 * @var list<Finding>          $open
 * @var int                    $openCount
 * @var int                    $allCount
 * @var list<string>           $severities
 * @var string                 $pageSlug
 * @var string                 $nonceAction
 * @var array<string, string>  $severityLabels
 * @var array<string, string>  $typeLabels
 * @var list<array{name:string}> $scannerSummary
 * @var string|null            $status
 */

$adminUrl = WpHelper::adminUrl('admin-post.php');
$nonceField = static function (string $action): string {
    if (\function_exists('wp_nonce_field')) {
        ob_start();
        \call_user_func('wp_nonce_field', $action);

        return (string) ob_get_clean();
    }

    return '';
};

$severityClass = static function (string $severity): string {
    return match ($severity) {
        FindingSeverity::CRITICAL => 'notice notice-error inline',
        FindingSeverity::HIGH => 'notice notice-warning inline',
        FindingSeverity::MEDIUM => 'notice notice-info inline',
        default => 'notice inline',
    };
};

$severityBuckets = [];
foreach ($severities as $level) {
    $severityBuckets[$level] = 0;
}
foreach ($open as $finding) {
    if (isset($severityBuckets[$finding->severity])) {
        $severityBuckets[$finding->severity]++;
    }
}

?>
<div class="wrap">
    <h1>SecurePress &mdash; File integrity</h1>

    <?php if (is_string($status) && $status !== '') : ?>
        <div class="notice notice-info is-dismissible">
            <p><strong><?php echo WpHelper::escapeHtml($status); ?></strong></p>
        </div>
    <?php endif; ?>

    <p class="description">
        File integrity monitoring inspects your WordPress install for tampering: modified core files,
        changes to installed plugins, and PHP files that look like malware. Findings stay open until
        you mark them reviewed.
    </p>

    <h2>Summary</h2>
    <table class="widefat striped" style="max-width: 760px;">
        <tbody>
            <tr>
                <th scope="row">Open findings</th>
                <td><?php echo (int) $openCount; ?></td>
            </tr>
            <tr>
                <th scope="row">Total findings (incl. reviewed)</th>
                <td><?php echo (int) $allCount; ?></td>
            </tr>
            <?php foreach ($severityBuckets as $level => $count) : ?>
                <tr>
                    <th scope="row"><?php echo WpHelper::escapeHtml($severityLabels[$level] ?? $level); ?></th>
                    <td><?php echo (int) $count; ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row">Active scanners</th>
                <td>
                    <?php if ($scannerSummary === []) : ?>
                        <em>No scanners registered.</em>
                    <?php else : ?>
                        <ul style="margin:0;">
                            <?php foreach ($scannerSummary as $scanner) : ?>
                                <li><code><?php echo WpHelper::escapeHtml($scanner['name']); ?></code></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <h2 style="margin-top: 2em;">Actions</h2>
    <p>
        <form method="post" action="<?php echo WpHelper::escapeUrl($adminUrl); ?>" style="display:inline-block; margin-right: 8px;">
            <input type="hidden" name="action" value="securepress_integrity_rescan" />
            <?php echo $nonceField(FileIntegrityPage::NONCE_ACTION); ?>
            <button type="submit" class="button button-primary">Run scan now</button>
        </form>

        <form method="post" action="<?php echo WpHelper::escapeUrl($adminUrl); ?>" style="display:inline-block; margin-right: 8px;">
            <input type="hidden" name="action" value="securepress_integrity_clear" />
            <?php echo $nonceField(FileIntegrityPage::NONCE_ACTION); ?>
            <button type="submit" class="button" onclick="return confirm('Permanently delete every finding?');">Clear all findings</button>
        </form>

        <form method="post" action="<?php echo WpHelper::escapeUrl($adminUrl); ?>" style="display:inline-block;">
            <input type="hidden" name="action" value="securepress_integrity_reset_baseline" />
            <input type="hidden" name="scope" value="" />
            <?php echo $nonceField(FileIntegrityPage::NONCE_ACTION); ?>
            <button type="submit" class="button" onclick="return confirm('Reset every baseline? The next scan will rebuild them.');">Reset all baselines</button>
        </form>
    </p>

    <h2 style="margin-top: 2em;">Open findings (<?php echo (int) $openCount; ?>)</h2>

    <?php if ($open === []) : ?>
        <div class="notice notice-success inline"><p><strong>Clean.</strong> No outstanding integrity findings.</p></div>
    <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 90px;">Severity</th>
                    <th scope="col" style="width: 130px;">Type</th>
                    <th scope="col" style="width: 80px;">Scope</th>
                    <th scope="col">Path / message</th>
                    <th scope="col" style="width: 160px;">When</th>
                    <th scope="col" style="width: 180px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($open as $finding) : ?>
                    <tr>
                        <td>
                            <span class="<?php echo WpHelper::escapeAttribute($severityClass($finding->severity)); ?>" style="margin:0; padding:2px 8px;">
                                <?php echo WpHelper::escapeHtml($severityLabels[$finding->severity] ?? $finding->severity); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo WpHelper::escapeHtml($typeLabels[$finding->type] ?? $finding->type); ?>
                        </td>
                        <td><?php echo WpHelper::escapeHtml($finding->scope); ?></td>
                        <td>
                            <code><?php echo WpHelper::escapeHtml($finding->path); ?></code><br>
                            <small><?php echo WpHelper::escapeHtml($finding->message); ?></small>
                            <?php if ($finding->details !== []) : ?>
                                <details style="margin-top: 4px;">
                                    <summary>Details</summary>
                                    <pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px;border-left:3px solid #c3c4c7;font-size:11px;"><?php
                                        echo WpHelper::escapeHtml((string) json_encode($finding->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                                    ?></pre>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td><?php echo WpHelper::escapeHtml(gmdate('Y-m-d H:i:s', $finding->createdAt)); ?> UTC</td>
                        <td>
                            <form method="post" action="<?php echo WpHelper::escapeUrl($adminUrl); ?>" style="display:inline-block;">
                                <input type="hidden" name="action" value="securepress_integrity_review" />
                                <input type="hidden" name="id" value="<?php echo (int) ($finding->id ?? 0); ?>" />
                                <?php echo $nonceField(FileIntegrityPage::NONCE_ACTION); ?>
                                <button type="submit" class="button button-small">Mark reviewed</button>
                            </form>
                            <form method="post" action="<?php echo WpHelper::escapeUrl($adminUrl); ?>" style="display:inline-block;">
                                <input type="hidden" name="action" value="securepress_integrity_delete" />
                                <input type="hidden" name="id" value="<?php echo (int) ($finding->id ?? 0); ?>" />
                                <?php echo $nonceField(FileIntegrityPage::NONCE_ACTION); ?>
                                <button type="submit" class="button button-small">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
