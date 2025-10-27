<?php
/**
 * Timber AVIF Converter
 *
 * Provides a '|toavif' filter for Timber to handle on-the-fly AVIF conversion.
 * It intelligently uses the best available server method (GD, ImageMagick, or exec)
 * and includes caching, robust error handling, and fallback mechanisms.
 *
 * @version 1.1 - Fixed compatibility with Timber 2.x and direct string URLs
 * @author Francesco Zeno Selva
 *
 * Installation:
 * 1. `require_once get_template_directory() . '/path/to/avif.php';` in your functions.php
 * 2. Use in Twig: `{{ image|toavif }}` or `{{ image.src|toavif(65) }}`
 */

use Timber\Image;

class AVIFConverter {

    // -- Configuration Constants --
    const DEFAULT_QUALITY = 80; // Default AVIF quality (1-100)
    const ENABLE_DEBUG_LOGGING = false; // Set to false in production to disable logging.

    // -- Internal Constants --
    private const CACHE_PREFIX = 'timber_avif_';
    private const CACHE_DURATION = MONTH_IN_SECONDS;
    private const ERROR_LOG_KEY = 'timber_avif_errors';
    private const MAX_ERRORS = 50;

    /**
     * Hooks into WordPress to register the Twig filter.
     */
    public static function init() {
        add_filter('timber/twig', [__CLASS__, 'add_twig_filters']);
        add_action('admin_notices', [__CLASS__, 'check_avif_support_admin_notice']);
    }

    /**
     * Registers the custom 'toavif' filter with Timber's Twig environment.
     */
    public static function add_twig_filters($twig) {
        $twig->addFilter(new \Twig\TwigFilter('toavif', [__CLASS__, 'convert_to_avif']));
        return $twig;
    }

    /**
     * The main conversion logic called by the Twig filter.
     *
     * @param mixed $src A Timber\Image object or an image URL string.
     * @param int $quality Overrides the default conversion quality.
     * @param bool $force Forces regeneration, ignoring existing files.
     * @return string The URL to the AVIF image or the original URL on failure.
     */
    public static function convert_to_avif($src, $quality = self::DEFAULT_QUALITY, $force = false) {
        if (empty($src)) {
            return '';
        }

        $details = self::get_image_details($src);
        $file_path = $details['path'];
        $original_url = $details['url'];

        if (!$file_path || !file_exists($file_path)) {
            self::log("Source file not found for: '{$original_url}'");
            return $original_url;
        }

        $avif_path = self::get_avif_path($file_path, $quality);
        $avif_url = str_replace(wp_basename($file_path), wp_basename($avif_path), $original_url);

        if (!$force && file_exists($avif_path) && filesize($avif_path) > 0) {
            return $avif_url;
        }

        if (!is_writable(dirname($avif_path))) {
            self::log("Upload directory is not writable: " . dirname($avif_path));
            return $original_url;
        }

        $success = self::perform_conversion($file_path, $avif_path, $quality);

        return $success ? $avif_url : $original_url;
    }
    
    /**
     * Resolves a Timber Image object or URL string into its file path and URL.
     *
     * @param mixed $src The source image.
     * @return array Containing 'path' and 'url'.
     */
    private static function get_image_details($src) {
        // Handle Timber\Image objects
        if ($src instanceof \Timber\Image) {
            return ['path' => $src->file_loc, 'url' => $src->src];
        }

        // Handle string URLs
        if (is_string($src)) {
            $url = $src;
            
            // Check if it's a theme file (not in uploads directory)
            $theme_dir = get_template_directory();
            $theme_url = get_template_directory_uri();
            
            if (str_starts_with($url, $theme_url)) {
                $path = str_replace($theme_url, $theme_dir, $url);
                return ['path' => $path, 'url' => $url];
            }
            
            // Check if it's in the uploads directory
            $upload_dir = wp_upload_dir();
            if (str_starts_with($url, $upload_dir['baseurl'])) {
                $path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
                return ['path' => $path, 'url' => $url];
            }
            
            // Try to handle attachment URLs using Timber's factory method
            try {
                // For Timber 2.x, use the factory method instead of constructor
                if (method_exists('\Timber\Timber', 'get_image')) {
                    $image = \Timber\Timber::get_image($url);
                } elseif (method_exists('\Timber\ImageHelper', 'get_image')) {
                    $image = \Timber\ImageHelper::get_image($url);
                } else {
                    // Fallback: try to extract attachment ID from URL
                    $attachment_id = self::get_attachment_id_from_url($url);
                    if ($attachment_id) {
                        $image = \Timber\Timber::get_post($attachment_id);
                    } else {
                        $image = null;
                    }
                }
                
                if ($image && isset($image->file_loc)) {
                    return ['path' => $image->file_loc, 'url' => $image->src];
                }
            } catch (\Exception $e) {
                self::log("Error creating Timber image from URL: " . $e->getMessage());
            }
            
            // Final fallback: return URL as-is with no conversion
            return ['path' => null, 'url' => $url];
        }

        return ['path' => null, 'url' => ''];
    }

