<?php
/**
 * Timber AVIF Converter
 *
 * @version 4.0.0
 * @author Francesco Zeno Selva
 *
 * Lightweight drop-in for Timber 2.3 that adds AVIF + WebP conversion, Twig helpers,
 * upload-time generation, responsive macros support and simple admin tools.
 *
 * Keep using the |toavif filter for ad-hoc conversions (remote URLs included).
 * New helpers expose `image.avif` / `image.webp` and `|avif_src` / `|webp_src`
 * for quickly grabbing converted URLs (with resize support).
 */


use Timber\Image;
use Timber\ImageHelper;

if (class_exists('Timber\\Image') && !class_exists('AVIFImage')) {
    class AVIFImage extends Image {
        public function __get($field) {
            if ($field === 'avif') {
                return AVIFConverter::get_avif_variant($this);
            }
            if ($field === 'webp') {
                return AVIFConverter::get_webp_variant($this);
            }
            if ($field === 'best') {
                $avif = AVIFConverter::get_avif_variant($this);
                if ($avif) {
                    return $avif;
                }
                return AVIFConverter::get_webp_variant($this) ?: $this->src;
            }
            return parent::__get($field);
        }
    }
}

class AVIFConverter {
    // -- Defaults & Options --
    const OPTION_KEY = 'timber_avif_settings';
    const DEFAULT_AVIF_QUALITY = 80;
    const DEFAULT_WEBP_QUALITY = 82;
    const MAX_IMAGE_DIMENSION = 4096;
    const MAX_FILE_SIZE_MB = 50;
    const ONLY_IF_SMALLER = true;
    const STALE_LOCK_TIMEOUT = 300; // seconds

    // -- Cache constants --
    private const CACHE_PREFIX = 'timber_avif_';
    private const CACHE_DURATION = MONTH_IN_SECONDS;
    private const CAPABILITY_CACHE_DURATION = WEEK_IN_SECONDS;

    // -- Runtime state --
    private static $conversion_methods = [
        'avif' => null,
        'webp' => null,
    ];
    private static $settings = [];

    /**
     * Bootstrap hooks
     */
    public static function init() {
        self::load_settings();

        add_filter('timber/twig', [__CLASS__, 'add_twig_filters']);
        add_filter('timber/image/new_class', [__CLASS__, 'override_timber_image_class']);
        add_filter('timber/image/new_url', [__CLASS__, 'handle_timber_resize'], 10, 1);

        add_action('admin_notices', [__CLASS__, 'check_avif_support_admin_notice']);
        add_action('wp_generate_attachment_metadata', [__CLASS__, 'generate_for_upload'], 20, 2);
        add_action('admin_menu', [__CLASS__, 'register_admin_page']);
        add_action('admin_post_timber_avif_tools', [__CLASS__, 'handle_admin_post']);
        add_action('wp_ajax_timber_avif_bulk_batch', [__CLASS__, 'handle_ajax_bulk_batch']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
        add_filter('manage_media_columns', [__CLASS__, 'add_media_column']);
        add_action('manage_media_custom_column', [__CLASS__, 'render_media_column'], 10, 2);
        add_action('admin_head', [__CLASS__, 'media_column_css']);

        // Detect capabilities early
        add_action('init', function () {
            self::detect_capabilities('avif');
            self::detect_capabilities('webp');
        });

        // Initialize CLI helpers
        if (defined('WP_CLI') && WP_CLI) {
            self::register_cli();
        }
    }

    /**
     * Ensure settings exist and merge with defaults
     */
    private static function load_settings() {
        $defaults = [
            'generate_avif_uploads'  => true,
            'generate_webp_uploads'  => true,
            'avif_quality'           => self::DEFAULT_AVIF_QUALITY,
            'webp_quality'           => self::DEFAULT_WEBP_QUALITY,
            'only_if_smaller'        => self::ONLY_IF_SMALLER,
            'max_dimension'          => self::MAX_IMAGE_DIMENSION,
            'max_file_size'          => self::MAX_FILE_SIZE_MB,
            'pregenerate_breakpoints' => false,
            'breakpoint_widths'      => '640,768,1024,1280,1600,1920,2560',
        ];

        $saved = get_option(self::OPTION_KEY, []);

        // Backfill legacy keys
        if (isset($saved['auto_convert_uploads'])) {
            $saved['generate_avif_uploads'] = (bool) $saved['auto_convert_uploads'];
        }
        if (isset($saved['enable_webp'])) {
            $saved['generate_webp_uploads'] = (bool) $saved['enable_webp'];
        }

        self::$settings = wp_parse_args($saved, $defaults);

        if (empty($saved)) {
            update_option(self::OPTION_KEY, self::$settings);
        }
    }

    /**
     * Twig filters + functions
     */
    public static function add_twig_filters($twig) {
        $twig->addFilter(new \Twig\TwigFilter('toavif', [__CLASS__, 'convert_to_avif']));
        $twig->addFilter(new \Twig\TwigFilter('avif_src', [__CLASS__, 'get_avif_variant']));
        $twig->addFilter(new \Twig\TwigFilter('webp_src', [__CLASS__, 'get_webp_variant']));

        $twig->addFunction(new \Twig\TwigFunction('avif_src', [__CLASS__, 'get_avif_variant']));
        $twig->addFunction(new \Twig\TwigFunction('webp_src', [__CLASS__, 'get_webp_variant']));

        return $twig;
    }

    /**
     * Allow Timber images to expose avif/webp properties directly
     */
    public static function override_timber_image_class($class) {
        if (class_exists('AVIFImage')) {
            return 'AVIFImage';
        }
        return $class;
    }

    /**
     * Convert resized Timber assets automatically (non-blocking, returns original URL)
     */
    public static function handle_timber_resize($new_url) {
        // Kick off conversions but don't block rendering
        if (self::get_setting('generate_avif_uploads')) {
            self::convert_async($new_url, 'avif');
        }
        if (self::get_setting('generate_webp_uploads')) {
            self::convert_async($new_url, 'webp');
        }
        return $new_url;
    }

    /**
     * Convert original + registered sizes after upload
     */
    public static function generate_for_upload($metadata, $attachment_id) {
        if (!self::get_setting('generate_avif_uploads') && !self::get_setting('generate_webp_uploads')) {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $base_url = trailingslashit($upload_dir['baseurl']) . ltrim($metadata['file'], '/');

        if (self::get_setting('generate_avif_uploads')) {
            self::convert_async($base_url, 'avif');
        }
        if (self::get_setting('generate_webp_uploads')) {
            self::convert_async($base_url, 'webp');
        }

        // Convert generated sizes
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }
                $size_url = trailingslashit(dirname($base_url)) . $size['file'];
                if (self::get_setting('generate_avif_uploads')) {
                    self::convert_async($size_url, 'avif');
                }
                if (self::get_setting('generate_webp_uploads')) {
                    self::convert_async($size_url, 'webp');
                }
            }
        }

        // Optional breakpoint pre-generation
        if (self::get_setting('pregenerate_breakpoints')) {
            $widths = array_filter(array_map('intval', explode(',', self::get_setting('breakpoint_widths'))));
            foreach ($widths as $width) {
                $resized = self::maybe_resize($base_url, $width, null);
                if ($resized) {
                    if (self::get_setting('generate_avif_uploads')) {
                        self::convert_async($resized, 'avif');
                    }
                    if (self::get_setting('generate_webp_uploads')) {
                        self::convert_async($resized, 'webp');
                    }
                }
            }
        }

        // Invalidate statistics cache so the Stats tab is fresh
        delete_transient(self::CACHE_PREFIX . 'statistics');

