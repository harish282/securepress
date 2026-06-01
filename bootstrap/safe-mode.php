<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines {@see NIYIGUARD_SAFE_MODE} from config when wp-config.php did not set it.
 *
 * Precedence: existing `define()` in wp-config.php wins; otherwise
 * `recovery.safe_mode` in config/plugin.php.
 */
if (!defined('NIYIGUARD_SAFE_MODE')) {
    $niyiguard_config_file = NIYIGUARD_CONFIG_PATH . '/plugin.php';
    $niyiguard_config = is_readable($niyiguard_config_file) ? require $niyiguard_config_file : [];
    $niyiguard_safe_mode_enabled = is_array($niyiguard_config)
        && is_array($niyiguard_config['recovery'] ?? null)
        && ($niyiguard_config['recovery']['safe_mode'] ?? false);

    if ($niyiguard_safe_mode_enabled) {
        define('NIYIGUARD_SAFE_MODE', true);
    }
}
