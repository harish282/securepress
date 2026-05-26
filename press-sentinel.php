<?php

/**
 * Plugin Name: PressSentinel
 * Plugin URI: https://github.com/harish282/presssentinel
 * Description: Laravel-inspired security infrastructure for WordPress.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Niyish Technologies
 * Author URI: https://github.com/harish282
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: presssentinel
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/bootstrap/constants.php';
require_once PRESS_SENTINEL_BOOTSTRAP_PATH . '/safe-mode.php';
require_once PRESS_SENTINEL_SRC_PATH . '/Core/Support/Autoloader.php';
require_once PRESS_SENTINEL_SRC_PATH . '/Core/Plugin.php';

\PressSentinel\Core\Support\Autoloader::register();

if (!defined('PRESS_SENTINEL_BOOTED')) {
    define('PRESS_SENTINEL_BOOTED', true);
    (new \PressSentinel\Core\Plugin())->boot();
}
