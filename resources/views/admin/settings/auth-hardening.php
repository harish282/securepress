<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.


/**
 * @var string $pageSlug
 * @var string $optionGroup
 */
?>
<div class="wrap">
    <h1>PressSentinel Authentication</h1>
    <p>Configure login lockout, two-factor authentication, session tracking, and suspicious-login alerts. Each user manages their own 2FA enrolment from <strong>Account Security</strong> in the sidebar.</p>

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
        <strong>Heads up:</strong> turning <em>Authentication Hardening</em> off does <em>not</em> remove existing 2FA enrolments — users keep their stored secrets and recovery codes; the login flow simply stops enforcing the second factor. Re-enabling restores enforcement immediately on the next request.
    </p>
</div>
