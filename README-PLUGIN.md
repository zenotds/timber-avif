# Timber AVIF – Plugin

Slim plugin that bundles the converter and macro so you can drop AVIF/WebP support into any Timber project without touching the theme.

## What it Does
- Uses the same `AVIFConverter` class as the theme version
- Autoloads Twig helpers: `|toavif`, `|avif_src`, `|webp_src`, `image.avif`, `image.webp`
- Registers the macro path (`timber-avif/macros/image.twig`)
- Adds Settings > Timber AVIF with Settings, Tools, and Statistics tabs
- WP-CLI commands under `timber-avif`

## Install
1. Copy `/timber-avif/` into `/wp-content/plugins/`.
2. Activate **Timber AVIF Converter** in WP admin.
3. Optional: import the macro from `timber-avif/macros/image.twig`.

The plugin loads `includes/avif-converter.php`. If your theme already requires `avif.php`, the plugin will detect the class and reuse it.

## Usage
All Twig and PHP APIs are identical to the theme version:
```twig
{{ image|toavif }}
{{ image|avif_src(1200) }}
{{ image.webp }}
{% import 'picture.twig' as img %}
{{ img.image(hero, { atf: true }) }}
```

Admin lives under Settings > Timber AVIF. CLI lives under `wp timber-avif`.

## Settings
Same options as the theme drop-in (shared via the `timber_avif_settings` option):
- Auto-convert uploads (AVIF + WebP toggles)
- Quality sliders
- Only if smaller; max dimension/file size
- Breakpoint warming widths
- Bulk convert with AJAX progress bar
- Purge conversions, clear caches
- Statistics with progress bars and space savings
