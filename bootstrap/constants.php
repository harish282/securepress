<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('PRESS_SENTINEL_FILE')) {
    define('PRESS_SENTINEL_FILE', dirname(__DIR__) . '/press-sentinel.php');
}

if (!defined('PRESS_SENTINEL_PATH')) {
    define('PRESS_SENTINEL_PATH', dirname(__DIR__));
}

if (!defined('PRESS_SENTINEL_BOOTSTRAP_PATH')) {
    define('PRESS_SENTINEL_BOOTSTRAP_PATH', PRESS_SENTINEL_PATH . '/bootstrap');
}

if (!defined('PRESS_SENTINEL_CONFIG_PATH')) {
    define('PRESS_SENTINEL_CONFIG_PATH', PRESS_SENTINEL_PATH . '/config');
}

if (!defined('PRESS_SENTINEL_SRC_PATH')) {
    define('PRESS_SENTINEL_SRC_PATH', PRESS_SENTINEL_PATH . '/src');
}

if (!defined('PRESS_SENTINEL_STORAGE_PATH')) {
    define('PRESS_SENTINEL_STORAGE_PATH', PRESS_SENTINEL_PATH . '/storage');
}

if (!defined('PRESS_SENTINEL_LOG_PATH')) {
    define('PRESS_SENTINEL_LOG_PATH', PRESS_SENTINEL_STORAGE_PATH . '/logs');
}

if (!defined('PRESS_SENTINEL_RESOURCES_PATH')) {
    define('PRESS_SENTINEL_RESOURCES_PATH', PRESS_SENTINEL_PATH . '/resources');
}

if (!defined('PRESS_SENTINEL_VIEWS_PATH')) {
    define('PRESS_SENTINEL_VIEWS_PATH', PRESS_SENTINEL_RESOURCES_PATH . '/views');
}

if (!defined('PRESS_SENTINEL_MU_LOADER_FILENAME')) {
    define('PRESS_SENTINEL_MU_LOADER_FILENAME', '00-press-sentinel-loader.php');
}

if (!defined('PRESS_SENTINEL_MU_LOADER_TEMPLATE_PATH')) {
    define('PRESS_SENTINEL_MU_LOADER_TEMPLATE_PATH', PRESS_SENTINEL_PATH . '/mu-loader/' . PRESS_SENTINEL_MU_LOADER_FILENAME);
}

/**
 * Emergency recovery: set in wp-config.php before wp-settings.php loads.
 *
 *     define('PRESS_SENTINEL_SAFE_MODE', true);
 *
 * While true, PressSentinel bypasses login URL disguise, login lockouts, and global
 * rate limiting without changing stored options. Remove after you regain access.
 *
 * You can also set `PRESS_SENTINEL_SAFE_MODE=true` in the plugin `.env` file; see
 * `.env.example` and {@see PRESS_SENTINEL_BOOTSTRAP_PATH}/safe-mode.php.
 */
