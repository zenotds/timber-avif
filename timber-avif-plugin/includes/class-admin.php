<?php
/**
 * Timber AVIF Converter - Admin Settings
 *
 * @package TimberAVIF
 * @version 3.0.0
 */

class Timber_AVIF_Admin {

    /**
     * Initialize admin hooks
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_timber_avif_bulk_convert', [__CLASS__, 'ajax_bulk_convert']);
        add_action('wp_ajax_timber_avif_get_stats', [__CLASS__, 'ajax_get_stats']);
    }

    /**
     * Add settings page to admin menu
     */
    public static function add_settings_page() {
        add_options_page(
            'Timber AVIF Converter',
            'Timber AVIF',
            'manage_options',
            'timber-avif-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    /**
     * Register plugin settings
     */
    public static function register_settings() {
        register_setting('timber_avif_settings', 'timber_avif_settings', [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings']
        ]);
    }

    /**
     * Sanitize settings before saving
     */
    public static function sanitize_settings($input) {
        $sanitized = [];

        // Booleans
        $sanitized['enable_auto_convert'] = isset($input['enable_auto_convert']);
        $sanitized['enable_webp'] = isset($input['enable_webp']);
        $sanitized['only_if_smaller'] = isset($input['only_if_smaller']);
        $sanitized['pregenerate_sizes'] = isset($input['pregenerate_sizes']);
        $sanitized['enable_debug_logging'] = isset($input['enable_debug_logging']);
        $sanitized['enable_smart_quality'] = isset($input['enable_smart_quality']);

        // Integers
        $sanitized['avif_quality'] = max(1, min(100, intval($input['avif_quality'] ?? 80)));
        $sanitized['webp_quality'] = max(1, min(100, intval($input['webp_quality'] ?? 85)));
        $sanitized['max_dimension'] = max(1000, min(8192, intval($input['max_dimension'] ?? 4096)));
        $sanitized['max_file_size'] = max(1, min(500, intval($input['max_file_size'] ?? 50)));
        $sanitized['stale_lock_timeout'] = max(60, min(3600, intval($input['stale_lock_timeout'] ?? 300)));

        // Strings
        $sanitized['common_sizes'] = sanitize_text_field($input['common_sizes'] ?? '800,1200,1600,2400');

        // Smart quality rules
        if (isset($input['smart_quality_rules']) && is_array($input['smart_quality_rules'])) {
            $sanitized['smart_quality_rules'] = $input['smart_quality_rules'];
        } else {
            $sanitized['smart_quality_rules'] = [
                ['max_dimension' => 1000, 'avif' => 85, 'webp' => 90],
                ['max_dimension' => 2000, 'avif' => 80, 'webp' => 85],
                ['max_dimension' => PHP_INT_MAX, 'avif' => 75, 'webp' => 80]
            ];
        }

        return $sanitized;
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_timber-avif-settings') {
            return;
        }

        wp_enqueue_style('timber-avif-admin', TIMBER_AVIF_PLUGIN_URL . 'admin/style.css', [], TIMBER_AVIF_VERSION);
        wp_enqueue_script('timber-avif-admin', TIMBER_AVIF_PLUGIN_URL . 'admin/script.js', ['jquery'], TIMBER_AVIF_VERSION, true);

        wp_localize_script('timber-avif-admin', 'timberAvifAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('timber_avif_admin')
        ]);
    }

