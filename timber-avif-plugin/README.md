# Timber AVIF Converter - WordPress Plugin

> **⚠️ WORK IN PROGRESS - Known Issues**
>
> **Twig Filter Registration Conflicts:**
> - Cannot register `|toavif`, `|towebp`, `|smart` Twig filters due to initialization timing
> - Theme's custom Twig code initializes extensions before plugin can register filters
> - Error: `LogicException: Unable to add filter as extensions have already been initialized`
>
> **What Works:**
> - ✓ Auto-conversion on upload
> - ✓ Pre-generation of common sizes
> - ✓ Admin UI and statistics
> - ✓ WP-CLI commands
> - ✓ Backend conversion engine
>
> **What Doesn't Work:**
> - ❌ Twig filter registration (if theme has custom Twig filters)
>
> **Use v2.5 `avif.php` theme file for production** until this is resolved.

High-performance AVIF and WebP image conversion plugin for WordPress with Timber integration. Automatically generates next-gen image formats on upload with smart quality optimization, comprehensive admin controls, and seamless Timber integration.

**Version:** 3.0.0 (WIP)
**Requires:** WordPress 5.0+, Timber 2.0+, PHP 8.1+

---

## ✨ Features

### Core Functionality
- **Auto-Generation on Upload** - Automatically creates AVIF and WebP versions when images are uploaded
- **Smart Timber Integration** - Hooks into Timber's resize operations to generate optimized formats
- **Pre-Generation of Common Sizes** - Optionally generate multiple sizes upfront to eliminate first-load delays
- **Browser Capability Detection** - Serves best format based on browser support
- **Lazy Fallback** - Generates formats on-the-fly if pre-generated versions don't exist

### Quality & Optimization
- **Smart Quality Selection** - Dimension-based quality adjustment (lower quality for larger images)
- **File Size Comparison** - Only keeps converted files if smaller than original
- **Multiple Conversion Methods** - GD, ImageMagick, or CLI (automatic detection)
- **Memory Management** - Dynamic memory limit adjustment and dimension limits

### Performance & Reliability
- **Non-Blocking Conversions** - No page load delays, returns original if conversion in progress
- **Stale Lock Cleanup** - Automatically removes stuck conversion locks
- **Comprehensive Caching** - Capability detection, attachment metadata, object cache
- **Statistics Tracking** - Monitor conversions, file counts, and storage savings

### Admin Experience
- **Visual Settings Page** - Easy-to-use interface with tabs for General, Quality, Statistics, and Tools
- **Statistics Dashboard** - Track total conversions, file counts, and storage savings
- **Bulk Conversion Tool** - Convert all existing images with progress tracking
- **WP-CLI Support** - Full command-line interface for batch operations

---

## 📋 Requirements

**Server Requirements:**
- PHP 8.1 or higher
- WordPress 5.0+
- Timber 2.0+

**Conversion Support** (at least one required):
- **GD Extension** (PHP 8.1+) with AVIF support
- **ImageMagick PHP Extension** with AVIF support
- **ImageMagick/GraphicsMagick CLI** accessible via `exec()`

---

## 🚀 Installation

### As a Plugin

1. Download or clone this repository
2. Copy the `timber-avif-plugin` folder to `/wp-content/plugins/`
3. Rename to `timber-avif` (optional, but cleaner)
4. Activate the plugin through the 'Plugins' menu in WordPress
5. Go to **Settings → Timber AVIF** to configure

### Migration from v2.5 Theme File

If you're using the theme-based v2.5 version:

1. Install the plugin as above
2. The plugin will automatically take priority
3. Optionally remove the theme file once you've verified everything works
4. Your Twig templates need **zero changes** - same filters work!

---

## ⚙️ Configuration

Navigate to **Settings → Timber AVIF** in WordPress admin.