        return $metadata;
    }

    /**
     * Register admin screen under Settings
     */
    public static function register_admin_page() {
        add_options_page(
            'Timber AVIF',
            'Timber AVIF',
            'manage_options',
            'timber-avif-settings',
            [__CLASS__, 'render_admin_page']
        );
    }

    /**
     * Gather conversion statistics
     */
    public static function get_statistics() {
        $cache_key = self::CACHE_PREFIX . 'statistics';
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];

        $image_ids = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        $stats = [
            'total_images'    => count($image_ids),
            'avif_converted'  => 0,
            'webp_converted'  => 0,
            'original_size'   => 0,
            'avif_size'       => 0,
            'webp_size'       => 0,
        ];

        foreach ($image_ids as $id) {
            $file = get_attached_file($id);
            if (!$file || !file_exists($file)) {
                continue;
            }

            $stats['original_size'] += filesize($file);

            $avif_path = preg_replace('/\.(jpe?g|png|gif)$/i', '.avif', $file);
            if (file_exists($avif_path)) {
                $stats['avif_converted']++;
                $stats['avif_size'] += filesize($avif_path);
            }

            $webp_path = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $file);
            if (file_exists($webp_path)) {
                $stats['webp_converted']++;
                $stats['webp_size'] += filesize($webp_path);
            }
        }

        $stats['avif_saved'] = $stats['avif_size'] > 0 ? $stats['original_size'] - $stats['avif_size'] : 0;
        $stats['webp_saved'] = $stats['webp_size'] > 0 ? $stats['original_size'] - $stats['webp_size'] : 0;

        set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
        return $stats;
    }

    /**
     * Format bytes to human-readable size
     */
    private static function format_bytes($bytes, $decimals = 1) {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $factor), $decimals) . ' ' . $units[$factor];
    }

    /**
     * Render admin page
     */
    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::$settings;
        $tab = sanitize_key($_GET['tab'] ?? 'settings');
        $base_url = admin_url('options-general.php?page=timber-avif-settings');

        // Capability detection
        $avif_method = self::detect_capabilities('avif');
        $webp_method = self::detect_capabilities('webp');

        $method_labels = [
            'gd'      => 'GD Library',
            'imagick' => 'ImageMagick',
            'exec'    => 'CLI (magick)',
            'none'    => 'Not available',
        ];
        ?>
        <style>
            .tavif-wrap { max-width: 860px; }
            .tavif-header { display: flex; align-items: center; gap: 12px; margin-bottom: 4px; }
            .tavif-header h1 { margin: 0; padding: 0; line-height: 1.2; }
            .tavif-version { font-size: 11px; color: #646970; background: #f0f0f1; padding: 2px 8px; border-radius: 10px; font-weight: 400; }
            .tavif-wrap .nav-tab-wrapper { margin-bottom: 0; border-bottom: 1px solid #c3c4c7; }
            .tavif-card { background: #fff; border: 1px solid #c3c4c7; border-top: 0; padding: 24px 28px; margin-bottom: 20px; }

            /* Status bar */
            .tavif-status { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 16px 0 20px; }
            .tavif-status-item { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; padding: 16px 18px; }
            .tavif-status-item .label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #646970; margin-bottom: 6px; }
            .tavif-status-item .value { font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
            .tavif-status-item .engine-detail { font-size: 11px; font-weight: 400; color: #646970; }

            /* Badges */
            .tavif-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; line-height: 1; }
            .tavif-badge--ok { background: #d1fae5; color: #065f46; }
            .tavif-badge--warn { background: #fef3c7; color: #92400e; }
            .tavif-badge--off { background: #f3f4f6; color: #6b7280; }
            .tavif-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
            .tavif-dot--ok { background: #10b981; }
            .tavif-dot--warn { background: #f59e0b; }
            .tavif-dot--off { background: #9ca3af; }

            /* Toggles */
            .tavif-toggle { position: relative; display: inline-flex; align-items: center; gap: 10px; cursor: pointer; user-select: none; }
            .tavif-toggle input[type="checkbox"] { position: absolute; opacity: 0; width: 0; height: 0; }
            .tavif-toggle .slider { width: 40px; height: 22px; background: #d1d5db; border-radius: 11px; position: relative; transition: background 0.2s; flex-shrink: 0; }
            .tavif-toggle .slider::after { content: ''; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: transform 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,.15); }
            .tavif-toggle input:checked + .slider { background: #2271b1; }
            .tavif-toggle input:checked + .slider::after { transform: translateX(18px); }
            .tavif-toggle .toggle-label { font-size: 13px; }

            /* Range sliders */
            .tavif-range-group { margin-bottom: 16px; }
            .tavif-range-group label { display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 13px; }
            .tavif-range-group input[type="range"] { flex: 1; max-width: 280px; accent-color: #2271b1; height: 6px; }
            .tavif-range-group .range-val { display: inline-block; min-width: 36px; text-align: center; font-weight: 600; font-size: 13px; background: #f0f0f1; padding: 3px 10px; border-radius: 4px; font-variant-numeric: tabular-nums; }
            .tavif-range-group .range-label { min-width: 42px; }

            /* Field rows */
            .tavif-field-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 12px; }
            .tavif-field-row label { display: flex; align-items: center; gap: 6px; font-size: 13px; }
            .tavif-field-row input[type="number"] { width: 90px; }
            .tavif-field-row input[type="text"].regular-text { max-width: 320px; }

            /* Sections */
            .tavif-section { margin-bottom: 28px; }
            .tavif-section:last-child { margin-bottom: 0; }
            .tavif-section h3 { font-size: 13px; font-weight: 600; color: #1d2327; margin: 0 0 14px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; text-transform: uppercase; letter-spacing: 0.3px; }

            /* Tool cards */
            .tavif-tools-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
            .tavif-tool-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 22px; display: flex; flex-direction: column; }
            .tavif-tool-card h3 { margin: 0 0 8px; font-size: 14px; color: #1d2327; }
            .tavif-tool-card p { color: #6b7280; font-size: 13px; margin: 0 0 18px; line-height: 1.5; flex: 1; }
            .tavif-tool-card .button { align-self: flex-start; }

            /* Stats */
            .tavif-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 24px; }
            .tavif-stat-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; text-align: center; }
            .tavif-stat-card .stat-value { font-size: 28px; font-weight: 700; color: #1d2327; line-height: 1.2; font-variant-numeric: tabular-nums; }
            .tavif-stat-card .stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; margin-top: 4px; }
            .tavif-stat-card .stat-sub { font-size: 12px; color: #9ca3af; margin-top: 2px; }
            .tavif-stat-card--highlight { background: #eff6ff; border-color: #bfdbfe; }
            .tavif-stat-card--highlight .stat-value { color: #1d4ed8; }
            .tavif-stat-card--green { background: #ecfdf5; border-color: #a7f3d0; }
            .tavif-stat-card--green .stat-value { color: #065f46; }
            .tavif-progress { margin-bottom: 24px; }
            .tavif-progress h3 { font-size: 13px; font-weight: 600; margin: 0 0 12px; color: #1d2327; }
            .tavif-progress-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; font-size: 13px; }
            .tavif-progress-row .bar-label { min-width: 48px; font-weight: 500; }
            .tavif-progress-row .bar-wrap { flex: 1; height: 24px; background: #f3f4f6; border-radius: 4px; overflow: hidden; position: relative; }
            .tavif-progress-row .bar-fill { height: 100%; border-radius: 4px; transition: width 0.3s; min-width: 2px; }
            .tavif-progress-row .bar-fill--avif { background: linear-gradient(90deg, #6366f1, #818cf8); }
            .tavif-progress-row .bar-fill--webp { background: linear-gradient(90deg, #2563eb, #60a5fa); }
            .tavif-progress-row .bar-text { font-size: 12px; color: #6b7280; min-width: 80px; text-align: right; }
        </style>

        <div class="wrap tavif-wrap">
            <h1>Timber AVIF</h1>

            <?php // Status bar ?>
            <div class="tavif-status">
                <div class="tavif-status-item">
                    <div class="label">AVIF Engine</div>
                    <div class="value">
                        <span class="tavif-dot tavif-dot--<?php echo $avif_method !== 'none' ? 'ok' : 'warn'; ?>"></span>
                        <?php echo esc_html($method_labels[$avif_method] ?? 'Unknown'); ?>
                    </div>
                </div>
                <div class="tavif-status-item">
                    <div class="label">WebP Engine</div>
                    <div class="value">
                        <span class="tavif-dot tavif-dot--<?php echo $webp_method !== 'none' ? 'ok' : 'warn'; ?>"></span>
                        <?php echo esc_html($method_labels[$webp_method] ?? 'Unknown'); ?>
                    </div>
                </div>
                <div class="tavif-status-item">
                    <div class="label">Auto-convert on upload</div>
                    <div class="value">
                        <?php
                        $formats = [];
                        if ($settings['generate_avif_uploads'] && $avif_method !== 'none') $formats[] = 'AVIF';
                        if ($settings['generate_webp_uploads'] && $webp_method !== 'none') $formats[] = 'WebP';
                        ?>
                        <?php if ($formats): ?>
                            <span class="tavif-badge tavif-badge--ok"><?php echo esc_html(implode(' + ', $formats)); ?></span>
                        <?php else: ?>
                            <span class="tavif-badge tavif-badge--off">Off</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="tavif-status-item">
                    <div class="label">Quality</div>
                    <div class="value">
                        AVIF <?php echo esc_html($settings['avif_quality']); ?>
                        &nbsp;&middot;&nbsp;
                        WebP <?php echo esc_html($settings['webp_quality']); ?>
                    </div>
                </div>
            </div>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg('tab', 'settings', $base_url)); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'tools', $base_url)); ?>" class="nav-tab <?php echo $tab === 'tools' ? 'nav-tab-active' : ''; ?>">Tools</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'statistics', $base_url)); ?>" class="nav-tab <?php echo $tab === 'statistics' ? 'nav-tab-active' : ''; ?>">Statistics</a>
            </h2>

            <div class="tavif-card">

            <?php if ($tab === 'statistics'): ?>
                <?php $stats = self::get_statistics(); ?>

                <div class="tavif-stats-grid">
                    <div class="tavif-stat-card">
                        <div class="stat-value"><?php echo esc_html($stats['total_images']); ?></div>
                        <div class="stat-label">Total images</div>
                        <div class="stat-sub">JPG, PNG, GIF</div>
                    </div>
                    <div class="tavif-stat-card tavif-stat-card--highlight">
                        <div class="stat-value"><?php echo esc_html($stats['avif_converted']); ?></div>
                        <div class="stat-label">AVIF converted</div>
                        <div class="stat-sub"><?php echo $stats['total_images'] > 0 ? round($stats['avif_converted'] / $stats['total_images'] * 100) : 0; ?>% of library</div>
                    </div>
                    <div class="tavif-stat-card tavif-stat-card--highlight">
                        <div class="stat-value"><?php echo esc_html($stats['webp_converted']); ?></div>
                        <div class="stat-label">WebP converted</div>
                        <div class="stat-sub"><?php echo $stats['total_images'] > 0 ? round($stats['webp_converted'] / $stats['total_images'] * 100) : 0; ?>% of library</div>
                    </div>
                </div>

                <?php // Progress bars ?>
                <div class="tavif-progress">
                    <h3>Conversion progress</h3>
                    <?php
                    $avif_pct = $stats['total_images'] > 0 ? round($stats['avif_converted'] / $stats['total_images'] * 100) : 0;
                    $webp_pct = $stats['total_images'] > 0 ? round($stats['webp_converted'] / $stats['total_images'] * 100) : 0;
                    ?>
                    <div class="tavif-progress-row">
                        <span class="bar-label">AVIF</span>
                        <div class="bar-wrap">
                            <div class="bar-fill bar-fill--avif" style="width: <?php echo esc_attr($avif_pct); ?>%"></div>
                        </div>
                        <span class="bar-text"><?php echo esc_html($stats['avif_converted']); ?> / <?php echo esc_html($stats['total_images']); ?></span>
                    </div>
                    <div class="tavif-progress-row">
                        <span class="bar-label">WebP</span>
                        <div class="bar-wrap">
                            <div class="bar-fill bar-fill--webp" style="width: <?php echo esc_attr($webp_pct); ?>%"></div>
                        </div>
                        <span class="bar-text"><?php echo esc_html($stats['webp_converted']); ?> / <?php echo esc_html($stats['total_images']); ?></span>
                    </div>
                </div>

                <?php // Space savings ?>
                <div class="tavif-stats-grid">
                    <div class="tavif-stat-card">
                        <div class="stat-value"><?php echo esc_html(self::format_bytes($stats['original_size'])); ?></div>
                        <div class="stat-label">Original size</div>
                        <div class="stat-sub">Source images</div>
                    </div>
                    <?php if ($stats['avif_converted'] > 0): ?>
                    <div class="tavif-stat-card tavif-stat-card--green">
                        <div class="stat-value"><?php echo esc_html(self::format_bytes($stats['avif_saved'])); ?></div>
                        <div class="stat-label">Saved with AVIF</div>
                        <div class="stat-sub"><?php echo $stats['original_size'] > 0 ? round($stats['avif_saved'] / $stats['original_size'] * 100) : 0; ?>% smaller</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($stats['webp_converted'] > 0): ?>
                    <div class="tavif-stat-card tavif-stat-card--green">
                        <div class="stat-value"><?php echo esc_html(self::format_bytes($stats['webp_saved'])); ?></div>
                        <div class="stat-label">Saved with WebP</div>
                        <div class="stat-sub"><?php echo $stats['original_size'] > 0 ? round($stats['webp_saved'] / $stats['original_size'] * 100) : 0; ?>% smaller</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($stats['avif_converted'] === 0 && $stats['webp_converted'] === 0): ?>
                    <div class="tavif-stat-card">
                        <div class="stat-value">&mdash;</div>
                        <div class="stat-label">No conversions yet</div>
                        <div class="stat-sub">Run bulk convert from Tools tab</div>
                    </div>
                    <?php endif; ?>
                </div>

                <p class="description" style="margin-top: 8px;">Statistics are cached for 5 minutes. Only original-sized images are counted (WordPress thumbnails and Timber resizes are excluded).</p>

            <?php elseif ($tab === 'tools'): ?>
                <?php
                $image_ids = get_posts([
                    'post_type'      => 'attachment',
                    'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
                    'posts_per_page' => -1,
                    'post_status'    => 'any',
                    'fields'         => 'ids',
                ]);
                $image_total = count($image_ids);
                ?>

                <div class="tavif-tools-grid">
                    <div class="tavif-tool-card">
                        <h3>Bulk Convert</h3>
                        <p>Process all <strong><?php echo esc_html($image_total); ?></strong> images in the media library and generate <?php
                            $targets = [];
                            if ($settings['generate_avif_uploads']) $targets[] = 'AVIF';
                            if ($settings['generate_webp_uploads']) $targets[] = 'WebP';
                            echo esc_html($targets ? implode(' &amp; ', $targets) : 'AVIF');
                        ?> variants. Already converted images will be skipped.</p>
                        <button type="button" id="tavif-bulk-start" class="button button-primary">Convert all media</button>
                        <div id="tavif-bulk-progress" style="display:none; margin-top: 14px;">
                            <div style="display:flex; align-items:center; gap:10px; margin-bottom: 6px;">
                                <div style="flex:1; height:22px; background:#f3f4f6; border-radius:4px; overflow:hidden;">
                                    <div id="tavif-bulk-bar" style="height:100%; width:0%; background:linear-gradient(90deg,#6366f1,#818cf8); border-radius:4px; transition:width .3s;"></div>
                                </div>
                                <span id="tavif-bulk-count" style="font-size:13px; font-variant-numeric:tabular-nums; min-width:80px; text-align:right;">0 / 0</span>
                            </div>
                            <p id="tavif-bulk-status" class="description" style="margin:0;"></p>
                        </div>
                    </div>
                    <div class="tavif-tool-card">
                        <h3>Purge Conversions</h3>
                        <p>Delete all generated AVIF and WebP files from the uploads directory. Original images are never touched. Useful before re-converting with different quality settings.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('timber_avif_tools'); ?>
                            <input type="hidden" name="action" value="timber_avif_tools" />
                            <input type="hidden" name="subaction" value="purge_conversions" />
                            <input type="hidden" name="tab" value="tools" />
                            <button type="submit" class="button" style="color:#b91c1c;" onclick="return confirm('Delete all generated AVIF and WebP files? Originals will not be touched.');">Purge all conversions</button>
                        </form>
                    </div>
                    <div class="tavif-tool-card">
                        <h3>Clear Caches</h3>
                        <p>Flush the internal capability cache and re-detect which PHP extensions and CLI tools are available for image conversion. Useful after a server update or PHP version change.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('timber_avif_tools'); ?>
                            <input type="hidden" name="action" value="timber_avif_tools" />
                            <input type="hidden" name="subaction" value="clear_cache" />
                            <input type="hidden" name="tab" value="tools" />
                            <button type="submit" class="button">Flush &amp; re-detect</button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('timber_avif_settings'); ?>
                    <input type="hidden" name="action" value="timber_avif_tools" />
                    <input type="hidden" name="subaction" value="save_settings" />
                    <input type="hidden" name="tab" value="settings" />

                    <div class="tavif-section">
                        <h3>Generation</h3>
                        <div class="tavif-field-row">
                            <label class="tavif-toggle">
                                <input type="hidden" name="generate_avif_uploads" value="0" />
                                <input type="checkbox" name="generate_avif_uploads" value="1" <?php checked($settings['generate_avif_uploads']); ?> />
                                <span class="slider"></span>
                                <span class="toggle-label">
                                    Generate AVIF on upload
                                    <?php if ($avif_method === 'none'): ?>
                                        <span class="tavif-badge tavif-badge--warn" style="margin-left:6px;">No engine</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        </div>
                        <div class="tavif-field-row">
                            <label class="tavif-toggle">
                                <input type="hidden" name="generate_webp_uploads" value="0" />
                                <input type="checkbox" name="generate_webp_uploads" value="1" <?php checked($settings['generate_webp_uploads']); ?> />
                                <span class="slider"></span>
                                <span class="toggle-label">
                                    Generate WebP on upload
                                    <?php if ($webp_method === 'none'): ?>
                                        <span class="tavif-badge tavif-badge--warn" style="margin-left:6px;">No engine</span>
                                    <?php endif; ?>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="tavif-section">
                        <h3>Quality</h3>
                        <div class="tavif-range-group">
                            <label>
                                <span class="range-label">AVIF</span>
                                <input type="range" name="avif_quality" min="1" max="100" value="<?php echo esc_attr($settings['avif_quality']); ?>" oninput="this.closest('label').querySelector('.range-val').textContent=this.value" />
                                <span class="range-val"><?php echo esc_html($settings['avif_quality']); ?></span>
                            </label>
                        </div>
                        <div class="tavif-range-group">
                            <label>
                                <span class="range-label">WebP</span>
                                <input type="range" name="webp_quality" min="1" max="100" value="<?php echo esc_attr($settings['webp_quality']); ?>" oninput="this.closest('label').querySelector('.range-val').textContent=this.value" />
                                <span class="range-val"><?php echo esc_html($settings['webp_quality']); ?></span>
                            </label>
                        </div>
                    </div>

                    <div class="tavif-section">
                        <h3>Size Limits</h3>
                        <div class="tavif-field-row">
                            <label>Max dimension (px) <input type="number" name="max_dimension" value="<?php echo esc_attr($settings['max_dimension']); ?>" min="512" step="1" /></label>
                            <label>Max file size (MB) <input type="number" name="max_file_size" value="<?php echo esc_attr($settings['max_file_size']); ?>" min="1" step="1" /></label>
                        </div>
                        <div class="tavif-field-row">
                            <label class="tavif-toggle">
                                <input type="checkbox" name="only_if_smaller" value="1" <?php checked($settings['only_if_smaller']); ?> />
                                <span class="slider"></span>
                                <span class="toggle-label">Only keep converted file if smaller than original</span>
                            </label>
                        </div>
                    </div>

                    <div class="tavif-section">
                        <h3>Responsive Breakpoints</h3>
                        <div class="tavif-field-row">
                            <label class="tavif-toggle">
                                <input type="checkbox" name="pregenerate_breakpoints" value="1" <?php checked($settings['pregenerate_breakpoints']); ?> />
                                <span class="slider"></span>
                                <span class="toggle-label">Pre-generate breakpoint variants on upload</span>
                            </label>
                        </div>
                        <div class="tavif-field-row" style="margin-top: 4px;">
                            <div>
                                <input type="text" name="breakpoint_widths" value="<?php echo esc_attr($settings['breakpoint_widths']); ?>" class="regular-text" />
                                <p class="description">Comma-separated widths (px). Converted variants will be pre-generated at each width on upload.</p>
                            </div>
                        </div>
                    </div>

                    <?php submit_button('Save settings'); ?>
                </form>
            <?php endif; ?>

            <?php if (!empty($_GET['converted'])): ?>
                <div class="notice notice-success is-dismissible" style="margin: 20px 0 0;"><p>Bulk conversion complete.</p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['cleared'])): ?>
                <div class="notice notice-success is-dismissible" style="margin: 20px 0 0;"><p>Caches cleared &mdash; capabilities re-detected.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['purged'])): ?>
                <div class="notice notice-success is-dismissible" style="margin: 20px 0 0;"><p><?php echo intval($_GET['purged']); ?> converted files deleted. Original images were not touched.</p></div>
            <?php endif; ?>

            </div>
            <p style="text-align:right; color:#9ca3af; font-size:11px; margin:12px 0 0;">Timber AVIF v4.0.0 &mdash; <a href="https://github.com/zenotds/timber-avif" target="_blank" style="color:#9ca3af;">GitHub</a></p>
        </div>
        <?php
    }

    /**
     * Handle admin actions
     */
    public static function handle_admin_post() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        $subaction = sanitize_text_field($_POST['subaction'] ?? '');
        $tab = sanitize_key($_POST['tab'] ?? 'settings');

        if ($subaction === 'save_settings') {
            check_admin_referer('timber_avif_settings');
            self::$settings['generate_avif_uploads'] = !empty($_POST['generate_avif_uploads']) && intval($_POST['generate_avif_uploads']) === 1;
            self::$settings['generate_webp_uploads'] = !empty($_POST['generate_webp_uploads']) && intval($_POST['generate_webp_uploads']) === 1;
            self::$settings['avif_quality'] = max(1, min(100, intval($_POST['avif_quality'] ?? self::DEFAULT_AVIF_QUALITY)));
            self::$settings['webp_quality'] = max(1, min(100, intval($_POST['webp_quality'] ?? self::DEFAULT_WEBP_QUALITY)));
            self::$settings['only_if_smaller'] = !empty($_POST['only_if_smaller']);
            self::$settings['max_dimension'] = max(512, intval($_POST['max_dimension'] ?? self::MAX_IMAGE_DIMENSION));
            self::$settings['max_file_size'] = max(1, intval($_POST['max_file_size'] ?? self::MAX_FILE_SIZE_MB));
            self::$settings['pregenerate_breakpoints'] = !empty($_POST['pregenerate_breakpoints']);
            self::$settings['breakpoint_widths'] = sanitize_text_field($_POST['breakpoint_widths'] ?? '');
            update_option(self::OPTION_KEY, self::$settings);
            wp_safe_redirect(add_query_arg(['updated' => 'true', 'tab' => $tab], admin_url('options-general.php?page=timber-avif-settings')));
            exit;
        }

        check_admin_referer('timber_avif_tools');

        if ($subaction === 'bulk_convert') {
            self::bulk_convert_media();
            wp_safe_redirect(add_query_arg(['converted' => 'true', 'tab' => $tab], admin_url('options-general.php?page=timber-avif-settings')));
            exit;
        }

        if ($subaction === 'purge_conversions') {
            $deleted = self::purge_all_conversions();
            wp_safe_redirect(add_query_arg(['purged' => $deleted, 'tab' => $tab], admin_url('options-general.php?page=timber-avif-settings')));
            exit;
        }

        if ($subaction === 'clear_cache') {
            self::clear_cache();
            wp_safe_redirect(add_query_arg(['cleared' => 'true', 'tab' => $tab], admin_url('options-general.php?page=timber-avif-settings')));
            exit;
        }

        wp_safe_redirect(admin_url('options-general.php?page=timber-avif-settings'));
        exit;
    }

    /**
     * AJAX handler: process one batch of bulk conversions
     */
    public static function handle_ajax_bulk_batch() {
        check_ajax_referer('timber_avif_bulk_batch', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions', 403);
        }

        $offset = max(0, intval($_POST['offset'] ?? 0));
        $batch_size = max(1, min(20, intval($_POST['batch_size'] ?? 5)));

        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        $total = count($attachments);
        $slice = array_slice($attachments, $offset, $batch_size);
        $processed = 0;

        foreach ($slice as $attachment_id) {
            self::convert_single_attachment($attachment_id);
            $processed++;
        }

        $new_offset = $offset + $processed;
        $done = $new_offset >= $total;

        if ($done) {
            delete_transient(self::CACHE_PREFIX . 'statistics');
        }

        wp_send_json_success([
            'processed' => $new_offset,
            'total'     => $total,
            'done'      => $done,
        ]);
    }

    /**
     * Enqueue inline JS for AJAX bulk convert (only on our settings page)
     */
    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_timber-avif-settings') {
            return;
        }

        $nonce = wp_create_nonce('timber_avif_bulk_batch');
        $ajax_url = admin_url('admin-ajax.php');

        wp_add_inline_script('jquery-core', "
            jQuery(function($){
                var running = false, cancelled = false;
                var btn = $('#tavif-bulk-start');
                var wrap = $('#tavif-bulk-progress');
                var bar = $('#tavif-bulk-bar');
                var count = $('#tavif-bulk-count');
                var status = $('#tavif-bulk-status');

                if (!btn.length) return;

                btn.on('click', function(){
                    if (running) {
                        cancelled = true;
                        btn.prop('disabled', true).text('Stopping\u2026');
                        return;
                    }

                    running = true;
                    cancelled = false;
                    btn.text('Cancel').removeClass('button-primary').addClass('button-secondary');
                    wrap.show();
                    bar.css('width', '0%');
                    count.text('0 / \u2026');
                    status.text('Starting\u2026');

                    processBatch(0);
                });

                function processBatch(offset) {
                    if (cancelled) {
                        finish('Cancelled at ' + offset + ' images.');
                        return;
                    }
                    $.post('" . esc_js($ajax_url) . "', {
                        action: 'timber_avif_bulk_batch',
                        nonce: '" . esc_js($nonce) . "',
                        offset: offset,
                        batch_size: 5
                    }, function(resp) {
                        if (!resp.success) {
                            finish('Error: ' + (resp.data || 'unknown'));
                            return;
                        }
                        var d = resp.data;
                        var pct = d.total > 0 ? Math.round(d.processed / d.total * 100) : 0;
                        bar.css('width', pct + '%');
                        count.text(d.processed + ' / ' + d.total);
                        status.text('Processing\u2026 ' + pct + '%');

                        if (d.done) {
                            finish('Done! ' + d.processed + ' images processed.');
                        } else {
                            processBatch(d.processed);
                        }
                    }).fail(function() {
                        finish('Request failed. Please try again.');
                    });
                }

                function finish(msg) {
                    running = false;
                    cancelled = false;
                    btn.prop('disabled', false).text('Convert all media').removeClass('button-secondary').addClass('button-primary');
                    status.text(msg);
                    bar.css('width', '100%');
                }
            });
        ");
    }

    /**
     * Wrapper for Twig filter (AVIF)
     */
    public static function convert_to_avif($src, $quality = null, $force = false) {
        $quality_provided = ($quality !== null);
        $quality = $quality_provided ? intval($quality) : self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY);
        return self::convert($src, 'avif', $quality, $force, $quality_provided);
    }

    /**
     * WebP helper (used by Twig function/filter)
     */
    public static function convert_to_webp($src, $quality = null, $force = false) {
        $quality_provided = ($quality !== null);
        $quality = $quality_provided ? intval($quality) : self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY);
        return self::convert($src, 'webp', $quality, $force, $quality_provided);
    }

    /**
     * Provide resized AVIF URL
     */
    public static function get_avif_variant($src, $width = null, $height = null, $quality = null) {
        $quality = $quality ? intval($quality) : self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY);
        return self::get_variant_url($src, 'avif', $width, $height, $quality);
    }

    /**
     * Provide resized WebP URL
     */
    public static function get_webp_variant($src, $width = null, $height = null, $quality = null) {
        $quality_provided = ($quality !== null);
        $quality = $quality_provided ? intval($quality) : self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY);
        if (!self::get_setting('generate_webp_uploads') && !$quality_provided && !$width && !$height) {
            return is_string($src) ? $src : ($src instanceof Image ? $src->src : '');
        }
        return self::get_variant_url($src, 'webp', $width, $height, $quality);
    }

    /**
     * Main conversion handler
     */
    private static function convert($src, $format, $quality, $force, $custom_quality = false) {
        if (empty($src)) {
            return '';
        }

        $format = ($format === 'webp') ? 'webp' : 'avif';
        $quality = max(1, min(100, intval($quality)));

        // Capability short-circuit
        $method = self::detect_capabilities($format);
        if ($method === 'none') {
            return is_string($src) ? $src : ($src instanceof Image ? $src->src : '');
        }

        $details = self::get_image_details($src);
        $file_path = $details['path'];
        $original_url = $details['url'];

        if (!$file_path || !file_exists($file_path)) {
            self::log("Source file not found: '{$original_url}'", 'warning');
            return $original_url;
        }

        $file_size_mb = filesize($file_path) / 1024 / 1024;
        if ($file_size_mb > self::get_setting('max_file_size', self::MAX_FILE_SIZE_MB)) {
            self::log("File too large for conversion ({$file_size_mb}MB): {$original_url}", 'info');
            return $original_url;
        }

        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            self::log("Unable to read image info: {$file_path}", 'warning');
            return $original_url;
        }

        if ($image_info[0] > self::get_setting('max_dimension', self::MAX_IMAGE_DIMENSION) || $image_info[1] > self::get_setting('max_dimension', self::MAX_IMAGE_DIMENSION)) {
            self::log("Image dimensions exceed maximum ({$image_info[0]}x{$image_info[1]}): {$file_path}", 'info');
            return $original_url;
        }

        // Destination
        $dest_path = self::get_destination_path($file_path, $quality, $format, $custom_quality);
        $dest_url = str_replace(wp_basename($file_path), wp_basename($dest_path), $original_url);

        // If file already exists and valid, return it
        if (!$force && file_exists($dest_path) && filesize($dest_path) > 0) {
            if (self::is_valid($dest_path, $format)) {
                return $dest_url;
            }
            @unlink($dest_path);
        }

        // Locking to avoid race conditions
        $lock_file = $dest_path . '.lock';
        if (file_exists($lock_file) && (time() - filemtime($lock_file)) > self::STALE_LOCK_TIMEOUT) {
            @unlink($lock_file);
        }

        $lock_handle = @fopen($lock_file, 'c');
        if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
            if ($lock_handle) {
                fclose($lock_handle);
            }
            return $original_url;
        }

        try {
            $success = self::perform_conversion($file_path, $dest_path, $quality, $format, $method);

            if ($success) {
                if (!self::is_valid($dest_path, $format)) {
                    @unlink($dest_path);
                    $success = false;
                } elseif (self::get_setting('only_if_smaller', self::ONLY_IF_SMALLER)) {
                    if (filesize($dest_path) >= filesize($file_path)) {
                        @unlink($dest_path);
                        $success = false;
                    }
                }
            }

            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            @unlink($lock_file);

            if ($success) {
                self::record_variant($details['url'], $dest_path, $format);
            }

            return $success ? $dest_url : $original_url;
        } catch (\Exception $e) {
            if ($lock_handle) {
                flock($lock_handle, LOCK_UN);
                fclose($lock_handle);
            }
            @unlink($lock_file);
            self::log('Exception during conversion: ' . $e->getMessage(), 'error');
            return $original_url;
        }
    }

    /**
     * Helper to resize then convert
     */
    private static function get_variant_url($src, $format, $width, $height, $quality) {
        // Use metadata if already known
        $meta_url = self::get_variant_from_meta($src, $format, $width, $height);
        if ($meta_url) {
            return $meta_url;
        }

        $target = $src;
        if ($width || $height) {
            $resized = self::maybe_resize($src, $width, $height);
            if ($resized) {
                $target = $resized;
            }
        }

        return ($format === 'webp')
            ? self::convert_to_webp($target, $quality)
            : self::convert_to_avif($target, $quality);
    }

    /**
     * Resize helper using Timber's ImageHelper if available
     */
    private static function maybe_resize($src, $width, $height = null) {
        if (!$width && !$height) {
            return $src;
        }

        try {
            if (class_exists('Timber\\ImageHelper')) {
                return ImageHelper::resize($src, $width, $height);
            }
        } catch (\Throwable $e) {
            self::log('Resize failed: ' . $e->getMessage(), 'warning');
        }

        return $src;
    }

    /**
     * Background conversion using shutdown callback to avoid blocking response
     */
    private static function convert_async($src, $format) {
        register_shutdown_function(function () use ($src, $format) {
            if ($format === 'webp') {
                self::convert_to_webp($src);
            } else {
                self::convert_to_avif($src);
            }
        });
    }

    /**
     * Detect conversion capabilities (per format)
     */
    public static function detect_capabilities($format = 'avif') {
        $format = ($format === 'webp') ? 'webp' : 'avif';

        if (self::$conversion_methods[$format] !== null) {
            return self::$conversion_methods[$format];
        }

        $cache_key = self::CACHE_PREFIX . 'capability_' . $format;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            self::$conversion_methods[$format] = $cached;
            return $cached;
        }

        $method = 'none';

        if ($format === 'avif') {
            if (function_exists('imageavif') && self::test_gd_conversion('avif')) {
                $method = 'gd';
            } elseif (extension_loaded('imagick') && self::test_imagick_conversion('avif')) {
                $method = 'imagick';
            } elseif (self::is_exec_available() && self::test_exec_conversion('avif')) {
                $method = 'exec';
            }
        } else {
            if (function_exists('imagewebp') && self::test_gd_conversion('webp')) {
                $method = 'gd';
            } elseif (extension_loaded('imagick') && self::test_imagick_conversion('webp')) {
                $method = 'imagick';
            } elseif (self::is_exec_available() && self::test_exec_conversion('webp')) {
                $method = 'exec';
            }
        }

        set_transient($cache_key, $method, self::CAPABILITY_CACHE_DURATION);
        self::$conversion_methods[$format] = $method;
        self::log("Detected {$format} method: {$method}", 'info');
        return $method;
    }

    private static function test_gd_conversion($format) {
        try {
            $test_image = @imagecreatetruecolor(1, 1);
            if (!$test_image) {
                return false;
            }

            ob_start();
            $result = ($format === 'webp')
                ? @imagewebp($test_image, null, 80)
                : @imageavif($test_image, null, 80);
            ob_end_clean();

            imagedestroy($test_image);
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function test_imagick_conversion($format) {
        try {
            if (empty(\Imagick::queryFormats(strtoupper($format)))) {
                return false;
            }
            $imagick = new \Imagick();
            $imagick->newImage(1, 1, 'white');
            $imagick->setImageFormat($format);
            $blob = $imagick->getImageBlob();
            $imagick->clear();
            return !empty($blob);
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function test_exec_conversion($format) {
        if (!self::is_exec_available()) {
            return false;
        }

        // Actually test a 1x1 pixel conversion to confirm format support
        $tmp_src = tempnam(sys_get_temp_dir(), 'tavif_test_') . '.png';
        $tmp_dst = tempnam(sys_get_temp_dir(), 'tavif_test_') . '.' . $format;

        // Create a tiny PNG test image
        $img = @imagecreatetruecolor(1, 1);
        if (!$img) {
            return false;
        }
        @imagepng($img, $tmp_src);
        imagedestroy($img);

        $success = false;
        $src_arg = escapeshellarg($tmp_src);
        $dst_arg = escapeshellarg($tmp_dst);

        @exec("magick {$src_arg} {$dst_arg} 2>&1", $output, $return_var);
        if ($return_var !== 0) {
            @exec("convert {$src_arg} {$dst_arg} 2>&1", $output, $return_var);
        }

        if ($return_var === 0 && file_exists($tmp_dst) && filesize($tmp_dst) > 0) {
            $success = true;
        }

        @unlink($tmp_src);
        @unlink($tmp_dst);

        return $success;
    }

    /**
     * Perform conversion with chosen method
     */
    private static function perform_conversion($source, $destination, $quality, $format, $method) {
        switch ($method) {
            case 'gd':
                return self::convert_with_gd($source, $destination, $quality, $format);
            case 'imagick':
                return self::convert_with_imagick($source, $destination, $quality, $format);
            case 'exec':
                return self::convert_with_exec($source, $destination, $quality, $format);
            default:
                return false;
        }
    }

    private static function convert_with_gd($source, $destination, $quality, $format) {
        try {
            $image_type = @exif_imagetype($source);
            if (!$image_type) {
                return false;
            }

            $memory_needed = self::estimate_memory_needed($source, $image_type);
            self::ensure_memory_limit($memory_needed);

            $image = match ($image_type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
                IMAGETYPE_PNG => @imagecreatefrompng($source),
                IMAGETYPE_WEBP => @imagecreatefromwebp($source),
                IMAGETYPE_GIF => @imagecreatefromgif($source),
                default => null,
            };

            if (!$image) {
                return false;
            }

            if ($image_type === IMAGETYPE_PNG) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            $success = ($format === 'webp')
                ? @imagewebp($image, $destination, $quality)
                : @imageavif($image, $destination, $quality);

            imagedestroy($image);
            return $success;
        } catch (\Exception $e) {
            self::log("GD Exception: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private static function convert_with_imagick($source, $destination, $quality, $format) {
        try {
            $imagick = new \Imagick($source);
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            $imagick->setResourceLimit(\Imagick::RESOURCETYPE_TIME, 60);
            $imagick->setImageFormat($format);
            $imagick->setImageCompressionQuality($quality);
            $imagick->stripImage();
            $success = $imagick->writeImage($destination);
            $imagick->clear();
            $imagick->destroy();
            return $success;
        } catch (\Exception $e) {
            self::log("Imagick Exception: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private static function convert_with_exec($source, $destination, $quality, $format) {
        $source_arg = escapeshellarg($source);
        $dest_arg = escapeshellarg($destination);
        $quality_arg = intval($quality);

        $command = "magick {$source_arg} -quality {$quality_arg} {$dest_arg} 2>&1";
        @exec($command, $output, $return_var);
        if ($return_var === 0 && file_exists($destination) && filesize($destination) > 0) {
            return true;
        }

        $command = "convert {$source_arg} -quality {$quality_arg} {$dest_arg} 2>&1";
        @exec($command, $output, $return_var);
        if ($return_var === 0 && file_exists($destination) && filesize($destination) > 0) {
            return true;
        }

        self::log("Exec failed. Output: " . implode(' ', $output), 'error');
        return false;
    }

    /**
     * Validation helpers
     */
    private static function is_valid($path, $format) {
        if (!file_exists($path) || filesize($path) < 50) {
            return false;
        }

        $header = file_get_contents($path, false, null, 0, 16);
        if ($format === 'avif') {
            return (strpos($header, 'ftyp') !== false && strpos($header, 'avif') !== false);
        }
        return (strpos($header, 'WEBP') !== false);
    }

    private static function estimate_memory_needed($file_path, $image_type) {
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            return 64 * 1024 * 1024;
        }
        $width = $image_info[0];
        $height = $image_info[1];
        $channels = ($image_type === IMAGETYPE_PNG) ? 4 : 3;
        return ceil($width * $height * $channels * 1.5);
    }

    private static function ensure_memory_limit($needed_bytes) {
        $current_limit = ini_get('memory_limit');
        if ($current_limit == -1) {
            return;
        }
        $current_bytes = self::parse_memory_limit($current_limit);
        $required_bytes = $needed_bytes + (32 * 1024 * 1024);
        if ($current_bytes < $required_bytes) {
            $new_limit = ceil($required_bytes / 1024 / 1024) . 'M';
            @ini_set('memory_limit', $new_limit);
            self::log("Increased memory limit to {$new_limit}", 'info');
        }
    }

    private static function parse_memory_limit($limit) {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        return match ($last) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Paths and metadata helpers
     */
    private static function get_destination_path($file_path, $quality, $format, $custom_quality = false) {
        $filename = pathinfo($file_path, PATHINFO_FILENAME);
        $dirname = pathinfo($file_path, PATHINFO_DIRNAME);
        $default_quality = ($format === 'webp')
            ? self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY)
            : self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY);
        $suffix = ($custom_quality && $quality !== $default_quality) ? "-q{$quality}" : '';
        $ext = $format === 'webp' ? 'webp' : 'avif';
        return "{$dirname}/{$filename}{$suffix}.{$ext}";
    }

    private static function get_image_details($src) {
        if ($src instanceof Image) {
            return ['path' => $src->file_loc, 'url' => $src->src];
        }

        if (is_string($src)) {
            $url = $src;
            $theme_dir = get_template_directory();
            $theme_url = get_template_directory_uri();
            if (str_starts_with($url, $theme_url)) {
                $path = str_replace($theme_url, $theme_dir, $url);
                return ['path' => $path, 'url' => $url];
            }
            $upload_dir = wp_upload_dir();
            if (str_starts_with($url, $upload_dir['baseurl'])) {
                $path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
                return ['path' => $path, 'url' => $url];
            }
            try {
                $attachment_id = self::get_attachment_id_from_url($url);
                if ($attachment_id) {
                    $file_path = get_attached_file($attachment_id);
                    if ($file_path) {
                        return ['path' => $file_path, 'url' => $url];
                    }
                }
            } catch (\Exception $e) {
                self::log('Error resolving image: ' . $e->getMessage(), 'warning');
            }
            return ['path' => null, 'url' => $url];
        }

        return ['path' => null, 'url' => ''];
    }

    private static function get_attachment_id_from_url($url) {
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            return intval($attachment_id);
        }
        global $wpdb;
        $filename = basename($url);
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($filename)
        ));
        return $attachment_id ? intval($attachment_id) : null;
    }

    private static function path_to_url($path) {
        $upload_dir = wp_upload_dir();
        if (str_starts_with($path, $upload_dir['basedir'])) {
            return str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $path);
        }
        return $path;
    }

    private static function record_variant($original_url, $dest_path, $format) {
        $attachment_id = self::get_attachment_id_from_url($original_url);
        if (!$attachment_id) {
            return;
        }

        $info = @getimagesize($dest_path);
        $key = ($info && isset($info[0], $info[1])) ? $info[0] . 'x' . $info[1] : 'original';

        $meta = get_post_meta($attachment_id, '_timber_variants', true);
        if (!is_array($meta)) {
            $meta = [];
        }
        if (!isset($meta[$format])) {
            $meta[$format] = [];
        }

        $meta[$format][$key] = self::path_to_url($dest_path);
        update_post_meta($attachment_id, '_timber_variants', $meta);
    }

    private static function get_variant_from_meta($src, $format, $width, $height) {
        $attachment_id = null;
        if ($src instanceof Image) {
            $attachment_id = $src->ID ?? null;
        } elseif (is_string($src)) {
            $attachment_id = self::get_attachment_id_from_url($src);
        }

        if (!$attachment_id) {
            return null;
        }

        $meta = get_post_meta($attachment_id, '_timber_variants', true);
        if (!is_array($meta) || empty($meta[$format])) {
            return null;
        }

        if ($width && $height) {
            $key = intval($width) . 'x' . intval($height);
            if (!empty($meta[$format][$key])) {
                return $meta[$format][$key];
            }
        }

        if ($width && !$height) {
            foreach ($meta[$format] as $size_key => $url) {
                $parts = explode('x', $size_key);
                if (count($parts) === 2 && intval($parts[0]) === intval($width)) {
                    return $url;
                }
            }
        }

        // Fallback to original format entry if available
        if (!empty($meta[$format]['original'])) {
            return $meta[$format]['original'];
        }

        return null;
    }

    /**
     * Logging helper
     */
    private static function log($message, $level = 'debug') {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        $level_upper = strtoupper($level);
        error_log("[Timber AVIF][{$level_upper}] {$message}");
    }

    /**
     * Admin notice when AVIF not supported
     */
    public static function check_avif_support_admin_notice() {
        if (self::detect_capabilities('avif') !== 'none') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>Timber AVIF:</strong> No server support detected for AVIF conversion. Images will fall back to original formats.</p>';
        echo '</div>';
    }

    /**
     * Clear caches and detection state
     */
    public static function clear_cache() {
        delete_transient(self::CACHE_PREFIX . 'capability_avif');
        delete_transient(self::CACHE_PREFIX . 'capability_webp');
        wp_cache_flush_group('avif_converter');
        self::$conversion_methods = ['avif' => null, 'webp' => null];
        self::detect_capabilities('avif');
        self::detect_capabilities('webp');
        return 'Cache cleared';
    }

    /**
     * Purge all generated AVIF and WebP files from uploads
     */
    public static function purge_all_conversions() {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $deleted = 0;

        // Collect original attachment paths so we never delete uploaded originals
        $original_paths = [];
        $originals = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/avif', 'image/webp'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        foreach ($originals as $id) {
            $path = get_attached_file($id);
            if ($path) {
                $original_paths[realpath($path)] = true;
                // Also protect WordPress-generated thumbnails of these originals
                $meta = wp_get_attachment_metadata($id);
                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    $dir = dirname($path);
                    foreach ($meta['sizes'] as $size) {
                        if (!empty($size['file'])) {
                            $thumb = realpath($dir . '/' . $size['file']);
                            if ($thumb) {
                                $original_paths[$thumb] = true;
                            }
                        }
                    }
                }
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if ($ext === 'avif' || $ext === 'webp') {
                $real = realpath($file->getPathname());
                // Skip files that are original uploads
                if ($real && isset($original_paths[$real])) {
                    continue;
                }
                if (@unlink($file->getPathname())) {
                    $deleted++;
                }
            }
        }

        // Clear all _timber_variants post meta
        global $wpdb;
        $wpdb->delete($wpdb->postmeta, ['meta_key' => '_timber_variants']);

        // Clear statistics cache
        delete_transient(self::CACHE_PREFIX . 'statistics');

        return $deleted;
    }

    /**
     * Convert a single attachment: original + WP sizes + optional breakpoints
     */
    private static function convert_single_attachment($attachment_id) {
        $file_path = get_attached_file($attachment_id);
        if (!$file_path || !file_exists($file_path)) {
            return;
        }

        $url = wp_get_attachment_url($attachment_id);
        if (!$url) {
            return;
        }

        $do_avif = self::get_setting('generate_avif_uploads');
        $do_webp = self::get_setting('generate_webp_uploads');

        // Original
        if ($do_avif) {
            self::convert_to_avif($url, self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY));
        }
        if ($do_webp) {
            self::convert_to_webp($url, self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY));
        }

        // WP-registered sizes (thumbnails, medium, large, etc.)
        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir_url = trailingslashit(dirname($url));
            foreach ($metadata['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }
                $size_url = $base_dir_url . $size['file'];
                if ($do_avif) {
                    self::convert_to_avif($size_url, self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY));
                }
                if ($do_webp) {
                    self::convert_to_webp($size_url, self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY));
                }
            }
        }

        // Breakpoints
        if (self::get_setting('pregenerate_breakpoints')) {
            $widths = array_filter(array_map('intval', explode(',', self::get_setting('breakpoint_widths'))));
            foreach ($widths as $width) {
                $resized = self::maybe_resize($url, $width, null);
                if ($resized) {
                    if ($do_avif) {
                        self::convert_to_avif($resized, self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY));
                    }
                    if ($do_webp) {
                        self::convert_to_webp($resized, self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY));
                    }
                }
            }
        }
    }

    /**
     * Bulk convert helper
     */
    public static function bulk_convert_media() {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        foreach ($attachments as $attachment_id) {
            self::convert_single_attachment($attachment_id);
        }

        delete_transient(self::CACHE_PREFIX . 'statistics');
    }

    /**
     * Exec availability check
     */
    private static function is_exec_available() {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    /**
     * Fetch settings value
     */
    private static function get_setting($key, $default = null) {
        return self::$settings[$key] ?? $default;
    }

    /**
     * Media library: add "Optimized" column
     */
    public static function add_media_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'date') {
                $new['tavif_optimized'] = 'Optimized';
            }
        }
        return $new;
    }

    /**
     * Media library: render badges in "Optimized" column
     */
    public static function render_media_column($column, $post_id) {
        if ($column !== 'tavif_optimized') {
            return;
        }

        $mime = get_post_mime_type($post_id);
        if (!$mime || !str_starts_with($mime, 'image/')) {
            echo '&mdash;';
            return;
        }

        $file = get_attached_file($post_id);
        if (!$file || !file_exists($file)) {
            echo '&mdash;';
            return;
        }

        $avif_path = preg_replace('/\.(jpe?g|png|gif)$/i', '.avif', $file);
        $webp_path = preg_replace('/\.(jpe?g|png|gif)$/i', '.webp', $file);
        $has_avif = file_exists($avif_path) && $avif_path !== $file;
        $has_webp = file_exists($webp_path) && $webp_path !== $file;

        if (!$has_avif && !$has_webp) {
            echo '<span style="color:#9ca3af;">&mdash;</span>';
            return;
        }

        if ($has_avif) {
            echo '<span class="tavif-col-badge tavif-col-badge--avif">AVIF</span> ';
        }
        if ($has_webp) {
            echo '<span class="tavif-col-badge tavif-col-badge--webp">WebP</span>';
        }
    }

    /**
     * Inline CSS for media library column (only on upload.php)
     */
    public static function media_column_css() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'upload') {
            return;
        }
        ?>
        <style>
            .fixed .column-tavif_optimized { width: 90px; text-align: center; }
            .tavif-col-badge { display: inline-block; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; line-height: 1.3; }
            .tavif-col-badge--avif { background: #d1fae5; color: #065f46; }
            .tavif-col-badge--webp { background: #dbeafe; color: #1e40af; }
        </style>
        <?php
    }

    /**
     * Register WP-CLI commands
     */
    private static function register_cli() {
        WP_CLI::add_command('timber-avif clear-cache', function () {
            WP_CLI::success(self::clear_cache());
        });

        WP_CLI::add_command('timber-avif detect', function () {
            $avif = self::detect_capabilities('avif');
            $webp = self::detect_capabilities('webp');
            WP_CLI::log("AVIF: {$avif}");
            WP_CLI::log("WebP: {$webp}");
        });

        WP_CLI::add_command('timber-avif bulk', function ($args, $assoc_args) {
            $quality = isset($assoc_args['quality']) ? intval($assoc_args['quality']) : self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY);
            $webp_quality = isset($assoc_args['webp-quality']) ? intval($assoc_args['webp-quality']) : self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY);
            $enable_webp = isset($assoc_args['webp']) ? filter_var($assoc_args['webp'], FILTER_VALIDATE_BOOLEAN) : self::get_setting('generate_webp_uploads');

            WP_CLI::log("Bulk converting media (AVIF {$quality}, WebP {$webp_quality}, webp: " . ($enable_webp ? 'yes' : 'no') . ' )');

            $query_args = [
                'post_type'      => 'attachment',
                'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif'],
                'posts_per_page' => -1,
                'post_status'    => 'any',
                'fields'         => 'ids',
            ];

            $attachments = get_posts($query_args);
            $total = count($attachments);
            if ($total === 0) {
                WP_CLI::warning('No images found.');
                return;
            }

            $progress = \WP_CLI\Utils\make_progress_bar("Converting {$total} images", $total);
            foreach ($attachments as $attachment_id) {
                self::convert_single_attachment($attachment_id);
                $progress->tick();
            }
            $progress->finish();
            WP_CLI::success('Bulk conversion complete');
        });
    }
}

// Initialize
AVIFConverter::init();