    /**
     * Render settings page
     */
    public static function render_settings_page() {
        $settings = get_option('timber_avif_settings', []);
        $stats = get_option('timber_avif_stats', [
            'total_conversions' => 0,
            'avif_count' => 0,
            'webp_count' => 0,
            'total_savings_bytes' => 0,
            'last_conversion' => null
        ]);

        $capability = Timber_AVIF_Converter::detect_capabilities();

        ?>
        <div class="wrap timber-avif-settings">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings saved successfully!</p>
                </div>
            <?php endif; ?>

            <!-- Capability Notice -->
            <?php if ($capability === 'none'): ?>
                <div class="notice notice-error">
                    <p><strong>No AVIF/WebP support detected!</strong> Please install GD (PHP 8.1+), ImageMagick, or GraphicsMagick with AVIF support.</p>
                </div>
            <?php else: ?>
                <div class="notice notice-success">
                    <p><strong>Conversion method:</strong> <?php echo esc_html(strtoupper($capability)); ?></p>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="#general" class="nav-tab nav-tab-active">General Settings</a>
                <a href="#quality" class="nav-tab">Quality Settings</a>
                <a href="#statistics" class="nav-tab">Statistics</a>
                <a href="#tools" class="nav-tab">Tools</a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('timber_avif_settings'); ?>

                <!-- General Settings Tab -->
                <div id="general" class="tab-content active">
                    <h2>General Settings</h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Auto-Convert on Upload</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="timber_avif_settings[enable_auto_convert]" value="1" <?php checked($settings['enable_auto_convert'] ?? true); ?>>
                                    Automatically convert images when uploaded to media library
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Enable WebP Generation</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="timber_avif_settings[enable_webp]" value="1" <?php checked($settings['enable_webp'] ?? true); ?>>
                                    Generate WebP versions alongside AVIF
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Only Use if Smaller</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="timber_avif_settings[only_if_smaller]" value="1" <?php checked($settings['only_if_smaller'] ?? true); ?>>
                                    Only keep converted files if smaller than original
                                </label>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Pre-generate Common Sizes</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="timber_avif_settings[pregenerate_sizes]" value="1" <?php checked($settings['pregenerate_sizes'] ?? false); ?>>
                                    Generate AVIF/WebP for common sizes on upload
                                </label>
                                <p class="description">
                                    Common sizes (comma-separated widths):
                                    <input type="text" name="timber_avif_settings[common_sizes]" value="<?php echo esc_attr($settings['common_sizes'] ?? '800,1200,1600,2400'); ?>" class="regular-text">
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Maximum Image Dimension</th>
                            <td>
                                <input type="number" name="timber_avif_settings[max_dimension]" value="<?php echo esc_attr($settings['max_dimension'] ?? 4096); ?>" min="1000" max="8192">
                                <p class="description">Skip images larger than this (prevents memory exhaustion)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Maximum File Size (MB)</th>
                            <td>
                                <input type="number" name="timber_avif_settings[max_file_size]" value="<?php echo esc_attr($settings['max_file_size'] ?? 50); ?>" min="1" max="500">
                                <p class="description">Skip files larger than this size</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Stale Lock Timeout (seconds)</th>
                            <td>
                                <input type="number" name="timber_avif_settings[stale_lock_timeout]" value="<?php echo esc_attr($settings['stale_lock_timeout'] ?? 300); ?>" min="60" max="3600">
                                <p class="description">Remove lock files older than this</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Debug Logging</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="timber_avif_settings[enable_debug_logging]" value="1" <?php checked($settings['enable_debug_logging'] ?? false); ?>>
                                    Enable detailed logging (requires WP_DEBUG)
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Quality Settings Tab -->
                <div id="quality" class="tab-content">
                    <h2>Quality Settings</h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">AVIF Quality</th>
                            <td>
                                <input type="number" name="timber_avif_settings[avif_quality]" value="<?php echo esc_attr($settings['avif_quality'] ?? 80); ?>" min="1" max="100">
                                <p class="description">Default quality for AVIF conversion (1-100)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">WebP Quality</th>
                            <td>
                                <input type="number" name="timber_avif_settings[webp_quality]" value="<?php echo esc_attr($settings['webp_quality'] ?? 85); ?>" min="1" max="100">
                                <p class="description">Default quality for WebP conversion (1-100)</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Smart Quality</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="timber_avif_settings[enable_smart_quality]" value="1" <?php checked($settings['enable_smart_quality'] ?? false); ?>>
                                    Enable dimension-based quality adjustment
                                </label>
                                <p class="description">Automatically adjust quality based on image dimensions</p>
                            </td>
                        </tr>
                    </table>

                    <h3>Smart Quality Rules</h3>
                    <p class="description">Lower quality for larger images to reduce file size</p>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Max Dimension (px)</th>
                                <th>AVIF Quality</th>
                                <th>WebP Quality</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Up to 1000px</td>
                                <td>85</td>
                                <td>90</td>
                            </tr>
                            <tr>
                                <td>1001 - 2000px</td>
                                <td>80</td>
                                <td>85</td>
                            </tr>
                            <tr>
                                <td>2001px+</td>
                                <td>75</td>
                                <td>80</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Statistics Tab -->
                <div id="statistics" class="tab-content">
                    <h2>Conversion Statistics</h2>

                    <div class="timber-avif-stats">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($stats['total_conversions']); ?></div>
                            <div class="stat-label">Total Conversions</div>
                        </div>

                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($stats['avif_count']); ?></div>
                            <div class="stat-label">AVIF Files</div>
                        </div>

                        <div class="stat-box">
                            <div class="stat-value"><?php echo number_format($stats['webp_count']); ?></div>
                            <div class="stat-label">WebP Files</div>
                        </div>

                        <div class="stat-box">
                            <div class="stat-value"><?php echo self::format_bytes($stats['total_savings_bytes']); ?></div>
                            <div class="stat-label">Total Savings</div>
                        </div>
                    </div>

                    <?php if ($stats['last_conversion']): ?>
                        <p><strong>Last Conversion:</strong> <?php echo esc_html($stats['last_conversion']); ?></p>
                    <?php endif; ?>

                    <p>
                        <button type="button" class="button" onclick="if(confirm('Reset all statistics?')) { this.form.submit(); }">
                            Reset Statistics
                        </button>
                    </p>
                </div>

                <!-- Tools Tab -->
                <div id="tools" class="tab-content">
                    <h2>Tools</h2>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Bulk Convert Existing Images</th>
                            <td>
                                <button type="button" id="bulk-convert-btn" class="button button-primary">
                                    Start Bulk Conversion
                                </button>
                                <p class="description">Convert all existing images in media library</p>
                                <div id="bulk-convert-progress" style="display:none; margin-top:10px;">
                                    <progress id="bulk-progress-bar" max="100" value="0" style="width:100%; height:30px;"></progress>
                                    <p id="bulk-progress-text">Processing...</p>
                                </div>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Cleanup Invalid Files</th>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=timber-avif-settings&action=cleanup')); ?>" class="button" onclick="return confirm('Remove all corrupted AVIF/WebP files?');">
                                    Cleanup Invalid Files
                                </a>
                                <p class="description">Remove corrupted or invalid AVIF/WebP files</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">Clear Cache</th>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=timber-avif-settings&action=clear-cache')); ?>" class="button">
                                    Clear Cache
                                </a>
                                <p class="description">Clear capability detection cache</p>
                            </td>
                        </tr>
                    </table>

                    <h3>WP-CLI Commands</h3>
                    <p>If you have WP-CLI installed, you can use these commands:</p>
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 3px;">
# Bulk convert all images
wp timber-avif bulk

# With options
wp timber-avif bulk --quality=75 --force --limit=100

# Show statistics
wp timber-avif stats

# Cleanup invalid files
wp timber-avif cleanup

# Clear cache
wp timber-avif clear-cache

# Detect conversion method
wp timber-avif detect
                    </pre>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>

        <style>
            .timber-avif-settings .nav-tab-wrapper {
                margin-bottom: 20px;
            }
            .timber-avif-settings .tab-content {
                display: none;
            }
            .timber-avif-settings .tab-content.active {
                display: block;
            }
            .timber-avif-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .stat-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 20px;
                text-align: center;
            }
            .stat-value {
                font-size: 32px;
                font-weight: bold;
                color: #2271b1;
            }
            .stat-label {
                font-size: 14px;
                color: #646970;
                margin-top: 5px;
            }
        </style>

