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

$niyiguard_plugins_dir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : ABSPATH . 'wp-content/plugins');

$niyiguard_bootstrap_candidates = [
    $niyiguard_plugins_dir . '/niyiguard/niyiguard.php',
    $niyiguard_plugins_dir . '/secure-press/niyiguard.php',
];

$niyiguard_bootstrap = '';
foreach ($niyiguard_bootstrap_candidates as $niyiguard_candidate) {
    if (is_readable($niyiguard_candidate)) {
        $niyiguard_bootstrap = $niyiguard_candidate;
        break;
    }
}

if ($niyiguard_bootstrap !== '') {
    require_once $niyiguard_bootstrap;
}
