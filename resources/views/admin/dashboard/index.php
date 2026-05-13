<?php

declare(strict_types=1);

use SecurePress\Core\Licensing\LicenseStatus;
use SecurePress\Core\Support\WpHelper;

/**
 * @var array{isPro:bool,status:LicenseStatus}      $license
 * @var array{enabled:bool}                          $auth
 * @var array{enabledCount:int,totalCount:int}      $headers
 * @var array{enabled:bool,openFindings:int}        $integrity
 * @var array{totalEvents:int}                       $audit
 * @var array{authentication:string,headers:string,integrity:string,auditLog:string,license:string} $links
 */

$badge = static function (bool $ok, string $okLabel, string $offLabel): string {
    $bg = $ok ? '#dff5e0' : '#fff1d6';
    $fg = $ok ? '#1f7a3a' : '#7a5400';
    $label = $ok ? $okLabel : $offLabel;

    return '<span style="display:inline-block;padding:2px 10px;border-radius:10px;background:'
        . $bg . ';color:' . $fg . ';font-weight:600;font-size:12px;">'
        . WpHelper::escapeHtml($label) . '</span>';
};

$card = static function (string $title, string $statusHtml, string $bodyHtml, string $href, string $cta): string {
    return '<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:18px 20px;display:flex;flex-direction:column;gap:10px;min-height:140px;">'
        . '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">'
        . '<h2 style="margin:0;font-size:15px;">' . WpHelper::escapeHtml($title) . '</h2>'
        . $statusHtml
        . '</div>'
        . '<div style="color:#50575e;font-size:13px;line-height:1.5;">' . $bodyHtml . '</div>'
        . '<div style="margin-top:auto;">'
        . '<a class="button button-secondary" href="' . WpHelper::escapeUrl($href) . '">'
        . WpHelper::escapeHtml($cta) . ' &rarr;</a>'
        . '</div>'
        . '</div>';
};

$licenseStatus = $license['status'];
$licenseLabel = match ($licenseStatus->state) {
    LicenseStatus::STATE_ACTIVE => 'Active (' . $licenseStatus->tier . ')',
    LicenseStatus::STATE_EXPIRED => 'Expired',
    LicenseStatus::STATE_INVALID => 'Invalid',
    default => 'Not configured',
};

?>
<div class="wrap">
    <h1>Secure Press</h1>
    <p class="description">Status overview for every SecurePress subsystem. Click any tile to manage that area in detail.</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:18px;">
        <?= $card(
            'Authentication',
            $badge($auth['enabled'], 'On', 'Off'),
            'Login lockout, two-factor enforcement, session tracking, and suspicious-login alerts.',
            $links['authentication'],
            'Configure authentication',
        ); ?>

        <?= $card(
            'Security Headers',
            $badge($headers['enabledCount'] > 0, $headers['enabledCount'] . ' of ' . $headers['totalCount'] . ' on', 'All off'),
            'HSTS, CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy, and X-Content-Type-Options.',
            $links['headers'],
            'Configure headers',
        ); ?>

        <?= $card(
            'File Integrity',
            $badge($integrity['enabled'], 'Scanning', 'Disabled'),
            $integrity['openFindings'] > 0
                ? '<strong style="color:#b32d2e;">' . (int) $integrity['openFindings'] . ' open finding' . ($integrity['openFindings'] === 1 ? '' : 's') . '.</strong> Review them before they age out.'
                : 'No open findings. Core, plugins, themes and uploads are clean as of the last scan.',
            $links['integrity'],
            'Review file integrity',
        ); ?>

        <?= $card(
            'Audit Log',
            $badge($audit['totalEvents'] > 0, (string) $audit['totalEvents'] . ' events', 'Empty'),
            'Plugin activations, role changes, file-editor edits, authentication events, and (Pro) WooCommerce decisions.',
            $links['auditLog'],
            'Open audit log',
        ); ?>

        <?= $card(
            'License',
            $badge($license['isPro'], $licenseLabel, $licenseLabel),
            $license['isPro']
                ? 'Pro features are unlocked: WooCommerce protection, file integrity heuristics, advanced audit listeners.'
                : 'Free tier. Apply a license key to unlock the Pro feature set.',
            $links['license'],
            $license['isPro'] ? 'Manage license' : 'Activate Pro',
        ); ?>
    </div>

    <hr style="margin-top:28px;">
    <p class="description">
        Per-user 2FA enrolment and active sessions live under <strong>Account Security</strong> in the sidebar, separate from this admin menu so all logged-in users can reach it.
    </p>
</div>
