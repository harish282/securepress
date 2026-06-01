<?php

/**
 * Plugin Name: NiyiGuard
 * Description: Laravel-inspired security infrastructure for WordPress.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Author: Niyish Technologies
 * Plugin URI: https://github.com/harish282/securepress
 * Author URI: https://github.com/harish282
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: niyiguard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/bootstrap/constants.php';
require_once NIYIGUARD_BOOTSTRAP_PATH . '/safe-mode.php';
require_once NIYIGUARD_SRC_PATH . '/Core/Support/Autoloader.php';
require_once NIYIGUARD_SRC_PATH . '/Core/Plugin.php';

\NiyiGuard\Core\Support\Autoloader::register();

if (!defined('NIYIGUARD_BOOTED')) {
    define('NIYIGUARD_BOOTED', true);
    (new \NiyiGuard\Core\Plugin())->boot();
}
