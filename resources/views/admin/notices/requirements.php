<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

use PressSentinel\Core\Support\WpHelper;

/**
 * @var array<int, string> $errors
 */
?>
<div class="notice notice-error">
    <p><strong>PressSentinel:</strong></p>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?php echo esc_html($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
