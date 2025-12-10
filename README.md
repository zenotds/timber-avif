# WARNING: plugin is broken at |filter level. need more work.

# Timber AVIF Converter

A powerful AVIF and WebP image conversion solution for WordPress + Timber projects.

> **🎉 NEW: Version 3.0 WordPress Plugin Available!**
> Check out the [v3-plugin branch](../../tree/v3-plugin) for a complete WordPress plugin with admin UI, WebP support, statistics dashboard, and pre-generation features. **Solves the 20+ image timeout problem!**
> [View v3.0 Documentation](timber-avif-plugin/README.md) | [See Changelog](#version-300---wordpress-plugin-new)

---

## Current Version: 2.5 (Theme File)

This is a single-file, dependency-free helper to add a powerful `|toavif` filter to your Timber 2.x projects in WordPress.

This script enables on-the-fly AVIF image conversion with an intelligent fallback system, mimicking Timber's built-in `|towebp` functionality but for the modern AVIF format.

---

## Features

* **On-the-Fly Conversion**: Automatically converts JPG, PNG, and WebP images to AVIF when the filter is used.
* **Intelligent Fallback System**: Automatically detects and uses the best available conversion method on your server in this order:
    1.  **GD Extension** (PHP 8.1+)
    2.  **ImageMagick Extension**
    3.  **ImageMagick/GraphicsMagick CLI** (`exec`)
* **Parameter-Aware Filenames**: Calling the filter with different quality settings (e.g., `|toavif(60)` vs. `|toavif(80)`) generates separate, non-conflicting files.
* **Clean Default Filenames**: The quality suffix (`-qXX`) is only added to the filename if it differs from the default, keeping filenames clean for the most common use case.
* **Flexible Input**: Works seamlessly with both Timber `Image` objects (`{{ post.thumbnail|toavif }}`) and plain URL strings (`{{ post.thumbnail.src|toavif }}`).
* **Easy Configuration**: Set default quality and enable/disable debug logging with simple constants at the top of the file.
* **Server Support Check**: Displays a helpful notice in the WordPress admin if no AVIF support is detected on the server.
* **WP-CLI Support**: Includes an optional command (`wp timber-avif clear-cache`) to purge all generated AVIF images.

---

## Requirements

* WordPress 5.0+
* Timber 2.0+
* **One of the following on your server:**
    * **PHP 8.1+** with the **GD extension** enabled.
    * The **ImageMagick PHP extension** compiled with AVIF support.
    * The **ImageMagick or GraphicsMagick CLI tools** installed and accessible via `exec()`.

---

## Installation

1.  Download the `avif.php` file from this repository.
2.  Place the file in your WordPress theme's directory. A good location is a subfolder like `/inc/`.
3.  Open your theme's `functions.php` file and add the following line to include the converter:

    ```php
    // functions.php
    require_once get_template_directory() . '/inc/avif.php';
    ```

That's it! The `|toavif` filter is now available in all your Twig templates.

---

## Configuration

You can configure the default behavior by editing the constants at the top of the `avif.php` file.

```php
// /inc/avif.php

// -- Configuration Constants --
const DEFAULT_QUALITY = 80; // Default AVIF quality (1-100)
const ENABLE_DEBUG_LOGGING = false; // Set to true for development debugging
const MAX_IMAGE_DIMENSION = 4096; // Prevent memory exhaustion (pixels)
const MAX_FILE_SIZE_MB = 50; // Max file size to attempt conversion
const ONLY_IF_SMALLER = true; // Only use AVIF if smaller than original
const ENABLE_AUTO_CONVERT_ON_UPLOAD = false; // Auto-convert images on upload
const STALE_LOCK_TIMEOUT = 300; // Remove locks older than N seconds
````

### Configuration Options

  * `DEFAULT_QUALITY`: The compression quality for images converted without a specified quality (1-100)
  * `ENABLE_DEBUG_LOGGING`: Set to `true` for development to enable detailed logging with severity levels
  * `MAX_IMAGE_DIMENSION`: Maximum image dimension in pixels to prevent memory exhaustion
  * `MAX_FILE_SIZE_MB`: Maximum file size in MB to attempt conversion
  * `ONLY_IF_SMALLER`: Only use AVIF if the converted file is smaller than the original
  * `ENABLE_AUTO_CONVERT_ON_UPLOAD`: Automatically convert images to AVIF when uploaded to media library
  * `STALE_LOCK_TIMEOUT`: Seconds after which stale lock files are automatically removed

-----

## Usage

The filter can be used in your `.twig` files just like any other Timber filter.

### Basic Usage

This will convert the image using the `DEFAULT_QUALITY` setting and generate a file like `my-image.avif`.

```twig
<img src="{{ post.thumbnail|toavif }}" alt="{{ post.thumbnail.alt }}">
```

### Custom Quality

You can pass a specific quality level as an argument. This will generate a unique file, such as `my-image-q60.avif`.

```twig
<img src="{{ post.thumbnail|toavif(60) }}" alt="{{ post.thumbnail.alt }}">
```

### Best Practice: The `<picture>` Element

For robust, production-ready code, you should provide fallbacks for browsers that don't support AVIF. The `<picture>` element is the correct way to do this, creating a fallback chain of **AVIF → WebP → Original Format**.

```twig
<picture>
    {# Offer the AVIF version first #}
    <source type="image/avif" srcset="{{ post.thumbnail|toavif|resize(800, 600) }}">

    {# Offer the WebP version as the next fallback #}
    <source type="image/webp" srcset="{{ post.thumbnail|towebp|resize(800, 600) }}">

    {# The original image is the final fallback for older browsers #}
    <img src="{{ post.thumbnail|resize(800, 600) }}" alt="{{ post.thumbnail.alt }}">
</picture>
```

-----

## WP-CLI Commands

If you use [WP-CLI](https://wp-cli.org/), several commands are available for managing AVIF conversions:

### Clear Cache
Clear all generated AVIF files and transients:
```bash
wp timber-avif clear-cache
```

### Bulk Conversion
Convert all existing images in the media library to AVIF:
```bash
wp timber-avif bulk
```

**Options:**
- `--quality=N` - Override default quality (1-100)
- `--force` - Force regeneration of existing AVIF files
- `--limit=N` - Limit number of images to convert

**Examples:**
```bash
# Convert all images with quality 70
wp timber-avif bulk --quality=70

# Force regenerate first 100 images
wp timber-avif bulk --force --limit=100
```

### Detect Capabilities
Check which conversion method is available on your server:
```bash
wp timber-avif detect
```

### Cleanup Corrupted Files
Remove invalid or corrupted AVIF files:
```bash
wp timber-avif cleanup
```

-----

## Changelog

### Version 3.0.0 - WordPress Plugin (New!)
**⚠️ Available on `v3-plugin` branch - Complete WordPress Plugin Architecture**

v3.0 is a complete rewrite as a WordPress plugin with major new features and performance improvements. The plugin is **backward compatible** with v2.5 - all your existing Twig templates will work unchanged!

#### Plugin Architecture
- ✓ **Full WordPress Plugin** - Proper plugin structure with easy installation/activation
- ✓ **Modular Design** - Separate classes for converter, admin, and core functionality
- ✓ **Version Management** - Easy updates and rollback capability
- ✓ **Safe Migration** - Can run alongside v2.5 theme file during testing

#### Major New Features
- ✓ **WebP Generation** - Automatically generates WebP versions alongside AVIF
- ✓ **Admin Settings Page** - Visual interface with tabs for General, Quality, Statistics, and Tools
- ✓ **Statistics Dashboard** - Track conversions, file counts, and storage savings in real-time
- ✓ **Pre-Generation of Common Sizes** - Generate multiple sizes on upload to eliminate first-load delays
- ✓ **Timber Resize Hook** - Automatically generates AVIF/WebP when Timber creates resized versions
- ✓ **Smart Quality Selection** - Dimension-based quality adjustment (lower quality for larger images)
- ✓ **Browser Capability Detection** - Serves best format based on Accept header
- ✓ **`|smart` Twig Filter** - NEW filter that automatically returns AVIF → WebP → Original based on browser support
- ✓ **Bulk Conversion UI** - Admin interface with AJAX progress tracking
- ✓ **Enhanced WP-CLI** - New `stats` command and improved bulk operations

#### Performance Improvements for Large Pages
- **Solves the 20+ image timeout problem!** Pre-generation eliminates conversion overhead on page load
- **Zero first-load conversion** when pre-generation is enabled
- **Instant subsequent loads** with comprehensive caching
- **Non-blocking architecture** returns originals immediately if conversion in progress

#### How It Works with Your Macro
With v3.0 and pre-generation enabled:
1. **Upload**: Generates AVIF/WebP for original + common sizes (800, 1200, 1600, 2400px)
2. **First Page Load**: All files already exist - serves instantly! ⚡
3. **Your 20+ image pages**: No more timeouts or 500 errors!

#### Installation
```bash
# Get v3.0 plugin
git checkout v3-plugin
cd timber-avif-plugin

# Install as WordPress plugin
cp -r timber-avif-plugin /path/to/wp-content/plugins/timber-avif
```

See the plugin README for complete documentation: `timber-avif-plugin/README.md`

#### Migration from v2.5
- All v2.5 features retained and enhanced
- Same Twig filters work (backward compatible)
- No template changes required
- Plugin takes priority over theme file automatically
- Can keep v2.5 as backup during testing

---

### Version 2.5 (Current - Main Branch)
**Backend Optimization Release**

#### Performance Enhancements
- **Non-Blocking Lock Handling**: Removed `sleep(1)` blocking call - returns original URL immediately when conversion is in progress, eliminating 1-second delays
- **Stale Lock Cleanup**: Automatically removes lock files older than 5 minutes (configurable) to prevent stuck conversions
- **File Size Comparison**: New `ONLY_IF_SMALLER` constant - only uses AVIF if smaller than original, logs size savings percentage
- **Structured Logging**: Added severity levels (debug, info, warning, error) for better log filtering and debugging

#### New Features
- **Auto-Conversion on Upload**: Optional `ENABLE_AUTO_CONVERT_ON_UPLOAD` constant - automatically converts images to AVIF on upload, including all image sizes
- **WP-CLI Bulk Conversion**: New `wp timber-avif bulk` command with progress bar, supports `--quality`, `--force`, and `--limit` flags
- **Enhanced Logging**: All log messages now include severity levels for better monitoring and debugging

#### Configuration Options
- `ONLY_IF_SMALLER` (default: true) - Only use AVIF if smaller than original
- `ENABLE_AUTO_CONVERT_ON_UPLOAD` (default: false) - Auto-convert on upload
- `STALE_LOCK_TIMEOUT` (default: 300) - Remove locks older than N seconds

#### Developer Experience
- Better error messages with context-aware severity levels
- Comprehensive logging of conversion success with size savings metrics
- Improved UX: no more blocking waits for concurrent conversion attempts

---

### Version 2.0.1
**Major Rewrite - Performance & Reliability Edition**

#### New Features
- **Capability Detection System**: Detects and caches the best conversion method (GD, ImageMagick, or exec) with 1-week caching
- **Race Condition Prevention**: File locking mechanism prevents concurrent conversion attempts
- **Memory Management**: Automatic memory estimation and dynamic PHP memory limit adjustment
- **File Validation**: Magic byte checking ensures generated AVIF files are not corrupted
- **Dimension & Size Limits**: Configurable max dimensions (4096px) and file size (50MB) to prevent memory exhaustion
- **Enhanced Error Handling**: Comprehensive try-catch blocks and validation layers throughout
- **WP-CLI Utilities**: Added `cleanup` and `detect` commands alongside existing `clear-cache`

#### Performance Improvements
- Cached conversion method eliminates repeated capability tests
- Optimized attachment ID lookup with object cache (1-hour TTL)
- Write permission checks before processing
- Smart AVIF file reuse with corruption detection

#### Bug Fixes
- Fixed compatibility with Timber 2.x for direct URL strings
- Improved theme file and uploads directory path resolution
- Added proper cleanup of lock files on exceptions
- Enhanced ImageMagick resource limits (256MB memory, 60s timeout)

#### Code Quality
- Modern PHP syntax with match expressions
- Proper constant organization (public config vs private internal)
- Structured validation workflow
- Comprehensive inline documentation

---

### Version 1.1
- Fixed compatibility with Timber 2.x
- Added support for direct string URLs
- Various checks and fixes

### Version 1.0
- Initial release
- Basic AVIF conversion with GD, ImageMagick, and exec fallbacks
- Quality-aware filename generation
- Simple caching mechanism

-----

## License

This project is licensed under the MIT License.