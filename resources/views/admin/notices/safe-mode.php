<?php

declare(strict_types=1);

use PressSentinel\Core\Support\WpHelper;

/**
 * @var list<string> $bypasses
 */
?>
<div class="notice notice-warning">
    <p>
        <strong>PressSentinel safe mode is active.</strong>
        Emergency recovery bypasses are enabled for:
        <code><?= WpHelper::escapeHtml(implode(', ', $bypasses)) ?></code>.
        Login disguise, login lockouts, and global rate limiting are not enforced until you set
        <code>PRESS_SENTINEL_SAFE_MODE=false</code> in the plugin <code>.env</code> file or remove
        <code>define('PRESS_SENTINEL_SAFE_MODE', true);</code> from <code>wp-config.php</code>.
    </p>
</div>
