<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

use PressSentinel\Admin\PressSentinelMenuPage;
use PressSentinel\Core\Support\WpHelper;

/**
 * @var string $pageSlug
 * @var string $optionGroup
 * @var bool $masterEnabled
 */
?>
<div class="wrap">
    <h1>PressSentinel Rate Limiting</h1>
    <p>Cap the request rate per user / IP. Configured here, enforced globally by the rate-limit middleware on every request.</p>

    <?php if (!$masterEnabled): ?>
        <div class="notice notice-warning">
            <p>
                <strong>Rate limiting is currently off.</strong>
                The master switch below (also available on the
                <a href="<?php echo esc_attr(
                    \function_exists('admin_url')
                        ? (string) \call_user_func('admin_url', 'admin.php?page=' . PressSentinelMenuPage::PARENT_SLUG)
                        : '#'
                ) ?>">PressSentinel dashboard</a>)
                is disabled, so no global throttling is applied. Per-route SDK limits (<code>Security::rateLimit(...)</code>) are unaffected.
            </p>
        </div>
    <?php endif; ?>

    <p class="description">
        Locked out or throttled? Add <code>define('PRESS_SENTINEL_SAFE_MODE', true);</code> to <code>wp-config.php</code> or set <code>recovery.safe_mode</code> to <code>true</code> in <code>config/plugin.php</code> to bypass global rate limiting and login lockouts until you regain access.
    </p>

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
        <strong>How buckets work:</strong>
        authenticated requests are bucketed by user id (so signed-in users don't share a budget with anonymous traffic on the same IP), and anonymous requests are bucketed by client IP. A custom <code>keyResolver</code> closure passed via the SDK can override this on a per-route basis.
    </p>
</div>
