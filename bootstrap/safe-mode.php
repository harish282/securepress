<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Defines {@see PRESS_SENTINEL_SAFE_MODE} from config when wp-config.php did not set it.
 *
 * Precedence: existing `define()` in wp-config.php wins; otherwise
 * `recovery.safe_mode` in config/plugin.php.
 */
if (!defined('PRESS_SENTINEL_SAFE_MODE')) {
    $presssentinel_config_file = PRESS_SENTINEL_CONFIG_PATH . '/plugin.php';
    $presssentinel_config = is_readable($presssentinel_config_file) ? require $presssentinel_config_file : [];
    $presssentinel_safe_mode_enabled = is_array($presssentinel_config)
        && is_array($presssentinel_config['recovery'] ?? null)
        && ($presssentinel_config['recovery']['safe_mode'] ?? false);

    if ($presssentinel_safe_mode_enabled) {
        define('PRESS_SENTINEL_SAFE_MODE', true);
    }
}
