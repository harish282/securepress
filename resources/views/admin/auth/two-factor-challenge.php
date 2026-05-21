<?php
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
 * @var string $submit_action
 */

use PressSentinel\Core\Support\WpHelper;

$method = (string) $method;
$is_email_otp = $method === 'email_otp';
$is_recovery_default = false;

$minutes_remaining = max(1, (int) ceil(((int) $remaining_seconds) / 60));
?>
<style>
    .sp-2fa-card { padding: 0 12px; }
    .sp-2fa-card h1 { font-size: 18px; margin: 0 0 12px; }
    .sp-2fa-meta { color: #555; margin: 0 0 16px; font-size: 13px; }
    .sp-2fa-input { font-size: 22px; letter-spacing: 8px; text-align: center; padding: 10px; width: 100%; box-sizing: border-box; }
    .sp-2fa-actions { display: flex; flex-direction: column; gap: 6px; margin-top: 14px; font-size: 13px; }
    .sp-2fa-error { color: #b32d2e; padding: 8px 12px; background: #fdecea; border-left: 4px solid #b32d2e; margin-bottom: 12px; }
    .sp-2fa-info { color: #1f5582; padding: 8px 12px; background: #e6f3ff; border-left: 4px solid #1f5582; margin-bottom: 12px; }
</style>

<form name="sp_2fa_form" id="loginform" action="<?php echo WpHelper::escapeAttribute((string) $submit_action); ?>" method="post">
    <div class="sp-2fa-card">
        <h1>Two-factor authentication</h1>
        <p class="sp-2fa-meta">
            Method: <strong><?php echo WpHelper::escapeHtml((string) $method_label); ?></strong>
            &middot; expires in ~<?php echo (int) $minutes_remaining; ?> min
        </p>

        <?php if (!empty($error)) : ?>
            <div class="sp-2fa-error"><?php echo WpHelper::escapeHtml((string) $error); ?></div>
        <?php endif; ?>

        <?php if (!empty($info)) : ?>
            <div class="sp-2fa-info"><?php echo WpHelper::escapeHtml((string) $info); ?></div>
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

        <input type="hidden" name="token" value="<?php echo WpHelper::escapeAttribute((string) $token); ?>" />
        <input type="hidden" name="redirect_to" value="<?php echo WpHelper::escapeAttribute((string) $redirect_to); ?>" />

        <p class="submit">
            <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large"
                   value="Verify and continue" />
        </p>

        <div class="sp-2fa-actions">
            <?php if ($is_email_otp) : ?>
                <a href="<?php echo WpHelper::escapeUrl((string) $submit_action . '&token=' . rawurlencode((string) $token) . '&resend=1'); ?>">
                    Resend code
                </a>
            <?php endif; ?>
            <a href="<?php echo WpHelper::escapeUrl((string) $login_url); ?>">Back to sign-in</a>
        </div>
    </div>
</form>
