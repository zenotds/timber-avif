<?php
/**
 * Timber AVIF Converter - Core Conversion Class
 *
 * @package TimberAVIF
 * @version 3.0.0
 */

use Timber\Image;

class Timber_AVIF_Converter {

    // Cached conversion method ('gd', 'imagick', 'exec', or 'none')
    private static $conversion_method = null;

    // Cache for settings
    private static $settings = null;

    /**
     * Initialize hooks and filters
     */
    public static function init() {
        // Register Twig filters
        add_filter('timber/twig', [__CLASS__, 'add_twig_filters']);

        // Auto-convert on upload
        add_filter('wp_generate_attachment_metadata', [__CLASS__, 'auto_convert_on_upload'], 10, 2);

        // Hook into Timber resize filter
        add_filter('timber/image/new_url', [__CLASS__, 'handle_resized_image'], 10, 3);

        // Detect capabilities on init
        self::detect_capabilities();
    }

    /**
     * Get plugin settings with defaults
     */
    private static function get_settings() {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $defaults = [
            'enable_auto_convert' => true,
            'enable_webp' => true,
            'avif_quality' => 80,
            'webp_quality' => 85,
            'max_dimension' => 4096,
            'max_file_size' => 50,
            'only_if_smaller' => true,
            'stale_lock_timeout' => 300,
            'enable_debug_logging' => false,
            'pregenerate_sizes' => false,
            'common_sizes' => '800,1200,1600,2400',
            'enable_smart_quality' => false,
            'smart_quality_rules' => [
                ['max_dimension' => 1000, 'avif' => 85, 'webp' => 90],
                ['max_dimension' => 2000, 'avif' => 80, 'webp' => 85],
                ['max_dimension' => PHP_INT_MAX, 'avif' => 75, 'webp' => 80]
            ]
        ];

        $settings = get_option('timber_avif_settings', []);
        self::$settings = array_merge($defaults, $settings);

        return self::$settings;
    }

    /**
     * Register Twig filters
     */
    public static function add_twig_filters($twig) {
        // Only register toavif filter (towebp is built into Timber core)
        // Check if toavif already exists (e.g., from v2.5 theme file)
        $existing_filter = false;
        try {
            $existing_filter = $twig->getFilter('toavif');
        } catch (\Exception $e) {
            // getFilter might throw, treat as not existing
        }

        if (!$existing_filter) {
            $twig->addFilter(new \Twig\TwigFilter('toavif', [__CLASS__, 'convert_to_avif']));
        }

        // Add smart filter (new in v3.0)
        $existing_smart = false;
        try {
            $existing_smart = $twig->getFilter('smart');
        } catch (\Exception $e) {
            // getFilter might throw, treat as not existing
        }

        if (!$existing_smart) {
            $twig->addFilter(new \Twig\TwigFilter('smart', [__CLASS__, 'get_best_format']));
        }

        // Note: We don't register 'towebp' as it's built into Timber core
        // Users should continue using Timber's built-in |towebp filter

        return $twig;
    }

    /**
     * Auto-converts images on upload
     */
    public static function auto_convert_on_upload($metadata, $attachment_id) {
        $settings = self::get_settings();

        if (!$settings['enable_auto_convert']) {
            return $metadata;
        }

        if (!isset($metadata['file'])) {
            return $metadata;
        }

        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . $metadata['file'];

        if (!file_exists($file_path)) {
            return $metadata;
        }

        self::log('Auto-converting uploaded image: ' . basename($file_path), 'info');

        // Convert full-size image
        $avif_created = self::convert_image($file_path, 'avif');
        $webp_created = $settings['enable_webp'] ? self::convert_image($file_path, 'webp') : false;

        // Track what was created
        $formats_created = [];
        if ($avif_created) $formats_created[] = 'avif';
        if ($webp_created) $formats_created[] = 'webp';

        // Store metadata
        update_post_meta($attachment_id, '_avif_available', $avif_created);
        update_post_meta($attachment_id, '_webp_available', $webp_created);

        // Pre-generate common sizes if enabled
        if ($settings['pregenerate_sizes'] && !empty($settings['common_sizes'])) {
            self::pregenerate_common_sizes($file_path, $settings['common_sizes'], $formats_created);
        }

        // Track statistics
        self::track_conversion($file_path, $formats_created);

        return $metadata;
    }

