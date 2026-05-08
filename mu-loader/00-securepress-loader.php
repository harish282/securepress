<?php
/**
 * Plugin Name: SecurePress Early Loader (MU)
 * Description: Loads SecurePress from must-use plugins for earliest request interception.
 * Version: 0.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$securePressBootstrap = WP_PLUGIN_DIR . '/secure-press/securepress.php';

if (is_readable($securePressBootstrap)) {
    require_once $securePressBootstrap;
}
