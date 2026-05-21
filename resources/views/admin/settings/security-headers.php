<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

use PressSentinel\Core\Support\WpHelper;

/**
 * @var string $pageSlug
 * @var string $optionGroup
 * @var bool $masterEnabled
 */
?>
<div class="wrap">
    <h1>PressSentinel Security Headers</h1>
    <p>Toggle the headers you want PressSentinel to emit on every WordPress response. Defaults are conservative — review carefully before enabling HSTS or CSP.</p>

    <?php if (!$masterEnabled): ?>
        <div class="notice notice-warning">
            <p>
                <strong>Security headers are currently off.</strong>
                The master switch below (also available on the
                <a href="<?php echo esc_attr(
                    \function_exists('admin_url')
                        ? (string) \call_user_func('admin_url', 'admin.php?page=' . \PressSentinel\Admin\PressSentinelMenuPage::PARENT_SLUG)
                        : '#'
                ) ?>">PressSentinel dashboard</a>)
                is disabled, so none of the headers configured here will be emitted on responses. Re-enable it and click <em>Save Changes</em> to resume.
            </p>
        </div>
    <?php endif; ?>

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
        <code>curl -sI <?php echo esc_html(\function_exists('home_url') ? (string) \call_user_func('home_url', '/') : '/') ?></code>
        from a terminal to verify the headers are being emitted as expected.
    </p>
</div>
