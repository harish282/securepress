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

$niyiguard_should_boot = false;
if ($niyiguard_bootstrap !== '' && function_exists('get_option')) {
    $niyiguard_plugin_slug = function_exists('plugin_basename')
        ? plugin_basename($niyiguard_bootstrap)
        : basename(dirname($niyiguard_bootstrap)) . '/' . basename($niyiguard_bootstrap);

    $niyiguard_active_plugins = get_option('active_plugins', []);
    if (is_array($niyiguard_active_plugins) && in_array($niyiguard_plugin_slug, $niyiguard_active_plugins, true)) {
        $niyiguard_should_boot = true;
    } elseif (
        function_exists('is_multisite')
        && is_multisite()
        && function_exists('get_site_option')
    ) {
        $niyiguard_network_active = get_site_option('active_sitewide_plugins', []);
        if (is_array($niyiguard_network_active) && isset($niyiguard_network_active[$niyiguard_plugin_slug])) {
            $niyiguard_should_boot = true;
        }
    }
}

if ($niyiguard_should_boot) {
    require_once $niyiguard_bootstrap;
}
