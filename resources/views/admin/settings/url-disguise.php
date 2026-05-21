<?php

declare(strict_types=1);

use PressSentinel\Admin\PressSentinelMenuPage;
use PressSentinel\Core\Support\WpHelper;

/**
 * @var string $pageSlug
 * @var string $optionGroup
 * @var bool $masterEnabled
 * @var bool $isActive
 * @var string $loginSlug
 */
?>
<div class="wrap">
    <h1>PressSentinel URL disguise</h1>
    <p>Replace the predictable <code>wp-login.php</code> URL with your own path. The admin area continues to use <code>/wp-admin/</code>.</p>

    <?php if ($masterEnabled && !$isActive): ?>
        <div class="notice notice-warning">
            <p>
                <strong>URL disguise is not active yet.</strong>
                Turn on <strong>Enable custom login URL</strong> below and set a valid <strong>Login URL slug</strong> (3+ characters, not a reserved word).
                Then save and, if needed, open <strong>Settings → Permalinks</strong> once and click <strong>Save</strong> so WordPress rebuilds rewrite rules.
            </p>
        </div>
    <?php endif; ?>

    <?php if (!$masterEnabled): ?>
        <div class="notice notice-warning">
            <p>
                <strong>The master switch is off.</strong>
                You can edit the slug below, but rewrites and redirects stay disabled until you enable URL disguise here or on the
                <a href="<?= WpHelper::escapeAttribute(
                    \function_exists('admin_url')
                        ? (string) \call_user_func('admin_url', 'admin.php?page=' . PressSentinelMenuPage::PARENT_SLUG)
                        : '#'
                ) ?>">PressSentinel dashboard</a>.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($isActive): ?>
        <div class="notice notice-info">
            <p>
                <strong>Login URL:</strong>
                <code><?= WpHelper::escapeHtml(WpHelper::homeUrl('/' . $loginSlug . '/')) ?></code>
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
    <p class="description">
        Bookmark your custom login URL before blocking <code>wp-login.php</code> (blocked requests return 404, not a redirect).
        If you lock yourself out, set <code>PRESS_SENTINEL_SAFE_MODE=true</code> in the plugin <code>.env</code> (see <code>.env.example</code>) or add <code>define('PRESS_SENTINEL_SAFE_MODE', true);</code> to <code>wp-config.php</code>, reload once, sign in at <code>wp-login.php</code>, then turn safe mode off.
        You can also disable the plugin from the filesystem or clear the <code>presssentinel_url_disguise</code> option in the database.
    </p>
</div>