    /**
     * Helper method to get attachment ID from URL.
     *
     * @param string $url The image URL.
     * @return int|null The attachment ID or null if not found.
     */
    private static function get_attachment_id_from_url($url) {
        global $wpdb;
        $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE guid='%s';", $url));
        return !empty($attachment) ? $attachment[0] : null;
    }

    /**
     * Cycles through available conversion methods until one succeeds.
     *
     * @return bool True on success, false on failure.
     */
    private static function perform_conversion($source, $destination, $quality) {
        if (function_exists('imageavif') && self::convert_with_gd($source, $destination, $quality)) {
            return true;
        }

        if (extension_loaded('imagick') && self::convert_with_imagick($source, $destination, $quality)) {
            return true;
        }

        if (self::is_exec_available() && self::convert_with_exec($source, $destination, $quality)) {
            return true;
        }

        self::log("All AVIF conversion methods failed for: {$source}");
        return false;
    }

    private static function convert_with_gd($source, $destination, $quality) {
        try {
            $image_type = @exif_imagetype($source);
            $image = match ($image_type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
                IMAGETYPE_PNG => @imagecreatefrompng($source),
                IMAGETYPE_WEBP => @imagecreatefromwebp($source),
                IMAGETYPE_GIF => @imagecreatefromgif($source),
                default => null,
            };

            if (!$image) {
                self::log("GD failed to create image resource from '{$source}'.");
                return false;
            }

            if ($image_type === IMAGETYPE_PNG) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
            }

            $success = @imageavif($image, $destination, $quality);
            imagedestroy($image);
            return $success;
        } catch (\Exception $e) {
            self::log("GD Exception: " . $e->getMessage());
            return false;
        }
    }

    private static function convert_with_imagick($source, $destination, $quality) {
        try {
            if (empty(\Imagick::queryFormats('AVIF'))) {
                return false;
            }
            $imagick = new \Imagick($source);
            $imagick->setImageFormat('avif');
            $imagick->setImageCompressionQuality($quality);
            $imagick->stripImage();
            $success = $imagick->writeImage($destination);
            $imagick->clear();
            return $success;
        } catch (\Exception $e) {
            self::log("ImageMagick Exception: " . $e->getMessage());
            return false;
        }
    }

    private static function convert_with_exec($source, $destination, $quality) {
        $source_arg = escapeshellarg($source);
        $dest_arg = escapeshellarg($destination);
        $quality_arg = intval($quality);
        $command = "magick {$source_arg} -quality {$quality_arg} {$dest_arg}";

        @exec($command . ' 2>&1', $output, $return_var);

        if ($return_var === 0 && file_exists($destination)) {
            return true;
        } else {
            $command = "convert {$source_arg} -quality {$quality_arg} {$dest_arg}";
            @exec($command . ' 2>&1', $output, $return_var);
            if ($return_var === 0 && file_exists($destination)) {
                return true;
            }
        }
        
        self::log("Exec command failed. Output: " . implode(' ', $output));
        return false;
    }
    
    /**
     * Derives the target '.avif' path from the source path.
     */
    private static function get_avif_path($file_path, $quality) {
        $filename = pathinfo($file_path, PATHINFO_FILENAME);
        $dirname = pathinfo($file_path, PATHINFO_DIRNAME);
        
        // Only add the quality suffix if it differs from the default.
        $suffix = ($quality == self::DEFAULT_QUALITY) ? '' : "-q{$quality}";

        return "{$dirname}/{$filename}{$suffix}.avif";
    }

    /**
     * Checks if the exec() function is available for use.
     */
    private static function is_exec_available() {
        return function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')));
    }

    /**
     * Logs a message to the PHP error log if debugging is enabled.
     */
    private static function log($message) {
        if (self::ENABLE_DEBUG_LOGGING && WP_DEBUG) {
            error_log('[Timber AVIF] ' . $message);
        }
    }

    /**
     * Displays a notice in the WP admin if no AVIF support is detected.
     */
    public static function check_avif_support_admin_notice() {
        if (function_exists('imageavif')) return;
        if (extension_loaded('imagick') && !empty(\Imagick::queryFormats('AVIF'))) return;

        $notice = '<div class="notice notice-warning"><p><strong>Timber AVIF Converter:</strong> No server support for AVIF conversion was detected. The filter will gracefully fall back to original image formats. Please install the GD extension (PHP 8.1+) or ImageMagick with AVIF support.</p></div>';
        echo $notice;
    }

    /**
     * Provides a method to clear all AVIF conversion transients from the database.
     */
    public static function clear_cache() {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_' . self::CACHE_PREFIX . '%'));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_' . self::CACHE_PREFIX . '%'));
    }
}

// Initialize the converter
AVIFConverter::init();

// Optional: Add WP-CLI command for cache management.
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('timber-avif clear-cache', ['AVIFConverter', 'clear_cache']);
}