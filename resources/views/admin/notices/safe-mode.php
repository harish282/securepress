<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

use PressSentinel\Core\Support\WpHelper;

/**
 * @var list<string> $bypasses
 */
?>
<div class="notice notice-warning">
    <p>
        <strong>PressSentinel safe mode is active.</strong>
        Emergency recovery bypasses are enabled for:
        <code><?php echo esc_html(implode(', ', $bypasses)) ?></code>.
        Login disguise, login lockouts, and global rate limiting are not enforced until you set
        <code>recovery.safe_mode</code> to <code>false</code> in <code>config/plugin.php</code> or remove
        <code>define('PRESS_SENTINEL_SAFE_MODE', true);</code> from <code>wp-config.php</code>.
    </p>
</div>
