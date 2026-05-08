<?php

declare(strict_types=1);

use SecurePress\Core\Support\WpHelper;

/**
 * @var array<int, string> $errors
 */
?>
<div class="notice notice-error">
    <p><strong>SecurePress:</strong></p>
    <ul>
        <?php foreach ($errors as $error): ?>
            <li><?= WpHelper::escapeHtml($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