    /**
     * Pre-generate common sizes
     */
    private static function pregenerate_common_sizes($file_path, $sizes_config, $formats) {
        $sizes = array_map('intval', array_map('trim', explode(',', $sizes_config)));

        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            return;
        }

        $original_width = $image_info[0];
        $original_height = $image_info[1];

        foreach ($sizes as $target_width) {
            // Skip if target is larger than original
            if ($target_width >= $original_width) {
                continue;
            }

            // Calculate proportional height
            $target_height = round($original_height * ($target_width / $original_width));

            // Generate resized version
            $resized_path = self::get_resized_path($file_path, $target_width, $target_height);

            if (!file_exists($resized_path)) {
                self::create_resized_image($file_path, $resized_path, $target_width, $target_height);
            }

            // Generate AVIF/WebP of resized version
            if (in_array('avif', $formats) && file_exists($resized_path)) {
                self::convert_image($resized_path, 'avif');
            }
            if (in_array('webp', $formats) && file_exists($resized_path)) {
                self::convert_image($resized_path, 'webp');
            }
        }

        self::log("Pre-generated " . count($sizes) . " common sizes for: " . basename($file_path), 'info');
    }

    /**
     * Handle Timber resized images - generate AVIF/WebP versions
     */
    public static function handle_resized_image($new_url, $src, $params) {
        // Get the file path from URL
        $upload_dir = wp_upload_dir();
        $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $new_url);

        if (!file_exists($file_path)) {
            return $new_url;
        }

        $settings = self::get_settings();

        // Generate AVIF version of resized image
        self::convert_image($file_path, 'avif');

        // Generate WebP version if enabled
        if ($settings['enable_webp']) {
            self::convert_image($file_path, 'webp');
        }

        return $new_url;
    }

    /**
     * Main AVIF conversion entry point (for Twig filter)
     */
    public static function convert_to_avif($src, $quality = null, $force = false) {
        return self::convert_to_format($src, 'avif', $quality, $force);
    }

    /**
     * Main WebP conversion entry point (for Twig filter)
     */
    public static function convert_to_webp($src, $quality = null, $force = false) {
        return self::convert_to_format($src, 'webp', $quality, $force);
    }

    /**
     * Get best format based on browser support
     */
    public static function get_best_format($src) {
        if (empty($src)) {
            return '';
        }

        $details = self::get_image_details($src);
        $file_path = $details['path'];
        $original_url = $details['url'];

        if (!$file_path || !file_exists($file_path)) {
            return $original_url;
        }

        // Check browser support
        $supports_avif = self::browser_supports_avif();
        $supports_webp = self::browser_supports_webp();

        // Try AVIF first
        if ($supports_avif) {
            $avif_path = self::get_converted_path($file_path, 'avif');
            if (file_exists($avif_path)) {
                return str_replace(wp_basename($file_path), wp_basename($avif_path), $original_url);
            }
        }

        // Try WebP second
        if ($supports_webp) {
            $webp_path = self::get_converted_path($file_path, 'webp');
            if (file_exists($webp_path)) {
                return str_replace(wp_basename($file_path), wp_basename($webp_path), $original_url);
            }
        }

        // Return original
        return $original_url;
    }

    /**
     * Universal format conversion
     */
    private static function convert_to_format($src, $format, $quality = null, $force = false) {
        if (empty($src)) {
            return '';
        }

        $settings = self::get_settings();

        // Use provided quality or default from settings
        if ($quality === null) {
            $quality = $format === 'avif' ? $settings['avif_quality'] : $settings['webp_quality'];
        }
        $quality = max(1, min(100, intval($quality)));

        // Check if conversion is available
        if (self::$conversion_method === 'none') {
            return is_string($src) ? $src : (($src instanceof \Timber\Image) ? $src->src : '');
        }

        $details = self::get_image_details($src);
        $file_path = $details['path'];
        $original_url = $details['url'];

        if (!$file_path || !file_exists($file_path)) {
            self::log("Source file not found: '{$original_url}'", 'warning');
            return $original_url;
        }

        // Check file size
        $file_size_mb = filesize($file_path) / 1024 / 1024;
        if ($file_size_mb > $settings['max_file_size']) {
            self::log("File too large for conversion ({$file_size_mb}MB): {$original_url}", 'info');
            return $original_url;
        }

        // Check dimensions
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            self::log("Unable to read image info: {$file_path}", 'warning');
            return $original_url;
        }

        if ($image_info[0] > $settings['max_dimension'] || $image_info[1] > $settings['max_dimension']) {
            self::log("Image dimensions exceed maximum ({$image_info[0]}x{$image_info[1]}): {$file_path}", 'info');
            return $original_url;
        }

        // Get converted path
        $converted_path = self::get_converted_path($file_path, $format, $quality);
        $converted_url = str_replace(wp_basename($file_path), wp_basename($converted_path), $original_url);

        // Check if already exists
        if (!$force && file_exists($converted_path) && filesize($converted_path) > 0) {
            if (self::is_valid_image($converted_path, $format)) {
                return $converted_url;
            } else {
                @unlink($converted_path);
                self::log("Corrupted {$format} file removed: {$converted_path}", 'warning');
            }
        }

        // Convert the image
        $success = self::convert_image($file_path, $format, $quality);

        return $success ? $converted_url : $original_url;
    }

    /**
     * Core conversion logic
     */
    private static function convert_image($file_path, $format, $quality = null) {
        $settings = self::get_settings();

        // Use smart quality if enabled
        if ($quality === null) {
            $quality = self::get_smart_quality($file_path, $format);
        }

        $converted_path = self::get_converted_path($file_path, $format, $quality);

        // Check if already exists
        if (file_exists($converted_path) && filesize($converted_path) > 0) {
            if (self::is_valid_image($converted_path, $format)) {
                return true;
            }
        }

        // Check write permissions
        $target_dir = dirname($converted_path);
        if (!is_writable($target_dir)) {
            self::log("Directory not writable: {$target_dir}", 'error');
            return false;
        }

        // Handle lock file
        $lock_file = $converted_path . '.lock';

        // Clean up stale locks
        if (file_exists($lock_file)) {
            $lock_age = time() - filemtime($lock_file);
            if ($lock_age > $settings['stale_lock_timeout']) {
                @unlink($lock_file);
                self::log("Removed stale lock file (age: {$lock_age}s): {$lock_file}", 'warning');
            }
        }

        $lock_handle = @fopen($lock_file, 'c');
        if (!$lock_handle || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
            self::log("Conversion already in progress: {$converted_path}", 'info');
            if ($lock_handle) fclose($lock_handle);
            return false;
        }

        try {
            // Perform conversion
            $success = self::perform_conversion($file_path, $converted_path, $quality, $format);

            if ($success) {
                // Validate
                if (!self::is_valid_image($converted_path, $format)) {
                    @unlink($converted_path);
                    self::log("Generated {$format} failed validation: {$converted_path}", 'error');
                    $success = false;
                } else {
                    // Check if smaller
                    if ($settings['only_if_smaller']) {
                        $original_size = filesize($file_path);
                        $converted_size = filesize($converted_path);

                        if ($converted_size >= $original_size) {
                            @unlink($converted_path);
                            $savings = $original_size - $converted_size;
                            self::log("{$format} not smaller than original ({$savings} bytes), removed: {$converted_path}", 'info');
                            $success = false;
                        } else {
                            $percent_saved = round((1 - $converted_size / $original_size) * 100, 1);
                            self::log("{$format} created successfully, {$percent_saved}% smaller: {$converted_path}", 'info');
                        }
                    }
                }
            }

            // Release lock
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            @unlink($lock_file);

            return $success;

        } catch (\Exception $e) {
            if ($lock_handle) {
                flock($lock_handle, LOCK_UN);
                fclose($lock_handle);
            }
            @unlink($lock_file);
            self::log("Exception during conversion: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get smart quality based on image dimensions and format
     */
    private static function get_smart_quality($file_path, $format) {
        $settings = self::get_settings();

        // Use default quality if smart quality is disabled
        if (!$settings['enable_smart_quality']) {
            return $format === 'avif' ? $settings['avif_quality'] : $settings['webp_quality'];
        }

        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            return $format === 'avif' ? $settings['avif_quality'] : $settings['webp_quality'];
        }

        $max_dimension = max($image_info[0], $image_info[1]);

        // Apply smart quality rules
        foreach ($settings['smart_quality_rules'] as $rule) {
            if ($max_dimension <= $rule['max_dimension']) {
                return $format === 'avif' ? $rule['avif'] : $rule['webp'];
            }
        }

        return $format === 'avif' ? $settings['avif_quality'] : $settings['webp_quality'];
    }

    /**
     * Perform the actual conversion
     */
    private static function perform_conversion($source, $destination, $quality, $format) {
        switch (self::$conversion_method) {
            case 'gd':
                return self::convert_with_gd($source, $destination, $quality, $format);

            case 'imagick':
                return self::convert_with_imagick($source, $destination, $quality, $format);

            case 'exec':
                return self::convert_with_exec($source, $destination, $quality, $format);

            default:
                self::log("No conversion method available for: {$source}", 'error');
                return false;
        }
    }

    /**
     * GD conversion
     */
    private static function convert_with_gd($source, $destination, $quality, $format) {
        // Check if format is supported
        if ($format === 'avif' && !function_exists('imageavif')) {
            return false;
        }
        if ($format === 'webp' && !function_exists('imagewebp')) {
            return false;
        }

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
                self::log("GD failed to create image resource", 'error');
                return false;
            }

            // Preserve alpha
            if ($image_type === IMAGETYPE_PNG) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            $success = match($format) {
                'avif' => @imageavif($image, $destination, $quality),
                'webp' => @imagewebp($image, $destination, $quality),
                default => false
            };

            imagedestroy($image);
            return $success;

        } catch (\Exception $e) {
            self::log("GD Exception: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * ImageMagick conversion
     */
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
            self::log("ImageMagick Exception: " . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Exec conversion
     */
    private static function convert_with_exec($source, $destination, $quality, $format) {
        $source_arg = escapeshellarg($source);
        $dest_arg = escapeshellarg($destination);
        $quality_arg = intval($quality);

        // Try magick command first (ImageMagick 7+)
        $command = "magick {$source_arg} -quality {$quality_arg} {$dest_arg} 2>&1";
        @exec($command, $output, $return_var);

        if ($return_var === 0 && file_exists($destination) && filesize($destination) > 0) {
            return true;
        }

        // Fallback to convert command (ImageMagick 6)
        $command = "convert {$source_arg} -quality {$quality_arg} {$dest_arg} 2>&1";
        @exec($command, $output, $return_var);

        if ($return_var === 0 && file_exists($destination) && filesize($destination) > 0) {
            return true;
        }

        self::log("Exec failed. Output: " . implode(' ', $output), 'error');
        return false;
    }

    /**
     * Validate image file
     */
    private static function is_valid_image($path, $format) {
        if (!file_exists($path) || filesize($path) < 100) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        // Check format-specific magic bytes
        if ($format === 'avif') {
            return (strpos($header, 'ftyp') !== false && strpos($header, 'avif') !== false);
        } elseif ($format === 'webp') {
            return (strpos($header, 'RIFF') !== false && strpos($header, 'WEBP') !== false);
        }

        return true;
    }

    /**
     * Get converted file path
     */
    private static function get_converted_path($file_path, $format, $quality = null) {
        $settings = self::get_settings();
        $default_quality = $format === 'avif' ? $settings['avif_quality'] : $settings['webp_quality'];

        $filename = pathinfo($file_path, PATHINFO_FILENAME);
        $dirname = pathinfo($file_path, PATHINFO_DIRNAME);

        $suffix = ($quality !== null && $quality != $default_quality) ? "-q{$quality}" : '';
        return "{$dirname}/{$filename}{$suffix}.{$format}";
    }

    /**
     * Get resized image path
     */
    private static function get_resized_path($file_path, $width, $height) {
        $dirname = pathinfo($file_path, PATHINFO_DIRNAME);
        $filename = pathinfo($file_path, PATHINFO_FILENAME);
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);

        return "{$dirname}/{$filename}-{$width}x{$height}.{$extension}";
    }

    /**
     * Create resized image using GD/Imagick
     */
    private static function create_resized_image($source, $destination, $width, $height) {
        // Simple resize using GD
        $image_type = @exif_imagetype($source);
        if (!$image_type) {
            return false;
        }

        $source_image = match ($image_type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => @imagecreatefromwebp($source),
            default => null,
        };

        if (!$source_image) {
            return false;
        }

        $dest_image = imagecreatetruecolor($width, $height);

        // Preserve transparency
        imagealphablending($dest_image, false);
        imagesavealpha($dest_image, true);

        $success = imagecopyresampled(
            $dest_image, $source_image,
            0, 0, 0, 0,
            $width, $height,
            imagesx($source_image), imagesy($source_image)
        );

        if ($success) {
            match ($image_type) {
                IMAGETYPE_JPEG => imagejpeg($dest_image, $destination, 90),
                IMAGETYPE_PNG => imagepng($dest_image, $destination, 9),
                IMAGETYPE_WEBP => imagewebp($dest_image, $destination, 90),
                default => false
            };
        }

        imagedestroy($source_image);
        imagedestroy($dest_image);

        return $success;
    }

    /**
     * Get image details from various sources
     */
    private static function get_image_details($src) {
        if ($src instanceof \Timber\Image) {
            return ['path' => $src->file_loc, 'url' => $src->src];
        }

        if (is_string($src)) {
            $url = $src;

            // Check theme files
            $theme_dir = get_template_directory();
            $theme_url = get_template_directory_uri();

            if (str_starts_with($url, $theme_url)) {
                $path = str_replace($theme_url, $theme_dir, $url);
                return ['path' => $path, 'url' => $url];
            }

            // Check uploads directory
            $upload_dir = wp_upload_dir();
            if (str_starts_with($url, $upload_dir['baseurl'])) {
                $path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
                return ['path' => $path, 'url' => $url];
            }

            return ['path' => null, 'url' => $url];
        }

        return ['path' => null, 'url' => ''];
    }

    /**
     * Detect browser AVIF support
     */
    private static function browser_supports_avif() {
        return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/avif') !== false;
    }

    /**
     * Detect browser WebP support
     */
    private static function browser_supports_webp() {
        return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }

    /**
     * Detect and cache conversion capabilities
     */
    public static function detect_capabilities() {
        if (self::$conversion_method !== null) {
            return self::$conversion_method;
        }

        $cached = get_transient('timber_avif_capability');
        if ($cached !== false) {
            self::$conversion_method = $cached;
            return $cached;
        }

        $method = 'none';

        if (function_exists('imageavif') && self::test_gd_conversion()) {
            $method = 'gd';
        } elseif (extension_loaded('imagick') && self::test_imagick_conversion()) {
            $method = 'imagick';
        } elseif (self::is_exec_available() && self::test_exec_conversion()) {
            $method = 'exec';
        }

        set_transient('timber_avif_capability', $method, WEEK_IN_SECONDS);
        self::$conversion_method = $method;

        self::log("Detected conversion method: {$method}", 'info');
        return $method;
    }

    /**
     * Test GD conversion
     */
    private static function test_gd_conversion() {
        try {
            $test_image = @imagecreatetruecolor(1, 1);
            if (!$test_image) return false;

            ob_start();
            $result = @imageavif($test_image, null, 80);
            ob_end_clean();

            imagedestroy($test_image);
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Test ImageMagick conversion
     */
    private static function test_imagick_conversion() {
        try {
            if (empty(\Imagick::queryFormats('AVIF'))) {
                return false;
            }

            $imagick = new \Imagick();
            $imagick->newImage(1, 1, 'white');
            $imagick->setImageFormat('avif');

            $blob = $imagick->getImageBlob();
            $imagick->clear();

            return !empty($blob);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Test exec conversion
     */
    private static function test_exec_conversion() {
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
     * Check if exec is available
     */
    private static function is_exec_available() {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = explode(',', ini_get('disable_functions'));
        return !in_array('exec', array_map('trim', $disabled));
    }

    /**
     * Estimate memory needed
     */
    private static function estimate_memory_needed($file_path, $image_type) {
        $image_info = @getimagesize($file_path);
        if (!$image_info) {
            return 64 * 1024 * 1024;
        }

        $width = $image_info[0];
        $height = $image_info[1];
        $channels = $image_type === IMAGETYPE_PNG ? 4 : 3;

        return ceil($width * $height * $channels * 1.5);
    }

    /**
     * Ensure sufficient memory limit
     */
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

    /**
     * Parse memory limit string
     */
    private static function parse_memory_limit($limit) {
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        return match($last) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Track conversion statistics
     */
    private static function track_conversion($file_path, $formats) {
        $stats = get_option('timber_avif_stats', [
            'total_conversions' => 0,
            'avif_count' => 0,
            'webp_count' => 0,
            'total_savings_bytes' => 0,
            'last_conversion' => null
        ]);

        $stats['total_conversions']++;
        if (in_array('avif', $formats)) $stats['avif_count']++;
        if (in_array('webp', $formats)) $stats['webp_count']++;
        $stats['last_conversion'] = current_time('mysql');

        // Calculate savings
        $original_size = file_exists($file_path) ? filesize($file_path) : 0;
        foreach ($formats as $format) {
            $converted_path = self::get_converted_path($file_path, $format);
            if (file_exists($converted_path)) {
                $converted_size = filesize($converted_path);
                $stats['total_savings_bytes'] += ($original_size - $converted_size);
            }
        }

        update_option('timber_avif_stats', $stats);
    }

    /**
     * Structured logging
     */
    private static function log($message, $level = 'debug') {
        $settings = self::get_settings();

        if (!$settings['enable_debug_logging'] || !defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $valid_levels = ['debug', 'info', 'warning', 'error'];
        if (!in_array($level, $valid_levels)) {
            $level = 'debug';
        }

        $level_upper = strtoupper($level);
        error_log("[Timber AVIF][{$level_upper}] {$message}");
    }

    /**
     * Clear all caches
     */
    public static function clear_cache() {
        delete_transient('timber_avif_capability');
        wp_cache_flush_group('avif_converter');
        self::$conversion_method = null;
        self::detect_capabilities();

        return "Cache cleared. Detected method: " . self::$conversion_method;
    }

    /**
     * Cleanup invalid files
     */
    public static function cleanup_invalid_files($directory = null) {
        if (!$directory) {
            $upload_dir = wp_upload_dir();
            $directory = $upload_dir['basedir'];
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $ext = $file->getExtension();
            if (in_array($ext, ['avif', 'webp'])) {
                if (!self::is_valid_image($file->getPathname(), $ext)) {
                    @unlink($file->getPathname());
                    $count++;
                    self::log("Removed corrupted {$ext}: " . $file->getPathname(), 'warning');
                }
            }
        }

        return "Cleaned up {$count} invalid files.";
    }
}
