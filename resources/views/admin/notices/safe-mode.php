<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.


use NiyiGuard\Core\Support\WpHelper;

/**
 * @var list<string> $bypasses
 */
?>
<div class="notice notice-warning">
    <p>
        <strong>NiyiGuard safe mode is active.</strong>
        Emergency recovery bypasses are enabled for:
        <code><?php echo esc_html(implode(', ', $bypasses)) ?></code>.
        Login disguise, login lockouts, and global rate limiting are not enforced until you set
        <code>recovery.safe_mode</code> to <code>false</code> in <code>config/plugin.php</code> or remove
        <code>define('NIYIGUARD_SAFE_MODE', true);</code> from <code>wp-config.php</code>.
    </p>
</div>
