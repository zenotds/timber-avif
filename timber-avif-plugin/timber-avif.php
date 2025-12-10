<?php
/**
 * Plugin Name: Timber AVIF Converter
 * Plugin URI: https://github.com/zenotds/timber-avif
 * Description: High-performance AVIF and WebP image conversion for Timber with auto-generation, smart quality, and comprehensive admin controls.
 * Version: 3.0.0
 * Author: Francesco Zeno Selva
 * Author URI: https://github.com/zenotds
 * Requires at least: 5.0
 * Requires PHP: 8.1
 * License: MIT
 * Text Domain: timber-avif
 *
 * @package TimberAVIF
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TIMBER_AVIF_VERSION', '3.0.0');
define('TIMBER_AVIF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TIMBER_AVIF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TIMBER_AVIF_PLUGIN_FILE', __FILE__);

/**
 * Check requirements on activation
 */
register_activation_hook(__FILE__, function() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Timber AVIF Converter requires PHP 8.1 or higher.');
    }

    // Note: We can't check for Timber here as it might be loaded via Composer in theme
    // The check will happen in timber_avif_init() instead
});

/**
 * Load plugin files
 */
require_once TIMBER_AVIF_PLUGIN_DIR . 'includes/class-converter.php';
require_once TIMBER_AVIF_PLUGIN_DIR . 'includes/class-admin.php';

/**
 * Initialize the plugin
 */
function timber_avif_init() {
    // Check if Timber is available
    // Use after_setup_theme priority to ensure theme's Composer autoload has run
    if (!class_exists('Timber\Timber') && !class_exists('Timber')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Timber AVIF Converter:</strong> Timber 2.0+ is required. ';
            echo 'Install via Composer in your theme: <code>composer require timber/timber</code>';
            echo '</p></div>';
        });
        return;
    }

    // Initialize converter
    Timber_AVIF_Converter::init();

    // Initialize admin if in admin area
    if (is_admin()) {
        Timber_AVIF_Admin::init();
    }
}
// Use after_setup_theme with priority 5 to run EARLY, before theme's twig.php
// This ensures we register our filters before any other timber/twig hooks
add_action('after_setup_theme', 'timber_avif_init', 5);

/**
 * Add settings link on plugins page
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=timber-avif-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

/**
 * WP-CLI Commands
 */
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('timber-avif clear-cache', ['Timber_AVIF_Converter', 'clear_cache']);
    WP_CLI::add_command('timber-avif cleanup', ['Timber_AVIF_Converter', 'cleanup_invalid_files']);

    WP_CLI::add_command('timber-avif detect', function() {
        $method = Timber_AVIF_Converter::detect_capabilities();
        WP_CLI::success("Detected conversion method: {$method}");
    });

    WP_CLI::add_command('timber-avif stats', function() {
        $stats = get_option('timber_avif_stats', []);

        WP_CLI::log("=== Timber AVIF Statistics ===");
        WP_CLI::log("Total Conversions: " . ($stats['total_conversions'] ?? 0));
        WP_CLI::log("AVIF Files: " . ($stats['avif_count'] ?? 0));
        WP_CLI::log("WebP Files: " . ($stats['webp_count'] ?? 0));

        $savings = $stats['total_savings_bytes'] ?? 0;
        $savings_mb = round($savings / 1024 / 1024, 2);
        WP_CLI::log("Total Savings: {$savings_mb} MB");

        if (isset($stats['last_conversion'])) {
            WP_CLI::log("Last Conversion: " . $stats['last_conversion']);
        }
    });

    WP_CLI::add_command('timber-avif bulk', function($args, $assoc_args) {
        $quality = isset($assoc_args['quality']) ? intval($assoc_args['quality']) : null;
        $force = isset($assoc_args['force']) && $assoc_args['force'];
        $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : -1;

        WP_CLI::log("Starting bulk AVIF/WebP conversion...");

        $query_args = [
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'posts_per_page' => $limit,
            'post_status' => 'any',
            'fields' => 'ids'
        ];

        $attachments = get_posts($query_args);
        $total = count($attachments);

        if ($total === 0) {
            WP_CLI::warning('No image attachments found.');
            return;
        }

        $progress = \WP_CLI\Utils\make_progress_bar("Converting {$total} images", $total);

        $converted = 0;
        $skipped = 0;

        foreach ($attachments as $attachment_id) {
            $file_path = get_attached_file($attachment_id);

            if (!$file_path || !file_exists($file_path)) {
                $skipped++;
                $progress->tick();
                continue;
            }

            $metadata = wp_get_attachment_metadata($attachment_id);
            Timber_AVIF_Converter::auto_convert_on_upload($metadata, $attachment_id);
            $converted++;

            $progress->tick();
        }

        $progress->finish();

        WP_CLI::success("Bulk conversion complete!");
        WP_CLI::log("Converted: {$converted}");
        WP_CLI::log("Skipped: {$skipped}");
    });
}