### General Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **Auto-Convert on Upload** | ✓ Enabled | Automatically generate AVIF/WebP when uploading images |
| **Enable WebP Generation** | ✓ Enabled | Create WebP versions alongside AVIF for better fallback support |
| **Only Use if Smaller** | ✓ Enabled | Only keep converted files if they're smaller than the original |
| **Pre-generate Common Sizes** | ✗ Disabled | Generate AVIF/WebP for common sizes immediately on upload |
| **Common Sizes** | 800,1200,1600,2400 | Comma-separated widths to pre-generate (if enabled) |
| **Maximum Image Dimension** | 4096px | Skip images larger than this to prevent memory issues |
| **Maximum File Size** | 50MB | Skip files larger than this size |
| **Stale Lock Timeout** | 300s | Remove conversion locks older than this |
| **Debug Logging** | ✗ Disabled | Enable detailed logging (requires WP_DEBUG) |

### Quality Settings

| Setting | Default | Description |
|---------|---------|-------------|
| **AVIF Quality** | 80 | Default quality for AVIF conversion (1-100) |
| **WebP Quality** | 85 | Default quality for WebP conversion (1-100) |
| **Smart Quality** | ✗ Disabled | Enable dimension-based quality adjustment |

**Smart Quality Rules** (when enabled):
- Images up to 1000px: AVIF 85, WebP 90
- Images 1001-2000px: AVIF 80, WebP 85
- Images 2001px+: AVIF 75, WebP 80

---

## 🎨 Usage in Timber/Twig

### Basic Usage (Same as v2.5!)

```twig
{# Convert to AVIF #}
<img src="{{ post.thumbnail|toavif }}" alt="{{ post.title }}">

{# Convert to WebP #}
<img src="{{ post.thumbnail|towebp }}" alt="{{ post.title }}">

{# Custom quality #}
<img src="{{ post.thumbnail|toavif(75) }}" alt="{{ post.title }}">
```

### Picture Element with Fallbacks

```twig
<picture>
    {# AVIF first (best compression) #}
    <source srcset="{{ post.thumbnail|toavif }}" type="image/avif">

    {# WebP fallback #}
    <source srcset="{{ post.thumbnail|towebp }}" type="image/webp">

    {# Original fallback #}
    <img src="{{ post.thumbnail.src }}" alt="{{ post.title }}">
</picture>
```

### NEW: Smart Filter (v3.0)

The `|smart` filter automatically returns the best format based on browser support:

```twig
{# Automatically serves AVIF → WebP → Original based on browser #}
<img src="{{ post.thumbnail|smart }}" alt="{{ post.title }}">
```

### With Timber Resize

```twig
{# Resize to 800px width, then convert to AVIF #}
<img src="{{ post.thumbnail|resize(800)|toavif }}" alt="{{ post.title }}">

{# Responsive images with multiple formats #}
{% set src1x = post.thumbnail|resize(800) %}
{% set src2x = post.thumbnail|resize(1600) %}

<picture>
    <source srcset="{{ src1x|toavif }} 1x, {{ src2x|toavif }} 2x" type="image/avif">
    <source srcset="{{ src1x|towebp }} 1x, {{ src2x|towebp }} 2x" type="image/webp">
    <img src="{{ src1x }}" srcset="{{ src1x }} 1x, {{ src2x }} 2x" alt="{{ post.title }}">
</picture>
```

### Performance Tip

With **Pre-generate Common Sizes** enabled and your macro's sizes matching the common sizes setting, all conversions happen at upload time, resulting in **instant page loads** with zero conversion overhead!

---

## 🖥️ WP-CLI Commands

### Bulk Convert All Images

```bash
wp timber-avif bulk
```

**Options:**
- `--quality=N` - Override default quality (1-100)
- `--force` - Force regeneration of existing files
- `--limit=N` - Limit number of images to convert

**Examples:**
```bash
# Convert all images
wp timber-avif bulk

# Convert with custom quality
wp timber-avif bulk --quality=75

# Force regenerate first 100 images
wp timber-avif bulk --force --limit=100
```

### View Statistics

```bash
wp timber-avif stats
```

Shows:
- Total conversions
- AVIF file count
- WebP file count
- Total storage savings
- Last conversion time

### Detect Conversion Method

```bash
wp timber-avif detect
```

Shows which conversion method is available (GD, ImageMagick, or exec).

### Cleanup Invalid Files

```bash
wp timber-avif cleanup
```

Removes corrupted or invalid AVIF/WebP files.

