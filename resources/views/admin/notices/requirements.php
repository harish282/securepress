<?php

declare(strict_types=1);

use PressSentinel\Core\Support\WpHelper;

/**
 * @var array<int, string> $errors
 */
?>
<div class="notice notice-error">
    <p><strong>PressSentinel:</strong></p>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= WpHelper::escapeHtml($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
