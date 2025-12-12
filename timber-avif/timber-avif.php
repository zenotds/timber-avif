<?php
/**
 * Plugin Name: Timber AVIF Converter
 * Description: Simple Timber helper that generates AVIF/WebP variants on upload, exposes Twig helpers and ships a responsive macro.
 * Version: 4.0.0
 * Author: Francesco Zeno Selva
 * License: GPL v2 or later
 * Requires at least: 5.0
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TIMBER_AVIF_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('TIMBER_AVIF_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Load converter (shared with theme version)
 */
add_action('plugins_loaded', function () {
    if (!class_exists('AVIFConverter')) {
        require_once TIMBER_AVIF_PLUGIN_PATH . 'includes/avif-converter.php';
    }
});

/**
 * Make plugin macros discoverable by Timber
 */
add_filter('timber/loader/paths', function ($paths) {
    if (!isset($paths['macros'])) {
        $paths['macros'] = [];
    }
    $paths['macros'][] = TIMBER_AVIF_PLUGIN_PATH . 'macros';
    return $paths;
});

/**
 * Activation guard for PHP version
 */
register_activation_hook(__FILE__, function () {
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            '<h1>Timber AVIF Converter</h1><p>This plugin requires PHP 8.1 or higher.</p>',
            'Plugin Activation Error',
            ['back_link' => true]
        );
    }
});