### Clear Cache

```bash
wp timber-avif clear-cache
```

Clears capability detection cache and forces re-detection.

---

## 📊 Statistics Dashboard

The admin page includes a visual statistics dashboard showing:

- **Total Conversions** - Number of images processed
- **AVIF Files** - Total AVIF files generated
- **WebP Files** - Total WebP files generated
- **Total Savings** - Storage space saved vs original images

---

## 🔄 How It Works

### On Upload

1. Image uploaded to WordPress media library
2. Plugin hooks into `wp_generate_attachment_metadata`
3. Generates AVIF version of full-size image
4. Generates WebP version (if enabled)
5. Optionally pre-generates common sizes
6. Stores metadata about available formats

### On Page Load (First Time)

```twig
{{ image|resize(800)|toavif }}
```

1. Timber resizes original to 800px → creates `image-800x600.jpg`
2. Plugin hook detects resize operation
3. Also generates `image-800x600.avif` and `image-800x600.webp`
4. `|toavif` filter serves pre-existing AVIF file
5. Total time: ~1 resize operation

### On Page Load (Subsequent)

```twig
{{ image|resize(800)|toavif }}
```

1. Timber serves cached `image-800x600.jpg`
2. `|toavif` serves cached `image-800x600.avif`
3. Total time: **0ms** (both files exist)

---

## 🆚 Version Comparison

| Feature | v2.5 (Theme File) | v3.0 (Plugin) |
|---------|-------------------|---------------|
| AVIF Conversion | ✓ | ✓ |
| WebP Conversion | ✗ | ✓ |
| Auto-Generation on Upload | Optional | ✓ Default |
| Pre-generate Common Sizes | ✗ | ✓ |
| Timber Resize Hook | ✗ | ✓ |
| Smart Quality | ✗ | ✓ |
| Browser Detection | ✗ | ✓ |
| Admin Settings Page | ✗ | ✓ |
| Statistics Dashboard | ✗ | ✓ |
| Bulk Conversion UI | ✗ | ✓ |
| `|smart` Filter | ✗ | ✓ |
| Plugin Architecture | ✗ | ✓ |
| Version Management | ✗ | ✓ |

---

## 🛠️ Troubleshooting

### No AVIF Support Detected

**Solution:** Install one of the following:
- PHP 8.1+ with GD extension compiled with AVIF support
- ImageMagick PHP extension with AVIF delegate
- ImageMagick or GraphicsMagick CLI tools

Check with: `wp timber-avif detect`

### Conversions Not Happening

1. Check **Settings → Timber AVIF → General**
2. Ensure "Auto-Convert on Upload" is enabled
3. Check server capabilities: `wp timber-avif detect`
4. Enable debug logging and check error logs

### Files Not Smaller

This is normal! Not all images compress better with AVIF/WebP.

With "Only Use if Smaller" enabled (default), larger converted files are automatically deleted.

### Bulk Convert Not Working

1. Check PHP max_execution_time
2. Try with `--limit=10` to test
3. Use WP-CLI instead: `wp timber-avif bulk`

---

## 📝 Changelog

### Version 3.0.0 (Current)
**Major Release - Plugin Architecture**

#### New Features
- Complete WordPress plugin architecture
- WebP generation alongside AVIF
- Admin settings page with visual interface
- Statistics dashboard with conversion metrics
- Smart quality selection (dimension-based)
- Pre-generation of common sizes
- Timber resize hook integration
- `|smart` filter for automatic format selection
- Browser capability detection
- Bulk conversion with progress tracking
- Enhanced WP-CLI commands with statistics

#### Performance Improvements
- Auto-generation on upload eliminates first-load delays
- Timber resize hook generates formats proactively
- Non-blocking conversion returns original immediately
- Comprehensive caching at multiple levels

#### Migration from v2.5
- All v2.5 features retained and enhanced
- Backward compatible - same filters work
- Enhanced with WebP support and admin UI
- Plugin architecture for better version management

---

## 📄 License

This project is licensed under the MIT License.

---

## 🙏 Credits

Created by Francesco Zeno Selva

Built for WordPress + Timber with ❤️
