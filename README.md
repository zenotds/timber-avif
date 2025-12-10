# Timber AVIF Converter

A single-file, dependency-free helper to add a powerful `|toavif` filter to your Timber 2.x projects in WordPress.

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
const DEFAULT_QUALITY = 75; // Default AVIF quality (1-100)
const ENABLE_DEBUG_LOGGING = true; // Set to false on production to disable logging.
````

  * `DEFAULT_QUALITY`: The compression quality for images converted without a specified quality.
  * `ENABLE_DEBUG_LOGGING`: Set this to `false` on your production site to prevent messages from being written to the PHP error log.

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

## WP-CLI Command

If you use [WP-CLI](https://wp-cli.org/), you can clear all generated AVIF files and transients using the following command. This is useful during development or if you change the default quality and want to regenerate all images.

```bash
wp timber-avif clear-cache
```

-----

## Changelog

### Version 2.0.1 (Current)
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