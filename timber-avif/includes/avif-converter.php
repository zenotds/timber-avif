<?php
/**
 * Timber AVIF Converter - v3.0.0
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
            'auto_convert_uploads'   => true,
            'enable_webp'            => true,
            'avif_quality'           => self::DEFAULT_AVIF_QUALITY,
            'webp_quality'           => self::DEFAULT_WEBP_QUALITY,
            'only_if_smaller'        => self::ONLY_IF_SMALLER,
            'max_dimension'          => self::MAX_IMAGE_DIMENSION,
            'max_file_size'          => self::MAX_FILE_SIZE_MB,
            'pregenerate_breakpoints' => false,
            'breakpoint_widths'      => '640,768,1024,1280,1600,1920,2560',
        ];

        $saved = get_option(self::OPTION_KEY, []);
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
        self::convert_async($new_url, 'avif');
        if (self::get_setting('enable_webp')) {
            self::convert_async($new_url, 'webp');
        }
        return $new_url;
    }

    /**
     * Convert original + registered sizes after upload
     */
    public static function generate_for_upload($metadata, $attachment_id) {
        if (!self::get_setting('auto_convert_uploads')) {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $base_url = trailingslashit($upload_dir['baseurl']) . ltrim($metadata['file'], '/');

        self::convert_async($base_url, 'avif');
        if (self::get_setting('enable_webp')) {
            self::convert_async($base_url, 'webp');
        }

        // Convert generated sizes
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size) {
                if (empty($size['file'])) {
                    continue;
                }
                $size_url = trailingslashit(dirname($base_url)) . $size['file'];
                self::convert_async($size_url, 'avif');
                if (self::get_setting('enable_webp')) {
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
                    self::convert_async($resized, 'avif');
                    if (self::get_setting('enable_webp')) {
                        self::convert_async($resized, 'webp');
                    }
                }
            }
        }

        return $metadata;
    }

    /**
     * Register admin screen under Tools
     */
    public static function register_admin_page() {
        add_management_page(
            'Timber AVIF',
            'Timber AVIF',
            'manage_options',
            'timber-avif',
            [__CLASS__, 'render_admin_page']
        );
    }

    /**
     * Render admin page
     */
    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::$settings;
        ?>
        <div class="wrap">
            <h1>Timber AVIF Tools</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('timber_avif_settings'); ?>
                <input type="hidden" name="action" value="timber_avif_tools" />
                <input type="hidden" name="subaction" value="save_settings" />

                <h2 class="title">Settings</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Auto-convert uploads</th>
                        <td><label><input type="checkbox" name="auto_convert_uploads" value="1" <?php checked($settings['auto_convert_uploads']); ?> /> Enable AVIF/WebP generation on upload</label></td>
                    </tr>
                    <tr>
                        <th scope="row">WebP support</th>
                        <td><label><input type="checkbox" name="enable_webp" value="1" <?php checked($settings['enable_webp']); ?> /> Generate WebP variants alongside AVIF</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Quality</th>
                        <td>
                            <label>AVIF <input type="number" name="avif_quality" min="1" max="100" value="<?php echo esc_attr($settings['avif_quality']); ?>" /></label>
                            <label style="margin-left:1rem;">WebP <input type="number" name="webp_quality" min="1" max="100" value="<?php echo esc_attr($settings['webp_quality']); ?>" /></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Size safeguards</th>
                        <td>
                            <label>Max dimension <input type="number" name="max_dimension" value="<?php echo esc_attr($settings['max_dimension']); ?>" /></label>
                            <label style="margin-left:1rem;">Max file size MB <input type="number" name="max_file_size" step="1" value="<?php echo esc_attr($settings['max_file_size']); ?>" /></label>
                            <label style="margin-left:1rem;"><input type="checkbox" name="only_if_smaller" value="1" <?php checked($settings['only_if_smaller']); ?> /> Only keep converted file if smaller</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Pregenerate widths</th>
                        <td>
                            <label><input type="checkbox" name="pregenerate_breakpoints" value="1" <?php checked($settings['pregenerate_breakpoints']); ?> /> Generate breakpoints on upload</label><br/>
                            <input type="text" name="breakpoint_widths" value="<?php echo esc_attr($settings['breakpoint_widths']); ?>" class="regular-text" />
                            <p class="description">Comma-separated widths (px) used to warm responsive caches.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <hr />

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('timber_avif_tools'); ?>
                <input type="hidden" name="action" value="timber_avif_tools" />
                <input type="hidden" name="subaction" value="bulk_convert" />
                <?php submit_button('Bulk convert existing media'); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1rem;">
                <?php wp_nonce_field('timber_avif_tools'); ?>
                <input type="hidden" name="action" value="timber_avif_tools" />
                <input type="hidden" name="subaction" value="clear_cache" />
                <?php submit_button('Clear caches / capability detection', 'secondary'); ?>
            </form>
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

        if ($subaction === 'save_settings') {
            check_admin_referer('timber_avif_settings');
            self::$settings['auto_convert_uploads'] = !empty($_POST['auto_convert_uploads']);
            self::$settings['enable_webp'] = !empty($_POST['enable_webp']);
            self::$settings['avif_quality'] = max(1, min(100, intval($_POST['avif_quality'] ?? self::DEFAULT_AVIF_QUALITY)));
            self::$settings['webp_quality'] = max(1, min(100, intval($_POST['webp_quality'] ?? self::DEFAULT_WEBP_QUALITY)));
            self::$settings['only_if_smaller'] = !empty($_POST['only_if_smaller']);
            self::$settings['max_dimension'] = max(512, intval($_POST['max_dimension'] ?? self::MAX_IMAGE_DIMENSION));
            self::$settings['max_file_size'] = max(1, intval($_POST['max_file_size'] ?? self::MAX_FILE_SIZE_MB));
            self::$settings['pregenerate_breakpoints'] = !empty($_POST['pregenerate_breakpoints']);
            self::$settings['breakpoint_widths'] = sanitize_text_field($_POST['breakpoint_widths'] ?? '');
            update_option(self::OPTION_KEY, self::$settings);
            wp_safe_redirect(add_query_arg('updated', 'true', admin_url('tools.php?page=timber-avif')));
            exit;
        }

        check_admin_referer('timber_avif_tools');

        if ($subaction === 'bulk_convert') {
            self::bulk_convert_media();
            wp_safe_redirect(add_query_arg('converted', 'true', admin_url('tools.php?page=timber-avif')));
            exit;
        }

        if ($subaction === 'clear_cache') {
            self::clear_cache();
            wp_safe_redirect(add_query_arg('cleared', 'true', admin_url('tools.php?page=timber-avif')));
            exit;
        }

        wp_safe_redirect(admin_url('tools.php?page=timber-avif'));
        exit;
    }

    /**
     * Wrapper for Twig filter (AVIF)
     */
    public static function convert_to_avif($src, $quality = null, $force = false) {
        $quality = $quality ? intval($quality) : self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY);
        return self::convert($src, 'avif', $quality, $force);
    }

    /**
     * WebP helper (used by Twig function/filter)
     */
    public static function convert_to_webp($src, $quality = null, $force = false) {
        $quality = $quality ? intval($quality) : self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY);
        return self::convert($src, 'webp', $quality, $force);
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
        $quality = $quality ? intval($quality) : self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY);
        if (!self::get_setting('enable_webp')) {
            return is_string($src) ? $src : ($src instanceof Image ? $src->src : '');
        }
        return self::get_variant_url($src, 'webp', $width, $height, $quality);
    }

    /**
     * Main conversion handler
     */
    private static function convert($src, $format, $quality, $force) {
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
        $dest_path = self::get_destination_path($file_path, $quality, $format);
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

        @exec('magick --version 2>&1', $output, $return_var);
        if ($return_var === 0) {
            return true;
        }

        @exec('convert --version 2>&1', $output, $return_var);
        return $return_var === 0;
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
    private static function get_destination_path($file_path, $quality, $format) {
        $filename = pathinfo($file_path, PATHINFO_FILENAME);
        $dirname = pathinfo($file_path, PATHINFO_DIRNAME);
        $suffix = ($quality === ($format === 'webp' ? self::DEFAULT_WEBP_QUALITY : self::DEFAULT_AVIF_QUALITY)) ? '' : "-q{$quality}";
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
     * Bulk convert helper
     */
    public static function bulk_convert_media() {
        $query_args = [
            'post_type'      => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ];

        $attachments = get_posts($query_args);
        foreach ($attachments as $attachment_id) {
            $file_path = get_attached_file($attachment_id);
            if (!$file_path || !file_exists($file_path)) {
                continue;
            }
            $url = wp_get_attachment_url($attachment_id);
            self::convert_to_avif($url, self::get_setting('avif_quality', self::DEFAULT_AVIF_QUALITY));
            if (self::get_setting('enable_webp')) {
                self::convert_to_webp($url, self::get_setting('webp_quality', self::DEFAULT_WEBP_QUALITY));
            }
        }
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
            $enable_webp = isset($assoc_args['webp']) ? filter_var($assoc_args['webp'], FILTER_VALIDATE_BOOLEAN) : self::get_setting('enable_webp');

            WP_CLI::log("Bulk converting media (AVIF {$quality}, WebP {$webp_quality}, webp: " . ($enable_webp ? 'yes' : 'no') . ' )');

            $query_args = [
                'post_type'      => 'attachment',
                'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
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
                $url = wp_get_attachment_url($attachment_id);
                self::convert_to_avif($url, $quality, true);
                if ($enable_webp) {
                    self::convert_to_webp($url, $webp_quality, true);
                }
                $progress->tick();
            }
            $progress->finish();
            WP_CLI::success('Bulk conversion complete');
        });
    }
}

// Initialize
AVIFConverter::init();
