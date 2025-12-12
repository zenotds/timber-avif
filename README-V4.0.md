# Timber AVIF v4.0 – Plugin

Slim plugin that bundles the v3 converter and macro so you can drop AVIF/WebP support into any Timber project without touching the theme.

## What it Does
- Uses the same `AVIFConverter` as the theme version (AVIF + WebP, upload-time generation, admin tools)
- Autoloads Twig helpers: `|toavif`, `|avif_src`, `|webp_src`, `image.avif`, `image.webp`
- Registers the macro path (`timber-avif/macros/picture.twig`)
- Adds the Tools → Timber AVIF screen + WP-CLI commands

## Install
1. Copy `/timber-avif/` into `/wp-content/plugins/`.
2. Activate **Timber AVIF Converter** in WP admin.
3. Optional: import the macro from `timber-avif/macros/picture.twig`.

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

Admin lives under Settings → Timber AVIF (Settings/Tools tabs). CLI lives under `wp timber-avif`.

## Settings
Same options as v3 (shared via the `timber_avif_settings` option):
- Auto-convert uploads; optional WebP
- Quality sliders
- Only if smaller; max dimension/file size
- Breakpoint warming widths
- Bulk convert + cache clear
