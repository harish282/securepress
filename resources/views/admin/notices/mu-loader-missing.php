<?php

declare(strict_types=1);

use PressSentinel\Core\Support\WpHelper;

/**
 * @var string $templatePath
 * @var string $expectedPath
 * @var string $guidePath
 */
?>
<div class="notice notice-warning">
    <p>
        <strong>PressSentinel:</strong>
        MU loader is not installed. For earliest request monitoring, copy the loader file now.
    </p>
    <p>
        <strong>Copy from:</strong>
        <code><?= WpHelper::escapeHtml($templatePath); ?></code><br />
        <strong>Copy to:</strong>
        <code><?= WpHelper::escapeHtml($expectedPath); ?></code><br />
        <strong>Guide:</strong>
        <code><?= WpHelper::escapeHtml($guidePath); ?></code>
    </p>
</div>
