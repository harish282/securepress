<?php
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.

/**
 * @var string $token
 * @var string $method
 * @var string $method_label
 * @var int $expires_at
 * @var int $remaining_seconds
 * @var string $redirect_to
 * @var string $login_url
 * @var ?string $error
 * @var ?string $info
 * @var string $form_nonce
 * @var string $nonce_field
 * @var string $resend_url
 * @var string $submit_action
 */

use NiyiGuard\Core\Support\WpHelper;

$method = (string) $method;
$is_email_otp = $method === 'email_otp';
$is_recovery_default = false;

$minutes_remaining = max(1, (int) ceil(((int) $remaining_seconds) / 60));
?>

<form name="sp_2fa_form" id="loginform" action="<?php echo esc_attr((string) $submit_action); ?>" method="post">
    <div class="sp-2fa-card">
        <h1>Two-factor authentication</h1>
        <p class="sp-2fa-meta">
            Method: <strong><?php echo esc_html((string) $method_label); ?></strong>
            &middot; expires in ~<?php echo (int) $minutes_remaining; ?> min
        </p>

        <?php if (!empty($error)) : ?>
            <div class="sp-2fa-error"><?php echo esc_html((string) $error); ?></div>
        <?php endif; ?>

        <?php if (!empty($info)) : ?>
            <div class="sp-2fa-info"><?php echo esc_html((string) $info); ?></div>
        <?php endif; ?>

        <p>
            <?php if ($is_email_otp) : ?>
                Enter the verification code we just emailed to you.
            <?php else : ?>
                Open your authenticator app and enter the current 6-digit code.
            <?php endif; ?>
        </p>

        <p>
            <label for="sp_2fa_code">Verification code</label>
            <input type="text"
                   id="sp_2fa_code"
                   name="sp_2fa_code"
                   class="input sp-2fa-input"
                   inputmode="numeric"
                   autocomplete="one-time-code"
                   autocorrect="off"
                   autocapitalize="none"
                   spellcheck="false"
                   required
                   autofocus />
        </p>

        <p>
            <label>
                <input type="checkbox" name="sp_2fa_recovery" value="1" <?php echo $is_recovery_default ? 'checked' : ''; ?> />
                Use a recovery code instead
            </label>
        </p>

        <input type="hidden" name="token" value="<?php echo esc_attr((string) $token); ?>" />
        <input type="hidden" name="redirect_to" value="<?php echo esc_attr((string) $redirect_to); ?>" />
        <input type="hidden" name="<?php echo esc_attr((string) $nonce_field); ?>" value="<?php echo esc_attr((string) $form_nonce); ?>" />

        <p class="submit">
            <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large"
                   value="Verify and continue" />
        </p>

        <div class="sp-2fa-actions">
            <?php if ($is_email_otp) : ?>
                <a href="<?php echo esc_url((string) $resend_url); ?>">
                    Resend code
                </a>
            <?php endif; ?>
            <a href="<?php echo esc_url((string) $login_url); ?>">Back to sign-in</a>
        </div>
    </div>
</form>
