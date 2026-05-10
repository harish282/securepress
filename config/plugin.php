<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => 'SecurePress',
        'env' => 'development',
        'debug' => false,
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
    'rate_limit' => [
        'limit' => 60,
        'window' => 60,
    ],
    'signed_url' => [
        'ttl_default' => 3600,
    ],
    'audit_log' => [
        'enabled' => true,
        'retention_days' => 90,
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
    'security_headers' => [
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
