<?php
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.

/**
 * @var \NiyiGuard\Core\Auth\TwoFactor\TwoFactorState $state
 * @var string $method_label
 * @var list<\NiyiGuard\Core\Auth\Sessions\SessionRecord> $sessions
 * @var ?list<string> $recovery_codes
 * @var ?array{secret:string, provisioning_uri:string} $enrolment
 * @var ?array{type:string,message:string} $flash
 * @var string $page_url
 * @var string $nonce_action
 * @var string $nonce
 * @var int $user_id
 * @var ?int $current_session_id
 */

use NiyiGuard\Core\Support\WpHelper;

$flashType = $flash['type'] ?? '';
$flashClass = match ($flashType) {
    'success' => 'notice notice-success',
    'error' => 'notice notice-error',
    'info' => 'notice notice-info',
    default => '',
};
?>
<div class="wrap sp-account-security">
    <h1>Account Security</h1>

    <?php if ($flashClass !== '') : ?>
        <div class="<?php echo esc_attr($flashClass); ?>">
            <p><?php echo esc_html((string) ($flash['message'] ?? '')); ?></p>
        </div>
    <?php endif; ?>

    <h2>Two-factor authentication</h2>
    <p><strong>Status:</strong>
        <?php if ($state->isEnabled()) : ?>
            Enabled (<?php echo esc_html($method_label); ?>)
        <?php else : ?>
            Disabled
        <?php endif; ?>
    </p>

    <?php if (!empty($recovery_codes)) : ?>
        <div class="notice notice-warning">
            <p><strong>Save these recovery codes now.</strong> They will not be shown again. Each code can be used once if you lose access to your primary 2FA method.</p>
            <pre style="background: #fafafa; padding: 12px; border: 1px solid #ddd; font-family: monospace;"><?php
                foreach ($recovery_codes as $code) {
                    echo esc_html((string) $code) . "\n";
                }
            ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($state->isEnabled()) : ?>
        <form method="post" action="<?php echo esc_attr($page_url); ?>" style="display:inline-block;margin-right:8px;">
            <input type="hidden" name="action" value="sp_2fa_disable" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
            <button type="submit" class="button">Disable 2FA</button>
        </form>
        <form method="post" action="<?php echo esc_attr($page_url); ?>" style="display:inline-block;">
            <input type="hidden" name="action" value="sp_2fa_regen" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
            <button type="submit" class="button">Regenerate recovery codes</button>
        </form>
    <?php else : ?>
        <h3>Authenticator app (recommended)</h3>
        <?php if ($enrolment === null) : ?>
            <p>Use Google Authenticator, 1Password, Authy, or any compatible TOTP app.</p>
            <form method="post" action="<?php echo esc_attr($page_url); ?>">
                <input type="hidden" name="action" value="sp_2fa_start_totp" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
                <button type="submit" class="button button-primary">Set up authenticator app</button>
            </form>
        <?php else : ?>
            <p>1. Open your authenticator app and add a new account using either method below.</p>
            <p><strong>Manual entry:</strong>
                <code style="font-size: 14px; padding: 4px 8px;"><?php echo esc_html($enrolment['secret']); ?></code>
            </p>
            <p><strong>Or scan / open this link:</strong><br />
                <a href="<?php echo esc_attr($enrolment['provisioning_uri']); ?>"><?php echo esc_html($enrolment['provisioning_uri']); ?></a>
            </p>
            <p>2. Enter the 6-digit code your app shows to confirm:</p>
            <form method="post" action="<?php echo esc_attr($page_url); ?>">
                <input type="hidden" name="action" value="sp_2fa_confirm_totp" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
                <input type="text" name="sp_2fa_code" inputmode="numeric" autocomplete="one-time-code"
                       placeholder="123456" maxlength="6" style="font-size: 18px; letter-spacing: 4px; width: 140px;" required />
                <button type="submit" class="button button-primary">Confirm and enable</button>
            </form>
        <?php endif; ?>

        <h3 style="margin-top: 32px;">Email one-time code</h3>
        <p>Each sign-in we'll send a fresh 6-digit code to your account email. Use this if you can't (or don't want to) use an authenticator app.</p>
        <form method="post" action="<?php echo esc_attr($page_url); ?>">
            <input type="hidden" name="action" value="sp_2fa_enable_email" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
            <button type="submit" class="button">Enable email 2FA</button>
        </form>
    <?php endif; ?>

    <h2 style="margin-top: 40px;">Active sessions</h2>
    <?php if (empty($sessions)) : ?>
        <p>No active sessions on record.</p>
    <?php else : ?>
        <table class="widefat striped" style="max-width: 900px;">
            <thead>
                <tr>
                    <th>Created</th>
                    <th>Last seen</th>
                    <th>IP</th>
                    <th>Browser</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sessions as $session) : ?>
                    <tr>
                        <td><?php echo esc_html(gmdate('Y-m-d H:i', $session->createdAt)); ?> UTC</td>
                        <td><?php echo esc_html(gmdate('Y-m-d H:i', $session->lastSeenAt)); ?> UTC</td>
                        <td><?php echo esc_html($session->ip ?? '—'); ?></td>
                        <td><?php echo esc_html(mb_substr($session->userAgent ?? '—', 0, 80)); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_attr($page_url); ?>" style="display:inline;">
                                <input type="hidden" name="action" value="sp_2fa_session_revoke" />
                                <input type="hidden" name="session_id" value="<?php echo (int) $session->id; ?>" />
                                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
                                <button type="submit" class="button button-link-delete">Revoke</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_attr($page_url); ?>" style="margin-top: 12px;">
            <input type="hidden" name="action" value="sp_2fa_session_revoke_others" />
            <?php if ($current_session_id !== null) : ?>
                <input type="hidden" name="current_session_id" value="<?php echo (int) $current_session_id; ?>" />
            <?php endif; ?>
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
            <button type="submit" class="button">Revoke all sessions</button>
        </form>
    <?php endif; ?>
</div>
