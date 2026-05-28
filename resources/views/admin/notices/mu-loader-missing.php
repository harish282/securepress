<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.


use NiyiGuard\Core\Support\WpHelper;

/**
 * @var string $templatePath
 * @var string $expectedPath
 * @var string $guidePath
 */
?>
<div class="notice notice-warning">
    <p>
        <strong>NiyiGuard:</strong>
        MU loader is not installed. For earliest request monitoring, copy the loader file now.
    </p>
    <p>
        <strong>Copy from:</strong>
        <code><?php echo esc_html($templatePath); ?></code><br />
        <strong>Copy to:</strong>
        <code><?php echo esc_html($expectedPath); ?></code><br />
        <strong>Guide:</strong>
        <code><?php echo esc_html($guidePath); ?></code>
    </p>
</div>
