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
    $configFile = PRESS_SENTINEL_CONFIG_PATH . '/plugin.php';
    $config = is_readable($configFile) ? require $configFile : [];
    $enabled = is_array($config)
        && is_array($config['recovery'] ?? null)
        && ($config['recovery']['safe_mode'] ?? false);

    if ($enabled) {
        define('PRESS_SENTINEL_SAFE_MODE', true);
    }
}
