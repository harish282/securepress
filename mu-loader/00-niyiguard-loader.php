<?php
/**
 * Plugin Name: NiyiGuard Early Loader (MU)
 * Description: Loads NiyiGuard from must-use plugins for earliest request interception.
 * Version: 0.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$pluginsDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : ABSPATH . 'wp-content/plugins');

$pressSentinelBootstrap = $pluginsDir . '/niyiguard/niyiguard.php';
if (!is_readable($pressSentinelBootstrap)) {
    $pressSentinelBootstrap = $pluginsDir . '/niyiguard/niyiguard.php';
}
if (!is_readable($pressSentinelBootstrap)) {
    $pressSentinelBootstrap = $pluginsDir . '/niyiguard/niyiguard.php';
}

if (is_readable($pressSentinelBootstrap)) {
    require_once $pressSentinelBootstrap;
}
