<?php

declare(strict_types=1);

use SecurePress\Core\Support\WpHelper;

/**
 * @var string $pageSlug
 * @var string $optionGroup
 */
?>
<div class="wrap">
    <h1>SecurePress Security Headers</h1>
    <p>Toggle the headers you want SecurePress to emit on every WordPress response. Defaults are conservative — review carefully before enabling HSTS or CSP.</p>

    <form method="post" action="options.php">
        <?php
        if (\function_exists('settings_fields')) {
            \call_user_func('settings_fields', $optionGroup);
        }
        if (\function_exists('do_settings_sections')) {
            \call_user_func('do_settings_sections', $pageSlug);
        }
        if (\function_exists('submit_button')) {
            \call_user_func('submit_button');
        }
        ?>
    </form>

    <hr>
    <p>
        <strong>Tip:</strong> after saving, open your site in a fresh browser tab and inspect the response headers
        (DevTools → Network → click any request → Headers) or run
        <code>curl -sI <?= WpHelper::escapeHtml(\function_exists('home_url') ? (string) \call_user_func('home_url', '/') : '/') ?></code>
        from a terminal to verify the headers are being emitted as expected.
    </p>
</div>
