<?php

declare(strict_types=1);
if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View template locals, not globals.

use NiyiGuard\Admin\FeatureDescriptor;
use NiyiGuard\Core\Support\WpHelper;

/**
 * @var array{enabled:bool}                                                  $auth
 * @var array{enabledCount:int,totalCount:int,masterEnabled:bool}           $headers
 * @var array{enabled:bool,openFindings:int}                                 $integrity
 * @var array{totalEvents:int}                                               $audit
 * @var array{list:list<FeatureDescriptor>,formAction:string,nonceAction:string,actionName:string} $features
 * @var array{review_url:string,donation_url:string,donation_label:string} $support
 * @var string|null                                                          $status
 * @var array{authentication:string,headers:string,integrity:string,auditLog:string} $links
 * @var array{isInstalled:bool,expectedPath:string,expectedDirectory:string,loaderFilename:string,downloadAction:string,downloadUrl:string} $muLoader
 */

$badge = static function (bool $ok, string $okLabel, string $offLabel): string {
    $bg = $ok ? '#dff5e0' : '#fff1d6';
    $fg = $ok ? '#1f7a3a' : '#7a5400';
    $label = $ok ? $okLabel : $offLabel;

    return '<span style="display:inline-block;padding:2px 10px;border-radius:10px;background:'
        . $bg . ';color:' . $fg . ';font-weight:600;font-size:12px;">'
        . esc_html($label) . '</span>';
};

$card = static function (string $title, string $statusHtml, string $bodyHtml, ?string $href, string $cta): string {
    return '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 20px;display:flex;flex-direction:column;gap:10px;min-height:140px;">'
        . '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">'
        . '<h2 style="margin:0;font-size:15px;">' . esc_html($title) . '</h2>'
        . $statusHtml
        . '</div>'
        . '<div style="color:#50575e;font-size:13px;line-height:1.5;">' . $bodyHtml . '</div>'
        . ($href !== null && $href !== '' && $cta !== ''
            ? '<div style="margin-top:auto;">'
                . '<a class="button button-secondary" href="' . esc_url($href) . '">'
                . esc_html($cta) . ' &rarr;</a>'
                . '</div>'
            : '')
        . '</div>';
};

$savedCount = null;
if (is_string($status) && str_starts_with($status, 'saved:')) {
    $candidate = substr($status, strlen('saved:'));
    if (ctype_digit($candidate)) {
        $savedCount = (int) $candidate;
    }
}

$reviewUrl = $support['review_url'] ?? '';
$donationUrl = $support['donation_url'] ?? '';
$donationLabel = $support['donation_label'] ?? 'Support on Ko-fi';

