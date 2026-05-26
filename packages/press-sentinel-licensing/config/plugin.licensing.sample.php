<?php

declare(strict_types=1);

/**
 * Merge these keys into your host plugin's config/plugin.php.
 */
return [
    'pro_license' => [
        'license_key' => '',
        'early_access' => false,
        'beta_trial' => [
            'enabled' => false,
            'duration_days' => 182,
        ],
    ],
    'licensing' => [
        'secret' => 'change-me-in-production',
    ],
];
