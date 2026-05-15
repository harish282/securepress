<?php

declare(strict_types=1);

use SecurePress\Core\Support\WpHelper;

/**
 * @var list<string> $bypasses
 */
?>
<div class="notice notice-warning">
    <p>
        <strong>SecurePress safe mode is active.</strong>
        Emergency recovery bypasses are enabled for:
        <code><?= WpHelper::escapeHtml(implode(', ', $bypasses)) ?></code>.
        Login disguise, login lockouts, and global rate limiting are not enforced until you set
        <code>SECUREPRESS_SAFE_MODE=false</code> in the plugin <code>.env</code> file or remove
        <code>define('SECUREPRESS_SAFE_MODE', true);</code> from <code>wp-config.php</code>.
    </p>
</div>