?>
<div class="wrap">
    <h1>NiyiGuard</h1>
    <p class="description">Status overview for every NiyiGuard subsystem. Toggle a feature on or off below, or click any tile to manage that area in detail.</p>

    <?php if (!$muLoader['isInstalled']): ?>
        <div style="background:#fff;border:1px solid #f0b849;border-left:4px solid #f0b849;border-radius:6px;padding:18px 22px;margin-top:16px;">
            <h2 style="margin:0 0 6px;font-size:15px;color:#7a5400;">
                MU loader not installed &mdash; NiyiGuard is loading later than it could
            </h2>
            <p style="margin:0 0 12px;color:#50575e;">
                <strong>Why this matters:</strong>
                WordPress loads must-use plugins (<code>wp-content/mu-plugins/</code>) <em>before</em> regular plugins, themes, and the request router. Installing the NiyiGuard MU loader lets us inspect incoming requests, rate-limit traffic, and apply security headers at the earliest possible point in the WordPress lifecycle &mdash; catching malicious traffic that would otherwise reach plugin code first.
            </p>
            <p style="margin:0 0 14px;color:#50575e;">
                <strong>How to install (under a minute):</strong>
            </p>
            <ol style="margin:0 0 14px 22px;color:#50575e;line-height:1.7;">
                <li>Click <strong>Download MU loader (.zip)</strong> below.</li>
                <li>Extract the archive. You'll get one file: <code><?php echo esc_html($muLoader['loaderFilename']) ?></code>.</li>
                <li>Upload it (via SFTP, your host's File Manager, or <code>wp cli</code>) to:<br>
                    <code style="display:inline-block;margin-top:4px;padding:4px 8px;background:#f6f7f7;border-radius:3px;"><?php echo esc_html($muLoader['expectedDirectory']) ?>/</code><br>
                    If the <code>mu-plugins</code> folder doesn't exist, create it &mdash; WordPress will pick it up automatically.</li>
                <li>Reload this page. The callout will disappear once NiyiGuard detects the loader.</li>
            </ol>
            <form method="post"
                  action="<?php echo esc_url($muLoader['downloadUrl']) ?>"
                  style="display:inline-flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="action" value="<?php echo esc_attr($muLoader['downloadAction']) ?>">
                <?php WpHelper::adminNonceField($muLoader['downloadAction']); ?>
                <button type="submit" class="button button-primary">
                    Download MU loader (.zip)
                </button>
                <span style="color:#50575e;font-size:12px;">
                    Contents: <code><?php echo esc_html($muLoader['loaderFilename']) ?></code> + <code>INSTALL.txt</code>.
                </span>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($savedCount !== null): ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php if ($savedCount === 0): ?>
                    No feature changes to apply &mdash; everything is already in the requested state.
                <?php else: ?>
                    <strong><?php echo (int) $savedCount ?></strong>
                    feature<?php echo $savedCount === 1 ? '' : 's' ?>
                    updated.
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <h2 style="margin-top:28px;">Enjoying NiyiGuard?</h2>
    <p class="description">NiyiGuard is free and open source. A short review or a small donation helps other site owners discover it and supports ongoing development.</p>
    <div style="display:grid;grid-template-columns:repeat(2,minmax(280px,1fr));gap:16px;margin-top:8px;">
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;display:flex;flex-direction:column;gap:12px;">
            <h3 style="margin:0;font-size:15px;">Leave a review</h3>
            <p style="margin:0;color:#50575e;font-size:13px;line-height:1.6;">
                Honest ratings on WordPress.org help other administrators choose security tools they can trust.
                If NiyiGuard saved you time or caught something important, please share your experience.
            </p>
            <?php if ($reviewUrl !== ''): ?>
                <p style="margin:0;">
                    <a class="button button-primary" href="<?php echo esc_url($reviewUrl) ?>" target="_blank" rel="noopener noreferrer">
                        Rate NiyiGuard on WordPress.org
                    </a>
                </p>
            <?php else: ?>
                <p class="description" style="margin:0;">Set <code>support.review_url</code> in <code>config/plugin.php</code> to show the review button.</p>
            <?php endif; ?>
        </div>
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;display:flex;flex-direction:column;gap:12px;">
            <h3 style="margin:0;font-size:15px;">Support development</h3>
            <p style="margin:0;color:#50575e;font-size:13px;line-height:1.6;">
                Donations are optional and go toward maintenance, documentation, and new security features.
                Tips are handled on <a href="https://ko-fi.com/" target="_blank" rel="noopener noreferrer">Ko-fi</a>.
            </p>
            <?php if ($donationUrl !== ''): ?>
                <p style="margin:0;">
                    <a class="button button-primary" href="<?php echo esc_url($donationUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($donationLabel) ?>
                    </a>
                </p>
            <?php else: ?>
                <p class="description" style="margin:0;">
                    Set <code>support.donation_url</code> in <code>config/plugin.php</code> (for example your Ko-fi page).
                </p>
            <?php endif; ?>
        </div>
    </div>

    <h2 style="margin-top:32px;">Feature toggles</h2>
    <p class="description">Turn entire NiyiGuard modules on or off in one click. Detailed per-module settings stay on each module's dedicated page &mdash; this only flips the master switch.</p>

    <form method="post" action="<?php echo esc_url($features['formAction']) ?>"
          style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px;margin-top:8px;">
        <input type="hidden" name="action" value="<?php echo esc_attr($features['actionName']) ?>">
        <?php WpHelper::adminNonceField($features['nonceAction']); ?>

        <table class="form-table" role="presentation" style="margin-top:0;">
            <tbody>
            <?php foreach ($features['list'] as $feature): ?>
                <?php $isOn = $feature->isEnabled(); ?>
                <tr>
                    <th scope="row" style="padding-left:0;">
                        <label for="niyiguard_feature_<?php echo esc_attr($feature->key) ?>" style="display:block;">
                            <strong><?php echo esc_html($feature->label) ?></strong>
                        </label>
                    </th>
                    <td>
                        <label style="display:inline-flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox"
                                   id="niyiguard_feature_<?php echo esc_attr($feature->key) ?>"
                                   name="features[<?php echo esc_attr($feature->key) ?>]"
                                   value="1"
                                   <?php echo $isOn ? 'checked' : '' ?>>
                            <span><?php echo esc_html($feature->description) ?></span>
                        </label>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="submit" class="button button-primary">Save feature toggles</button>
        </p>
    </form>

    <h2 style="margin-top:32px;">Status overview</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:8px;">
        <?php echo wp_kses_post($card(
            'Authentication',
            $badge($auth['enabled'], 'On', 'Off'),
            'Login lockout, two-factor enforcement, session tracking, and suspicious-login alerts.',
            $links['authentication'],
            'Configure authentication',
        )); ?>

        <?php echo wp_kses_post($card(
            'Security Headers',
            $headers['masterEnabled']
                ? $badge($headers['enabledCount'] > 0, $headers['enabledCount'] . ' of ' . $headers['totalCount'] . ' on', 'All off')
                : $badge(false, 'On', 'Off'),
            'HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, and X-Content-Type-Options.',
            $links['headers'],
            'Configure headers',
        )); ?>

        <?php echo wp_kses_post($card(
            'File Integrity',
            $badge($integrity['enabled'], 'Scanning', 'Disabled'),
            $integrity['openFindings'] > 0
                ? '<strong style="color:#b32d2e;">' . (int) $integrity['openFindings'] . ' open finding' . ($integrity['openFindings'] === 1 ? '' : 's') . '.</strong> Review them before they age out.'
                : 'No open findings. Core, plugins, themes and uploads are clean as of the last scan.',
            $links['integrity'],
            'Review file integrity',
        )); ?>

        <?php echo wp_kses_post($card(
            'Audit Log',
            $badge($audit['totalEvents'] > 0, (string) $audit['totalEvents'] . ' events', 'Empty'),
            'Plugin activations, role changes, file-editor edits, authentication events, and WooCommerce decisions.',
            $links['auditLog'],
            'Open audit log',
        )); ?>
    </div>

    <hr style="margin-top:28px;">
    <p class="description">
        Per-user 2FA enrolment and active sessions live under <strong>Account Security</strong> in the sidebar, separate from this admin menu so all logged-in users can reach it.
    </p>
</div>
