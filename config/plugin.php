<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'SecurePress',
        'env' => 'development',
        'debug' => false,
    ],
    /**
     * Pro licensing + optional public beta trial.
     *
     * Beta trial: when `beta_trial.enabled` is true and no valid license key is
     * configured, the install receives Pro capabilities for `duration_days` from
     * the first request that evaluates licensing (a `securepress_beta_trial_started_at`
     * timestamp is written once). Override at deploy time with
     * `SECUREPRESS_BETA_TRIAL_ENABLED=true|false`. PHPUnit forces it off in
     * `tests/bootstrap.php` so the suite stays deterministic.
     */
    'pro_license' => [
        'beta_trial' => [
            'enabled' => true,
            // ~6 calendar months (182 d). Clamped at runtime to 1–730 days.
            'duration_days' => 182,
        ],
    ],
    'requirements' => [
        'php' => '8.2.0',
        'wordpress' => '6.4',
    ],
    'logging' => [
        'channel' => 'file',
        'level' => 'info',
        'file' => 'securepress.log',
    ],
    /**
     * Disguise default `wp-login.php` behind a custom URL slug. Master
     * `enabled` is mirrored on the dashboard; slug is configured on
     * SecurePress → URL disguise. Off by default — enabling without saving
     * permalinks / slug can lock admins out.
     */
    'url_disguise' => [
        'enabled' => false,
        'login_slug' => '',
        // When disguise is active and true, direct wp-login.php gets HTTP 404 (no redirect).
        'block_default_wp_login' => true,
    ],
    'rate_limit' => [
        // Master switch. Mirrored on the SecurePress dashboard's feature
        // toggle list and the dedicated Rate Limiting settings page.
        'enabled' => true,
        // Requests allowed per `window` seconds, per bucket
        // (per-user when authenticated, per-IP otherwise).
        'limit' => 60,
        'window' => 60,
    ],
    'signed_url' => [
        'ttl_default' => 3600,
    ],
    'audit_log' => [
        'enabled' => true,
        'retention_days' => 90,
        'auto_prune_enabled' => true,
        // Events below this PSR-3 level are not inserted into wp_securepress_audit_logs.
        'min_storage_level' => 'notice',
        'mirror_to_file_logger' => false,
        'listeners' => [
            'auth' => true,
            'plugin' => true,
            'user' => true,
            'options' => true,
            'file_editor' => true,
            'woocommerce' => true,
        ],
        'option_allowlist' => [
            'siteurl',
            'home',
            'admin_email',
            'users_can_register',
            'default_role',
            'blogname',
            'blogdescription',
            'wp_user_roles',
            'permalink_structure',
            'template',
            'stylesheet',
        ],
    ],
    'auth_hardening' => [
        'enabled' => true,

        'two_factor' => [
            // The HMAC issuer string baked into provisioning URIs — shows up in the
            // user's authenticator app (e.g., "SecurePress: alice@example.com").
            'issuer' => 'SecurePress',
            // How long a pending 2FA challenge stays valid after the user submits
            // their password but before they enter the code.
            'challenge_ttl_seconds' => 600,
        ],

        'lockout' => [
            'enabled' => true,
            // Threshold and window for the rolling failure counter.
            'max_attempts' => 5,
            'window_seconds' => 900,
            // How long an account / IP stays locked after the threshold is crossed.
            'lock_seconds' => 900,
        ],

        'sessions' => [
            'enabled' => true,
            // Sessions older than this (relative to last_seen / revoked_at) are
            // hard-deleted by the daily pruner cron.
            'retention_days' => 90,
        ],

        'suspicion' => [
            'enabled' => true,
            // Minimum score from the rule engine before we email a "new device" alert.
            'alert_threshold' => 50,
            'rules' => [
                'new_device' => true,
            ],
        ],

        'notifications' => [
            // Globally disable outbound email if the site is in dry-run / staging.
            'enabled' => true,
        ],
    ],
    'licensing' => [
        // The HMAC secret used to verify offline-issued license keys.
        // In production, override via the SECUREPRESS_LICENSE_SECRET env var so the
        // secret never lands in repo or backups.
        'secret' => 'change-me-in-production',
    ],

    'woocommerce_protection' => [
        // Module master switch. Even on Pro installs you can flip this to disable
        // every WC pipeline atomically.
        'enabled' => true,

        'checkout' => [
            'enabled' => true,
            // VelocityDetectionMiddleware:
            // Soft = signal; hard = block. Window in seconds.
            'velocity_soft' => 3,
            'velocity_hard' => 8,
            'velocity_window' => 120,
            // Checkout timing: set min_seconds_to_submit > 0 to enable. Default action
            // is report (audit log + fraud score only). Use timing_action "block" only
            // when the admin explicitly wants instant rejection.
            'min_seconds_to_submit' => 0,
            'timing_action' => 'report',
            'honeypot_field_name' => 'securepress_hp',
            'bot' => [
                // Extra User-Agent substrings to flag as scanners. The middleware
                // already ships with sqlmap/nikto/wpscan/curl/wget/etc.
                'extra_scanner_uas' => [],
                // Weights for each bot signal. The 200-weight ones short-circuit
                // with DENY; the lower ones only contribute to the fraud score.
                'weight_honeypot' => 200,
                'weight_scanner_ua' => 200,
                'weight_impossible_timing' => 30,
                'weight_empty_ua' => 35,
                'weight_missing_referer' => 15,
            ],
            // FraudScoreService thresholds (sum-of-signal-weights).
            'fraud' => [
                'challenge_threshold' => 40,
                'deny_threshold' => 80,
            ],
        ],

        'registration' => [
            'enabled' => true,
            // RegistrationRateLimitMiddleware: per-IP fixed window.
            'rate_limit' => 5,
            'window' => 600,
            // Hard-deny disposable email domains on registration.
            'deny_disposable_emails' => true,
            // HoneypotMiddleware: hidden field name + min seconds to submit.
            'honeypot_field_name' => 'securepress_hp',
            'min_seconds_to_submit' => 0,
        ],

        'api' => [
            'enabled' => true,
            // ApiRateLimitMiddleware defaults — used when a route has no per-route override.
            'default_limit' => 60,
            'default_window' => 60,
            // SuspiciousRequestMiddleware behaviour.
            'pass_when_authenticated' => true,
            'deny_on_scanner_ua' => true,
            // Per-route overrides: route prefix → { limit, window }. Examples:
            //   '/wc/store/cart' => ['limit' => 120, 'window' => 60],
            //   '/wc/v3/customers' => ['limit' => 20, 'window' => 60],
            'per_route' => [],
        ],

        'cart' => [
            'enabled' => true,
            // CartVelocityMiddleware soft/hard thresholds + window.
            'velocity_soft' => 20,
            'velocity_hard' => 60,
            'window' => 60,
            // CouponAbuseMiddleware thresholds (failed attempts).
            'coupon_soft' => 4,
            'coupon_hard' => 10,
        ],
    ],

    'integrity' => [
        // Master switch for file-integrity monitoring (scanners, scheduler, admin page).
        'enabled' => true,

        // Per-scope toggles. Themes default off because legitimate developers edit them
        // constantly; uploads default on because PHP files there are always suspicious.
        'scan_core' => true,
        'scan_plugins' => true,
        'scan_themes' => false,
        'scan_uploads' => true,

        'cron' => [
            'enabled' => true,
            // 'hourly' | 'twicedaily' | 'daily'. Anything else falls back to 'daily'.
            'recurrence' => 'daily',
        ],

        'notifications' => [
            'enabled' => true,
            // Only email when at least one finding at or above this severity appeared.
            'min_severity' => 'high',
            // Email recipients. Defaults to the WordPress admin email when empty.
            'recipients' => [],
        ],

        // Findings older than this are pruned by the daily cron. 0 disables pruning.
        'retention_days' => 60,
    ],
    'security_headers' => [
        // Master switch for the entire feature. When false, no header is emitted
        // regardless of the per-header `enabled` flags. Lets the SecurePress
        // dashboard turn the whole module off in one click without zeroing the
        // per-header config (which an admin may want to keep for later).
        'enabled' => true,
        'hsts' => [
            'enabled' => false,
            'max_age' => 31536000,
            'include_subdomains' => false,
            'preload' => false,
        ],
        'csp' => [
            'enabled' => false,
            'policy' => "default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; connect-src 'self'",
            'report_only' => true,
        ],
        'x_frame_options' => [
            'enabled' => true,
            'value' => 'SAMEORIGIN',
        ],
        'referrer_policy' => [
            'enabled' => true,
            'policy' => 'strict-origin-when-cross-origin',
        ],
        'permissions_policy' => [
            'enabled' => true,
            'policy' => 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), accelerometer=(), gyroscope=(), magnetometer=(), interest-cohort=()',
        ],
        'x_content_type_options' => [
            'enabled' => true,
        ],
    ],
];