        <script>
            jQuery(document).ready(function($) {
                // Tab switching
                $('.nav-tab').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).attr('href');

                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');

                    $('.tab-content').removeClass('active');
                    $(target).addClass('active');
                });

                // Bulk convert
                $('#bulk-convert-btn').on('click', function() {
                    if (!confirm('Start bulk conversion? This may take a while.')) {
                        return;
                    }

                    $(this).prop('disabled', true);
                    $('#bulk-convert-progress').show();

                    $.ajax({
                        url: timberAvifAdmin.ajaxUrl,
                        type: 'POST',
                        data: {
                            action: 'timber_avif_bulk_convert',
                            nonce: timberAvifAdmin.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#bulk-progress-text').html('Completed! ' + response.data.message);
                                $('#bulk-progress-bar').val(100);
                            } else {
                                alert('Error: ' + response.data.message);
                            }
                            $('#bulk-convert-btn').prop('disabled', false);
                        },
                        error: function() {
                            alert('An error occurred during bulk conversion.');
                            $('#bulk-convert-btn').prop('disabled', false);
                            $('#bulk-convert-progress').hide();
                        }
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX: Bulk convert images
     */
    public static function ajax_bulk_convert() {
        check_ajax_referer('timber_avif_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        // Get all image attachments
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        $converted = 0;
        $skipped = 0;

        foreach ($attachments as $attachment_id) {
            $file_path = get_attached_file($attachment_id);

            if (!$file_path || !file_exists($file_path)) {
                $skipped++;
                continue;
            }

            $metadata = wp_get_attachment_metadata($attachment_id);
            Timber_AVIF_Converter::auto_convert_on_upload($metadata, $attachment_id);
            $converted++;
        }

        wp_send_json_success([
            'message' => "Converted: {$converted}, Skipped: {$skipped}"
        ]);
    }

    /**
     * Format bytes for display
     */
    private static function format_bytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
