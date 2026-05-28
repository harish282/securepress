<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('NIYIGUARD_FILE')) {
    define('NIYIGUARD_FILE', dirname(__DIR__) . '/niyiguard.php');
}

if (!defined('NIYIGUARD_PATH')) {
    define('NIYIGUARD_PATH', dirname(__DIR__));
}

if (!defined('NIYIGUARD_BOOTSTRAP_PATH')) {
    define('NIYIGUARD_BOOTSTRAP_PATH', NIYIGUARD_PATH . '/bootstrap');
}

if (!defined('NIYIGUARD_CONFIG_PATH')) {
    define('NIYIGUARD_CONFIG_PATH', NIYIGUARD_PATH . '/config');
}

if (!defined('NIYIGUARD_SRC_PATH')) {
    define('NIYIGUARD_SRC_PATH', NIYIGUARD_PATH . '/src');
}

if (!defined('NIYIGUARD_STORAGE_PATH')) {
    define('NIYIGUARD_STORAGE_PATH', NIYIGUARD_PATH . '/storage');
}

if (!defined('NIYIGUARD_LOG_PATH')) {
    $niyiguard_uploads_base = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/uploads' : NIYIGUARD_STORAGE_PATH;
    if (function_exists('wp_upload_dir')) {
        $niyiguard_upload_info = wp_upload_dir();
        if (is_array($niyiguard_upload_info) && !empty($niyiguard_upload_info['basedir']) && is_string($niyiguard_upload_info['basedir'])) {
            $niyiguard_uploads_base = rtrim($niyiguard_upload_info['basedir'], '/');
        }
    }
    define('NIYIGUARD_LOG_PATH', $niyiguard_uploads_base . '/niyiguard/logs');
}

if (!defined('NIYIGUARD_RESOURCES_PATH')) {
    define('NIYIGUARD_RESOURCES_PATH', NIYIGUARD_PATH . '/resources');
}

if (!defined('NIYIGUARD_VIEWS_PATH')) {
    define('NIYIGUARD_VIEWS_PATH', NIYIGUARD_RESOURCES_PATH . '/views');
}

if (!defined('NIYIGUARD_MU_LOADER_FILENAME')) {
    define('NIYIGUARD_MU_LOADER_FILENAME', '00-niyiguard-loader.php');
}

if (!defined('NIYIGUARD_MU_LOADER_TEMPLATE_PATH')) {
    define('NIYIGUARD_MU_LOADER_TEMPLATE_PATH', NIYIGUARD_PATH . '/mu-loader/' . NIYIGUARD_MU_LOADER_FILENAME);
}

/**
 * Emergency recovery: set in wp-config.php before wp-settings.php loads.
 *
 *     define('NIYIGUARD_SAFE_MODE', true);
 *
 * While true, NiyiGuard bypasses login URL disguise, login lockouts, and global
 * rate limiting without changing stored options. Remove after you regain access.
 *
 * Or set `recovery.safe_mode` to true in config/plugin.php; see
 * {@see NIYIGUARD_BOOTSTRAP_PATH}/safe-mode.php.
 */
