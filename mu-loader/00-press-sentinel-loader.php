<?php
/**
 * Plugin Name: PressSentinel Early Loader (MU)
 * Description: Loads PressSentinel from must-use plugins for earliest request interception.
 * Version: 0.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$pressSentinelBootstrap = WP_PLUGIN_DIR . '/press-sentinel/press-sentinel.php';

if (is_readable($pressSentinelBootstrap)) {
    require_once $pressSentinelBootstrap;
}
