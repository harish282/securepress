<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('SECUREPRESS_FILE')) {
    define('SECUREPRESS_FILE', dirname(__DIR__) . '/securepress.php');
}

if (!defined('SECUREPRESS_PATH')) {
    define('SECUREPRESS_PATH', dirname(__DIR__));
}

if (!defined('SECUREPRESS_BOOTSTRAP_PATH')) {
    define('SECUREPRESS_BOOTSTRAP_PATH', SECUREPRESS_PATH . '/bootstrap');
}

if (!defined('SECUREPRESS_CONFIG_PATH')) {
    define('SECUREPRESS_CONFIG_PATH', SECUREPRESS_PATH . '/config');
}

if (!defined('SECUREPRESS_SRC_PATH')) {
    define('SECUREPRESS_SRC_PATH', SECUREPRESS_PATH . '/src');
}

if (!defined('SECUREPRESS_STORAGE_PATH')) {
    define('SECUREPRESS_STORAGE_PATH', SECUREPRESS_PATH . '/storage');
}

if (!defined('SECUREPRESS_LOG_PATH')) {
    define('SECUREPRESS_LOG_PATH', SECUREPRESS_STORAGE_PATH . '/logs');
}

if (!defined('SECUREPRESS_RESOURCES_PATH')) {
    define('SECUREPRESS_RESOURCES_PATH', SECUREPRESS_PATH . '/resources');
}

if (!defined('SECUREPRESS_VIEWS_PATH')) {
    define('SECUREPRESS_VIEWS_PATH', SECUREPRESS_RESOURCES_PATH . '/views');
}

if (!defined('SECUREPRESS_MU_LOADER_FILENAME')) {
    define('SECUREPRESS_MU_LOADER_FILENAME', '00-securepress-loader.php');
}

if (!defined('SECUREPRESS_MU_LOADER_TEMPLATE_PATH')) {
    define('SECUREPRESS_MU_LOADER_TEMPLATE_PATH', SECUREPRESS_PATH . '/mu-loader/' . SECUREPRESS_MU_LOADER_FILENAME);
}
