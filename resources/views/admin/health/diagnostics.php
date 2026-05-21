<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.


use PressSentinel\Core\Support\WpHelper;

/**
 * @var array{
 *     generated_at: int,
 *     safe_mode: array{active: bool, bypasses: list<string>},
 *     protections: list<array{key: string, label: string, state: string, detail: string}>,
 *     hooks: list<array{label: string, hook: string, kind: string, expected: bool, registered: bool, status: string, note: string}>,
 *     storage: list<array{label: string, table: string, expected: bool, exists: bool, version: int|null, row_count: int|null, status: string}>,
 *     transients: array{functions_available: bool, read_write_ok: bool, object_cache_active: bool, note: string}
 * } $report
 * @var string $pageSlug
 */

$badge = static function (string $status, string $label): string {
    $styles = match ($status) {
        'active', 'ok' => ['#dff5e0', '#1f7a3a'],
        'bypassed', 'extra', 'present' => ['#e8f4fd', '#135e96'],
        'blocked', 'missing', 'stale' => ['#fce8e8', '#8a2424'],
        default => ['#f0f0f1', '#50575e'],
    };

    return '<span style="display:inline-block;padding:2px 10px;border-radius:10px;background:'
        . $styles[0] . ';color:' . $styles[1] . ';font-weight:600;font-size:12px;">'
        . esc_html($label) . '</span>';
};

$hookLabel = static function (string $status): string {
    return match ($status) {
        'ok' => 'OK',
        'missing' => 'Missing',
        'extra' => 'Registered (idle)',
        'idle' => 'Not required',
        default => ucfirst($status),
    };
};

$storageLabel = static function (string $status): string {
    return match ($status) {
        'ok' => 'OK',
        'missing' => 'Table missing',
        'stale' => 'Needs migration',
        'present' => 'Present (idle)',
        'idle' => 'Not required',
        default => ucfirst($status),
    };
};

$protectionLabel = static function (string $state): string {
    return match ($state) {
        'active' => 'Active',
        'inactive' => 'Off',
        'bypassed' => 'Bypassed',
        'blocked' => 'License blocked',
        default => ucfirst($state),
    };
};

?>
<div class="wrap">
    <h1>Health diagnostics</h1>
    <p style="max-width:720px;color:#50575e;">
        Read-only snapshot for support and pre-launch checks. Refreshed on each page load
        (<?php echo esc_html(gmdate('Y-m-d H:i:s', $report['generated_at']) . ' UTC'); ?>).
    </p>

    <?php if ($report['safe_mode']['active']) : ?>
        <div class="notice notice-warning" style="margin:12px 0;">
            <p><strong>Safe mode is active.</strong>
                Emergency bypasses: <?php echo esc_html(implode(', ', $report['safe_mode']['bypasses'])); ?>.
                Remove <code>PRESS_SENTINEL_SAFE_MODE</code> from wp-config or set <code>recovery.safe_mode</code> to <code>false</code> in <code>config/plugin.php</code> when recovery is complete.</p>
        </div>
    <?php endif; ?>

    <h2>Active protections</h2>
    <table class="widefat striped" style="max-width:960px;">
        <thead>
            <tr>
                <th scope="col">Protection</th>
                <th scope="col">Status</th>
                <th scope="col">Detail</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($report['protections'] as $row) : ?>
            <tr>
                <td><strong><?php echo esc_html($row['label']); ?></strong></td>
                <td><?php echo wp_kses_post($badge($row['state'], $protectionLabel($row['state'])); ?></td>
                <td style="color:#50575e;"><?php echo esc_html($row['detail']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:28px;">Hook status</h2>
    <p class="description" style="max-width:720px;">
        Expected hooks should be registered when the related feature is on. Shared WordPress hooks
        may list other plugins&rsquo; callbacks too — look for <strong>Missing</strong> when a feature is enabled.
    </p>
    <table class="widefat striped" style="max-width:960px;">
        <thead>
            <tr>
                <th scope="col">Component</th>
                <th scope="col">Hook</th>
                <th scope="col">Expected</th>
                <th scope="col">Registered</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($report['hooks'] as $row) : ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($row['label']); ?></strong>
                    <div style="color:#787c82;font-size:12px;margin-top:4px;"><?php echo esc_html($row['note']); ?></div>
                </td>
                <td><code><?php echo esc_html($row['kind'] . ':' . $row['hook']); ?></code></td>
                <td><?php echo esc_html($row['expected'] ? 'Yes' : 'No'); ?></td>
                <td><?php echo esc_html($row['registered'] ? 'Yes' : 'No'); ?></td>
                <td><?php echo wp_kses_post($badge($row['status'], $hookLabel($row['status'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:28px;">Storage status</h2>
    <table class="widefat striped" style="max-width:960px;">
        <thead>
            <tr>
                <th scope="col">Store</th>
                <th scope="col">Table</th>
                <th scope="col">Expected</th>
                <th scope="col">Exists</th>
                <th scope="col">Schema v.</th>
                <th scope="col">Rows</th>
                <th scope="col">Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($report['storage'] as $row) : ?>
            <tr>
                <td><strong><?php echo esc_html($row['label']); ?></strong></td>
                <td><code><?php echo esc_html($row['table']); ?></code></td>
                <td><?php echo esc_html($row['expected'] ? 'Yes' : 'No'); ?></td>
                <td><?php echo esc_html($row['exists'] ? 'Yes' : 'No'); ?></td>
                <td><?php echo esc_html($row['version'] !== null ? (string) (int) $row['version'] : '—'); ?></td>
                <td><?php echo esc_html($row['row_count'] !== null ? number_format($row['row_count']) : '—'); ?></td>
                <td><?php echo wp_kses_post($badge($row['status'], $storageLabel($row['status'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2 style="margin-top:28px;">Transient availability</h2>
    <table class="widefat striped" style="max-width:720px;">
        <tbody>
            <tr>
                <th scope="row" style="width:220px;">WordPress transient API</th>
                <td><?php echo esc_html($report['transients']['functions_available'] ? 'Available' : 'Unavailable'); ?></td>
            </tr>
            <tr>
                <th scope="row">Read/write probe</th>
                <td>
                    <?php
                    if (!$report['transients']['functions_available']) {
                        echo 'Skipped';
                    } elseif ($report['transients']['read_write_ok']) {
                        echo wp_kses_post($badge('ok', 'Pass'));
                    } else {
                        echo wp_kses_post($badge('missing', 'Failed'));
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Persistent object cache</th>
                <td><?php echo esc_html($report['transients']['object_cache_active'] ? 'Detected' : 'Not detected'); ?></td>
            </tr>
            <tr>
                <th scope="row">Notes</th>
                <td style="color:#50575e;"><?php echo esc_html($report['transients']['note']); ?></td>
            </tr>
        </tbody>
    </table>
</div>
