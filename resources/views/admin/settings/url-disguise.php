<?php

declare(strict_types=1);

use SecurePress\Admin\SecurePressMenuPage;
use SecurePress\Core\Support\WpHelper;

/**
 * @var string $pageSlug
 * @var string $optionGroup
 * @var bool $masterEnabled
 * @var bool $isActive
 * @var string $loginSlug
 * @var string $adminSlug
 */
?>
<div class="wrap">
    <h1>SecurePress URL disguise</h1>
    <p>Replace the predictable <code>wp-login.php</code> URL with your own path, and optionally serve admin PHP entry points under a second custom prefix.</p>

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
                You can edit slugs below, but rewrites and redirects stay disabled until you enable URL disguise here or on the
                <a href="<?= WpHelper::escapeAttribute(
                    \function_exists('admin_url')
                        ? (string) \call_user_func('admin_url', 'admin.php?page=' . SecurePressMenuPage::PARENT_SLUG)
                        : '#'
                ) ?>">SecurePress dashboard</a>.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($isActive): ?>
        <div class="notice notice-info">
            <p>
                <strong>Login URL:</strong>
                <code><?= WpHelper::escapeHtml(WpHelper::homeUrl('/' . $loginSlug . '/')) ?></code>
                <?php if ($adminSlug !== ''): ?>
                    <br><strong>Admin prefix:</strong>
                    <code><?= WpHelper::escapeHtml(WpHelper::homeUrl('/' . $adminSlug . '/')) ?></code>
                <?php endif; ?>
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
        Bookmark your custom login URL before blocking <code>wp-login.php</code> (blocked requests return 404, not a redirect). With an admin slug, the default <code>/wp-admin/</code> entry can be blocked the same way; deeper <code>/wp-admin/</code> URLs used for assets and AJAX stay available. If you lock yourself out, disable SecurePress from the filesystem or the database option <code>securepress_url_disguise</code>.
    </p>
</div>
