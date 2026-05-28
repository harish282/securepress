<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.


use NiyiGuard\Core\Support\WpHelper;

/**
 * @var array<int, string> $errors
 */
?>
<div class="notice notice-error">
    <p><strong>NiyiGuard:</strong></p>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?php echo esc_html($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
