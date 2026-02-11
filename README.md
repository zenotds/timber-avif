# Timber AVIF

Modern AVIF/WebP support for Timber projects. Choose the drop-in file or the lightweight plugin; both share the same converter (v4.0), helpers, and macro.

- **Theme drop-in:** copy `avif.php` + `macros.twig` into your theme. [Docs](README-THEME.md)
- **Plugin:** activate `/timber-avif/` to load the same features without touching the theme. [Docs](README-PLUGIN.md)

## Features
- AVIF + WebP generation with GD/Imagick/CLI fallbacks
- Upload-time conversion (original + registered sizes) with optional breakpoint warming
- Twig helpers: `|toavif`, `|avif_src`, `|webp_src`, `image.avif`, `image.webp`
- Responsive `image` macro with Tailwind-style breakpoints, mobile-first media queries, and 2x capping
- Admin page (Settings > Timber AVIF) with three tabs: Settings, Tools, Statistics
- AJAX-powered bulk conversion with progress bar
- Media library "Optimized" column showing AVIF/WebP badges
- WP-CLI: `timber-avif detect`, `timber-avif bulk`, `timber-avif clear-cache`

## Quick Start (theme version)
```php
// functions.php
require_once get_template_directory() . '/inc/avif.php';
```
```twig
{# Twig #}
{{ image|avif_src(1200) }}
{% import 'macros.twig' as img %}
{{ img.image(post.thumbnail, { sizes: { 'lg': [1600, 900] } }) }}
```

Open **Settings > Timber AVIF** to configure.

## Requirements
- WordPress 5.0+
- PHP 8.1+
- Timber 2.x
- GD with AVIF/WebP, Imagick, or ImageMagick CLI

## Migration
Coming from an earlier version? See [MIGRATION.md](MIGRATION.md).

## Changelog

### v4.0.0
- Unified versioning across theme drop-in and plugin (both are now v4.0)
- **Admin:** Statistics tab with conversion progress bars and space savings
- **Admin:** AJAX-powered bulk convert with cancel support and progress bar
- **Admin:** Media library "Optimized" column with AVIF/WebP badges
- **Admin:** Redesigned UI with status bar, tool cards, and stat cards
- **Macro:** Fixed media query overlap — mobile-first `(min-width)` only, ordered largest to smallest
- **Macro:** 2x srcset capped at original image dimensions (avoids useless upscaling)
- **Macro:** Uses `image.alt` with `image.title` fallback (proper accessibility)
- **Macro:** Simplified `normalizedSizes` cascade (loop instead of nested ternary)
- **Macro:** Removed unused `ximage` macro

### v3.0.0
- Initial release with AVIF + WebP conversion, Twig helpers, admin tools, WP-CLI
- Upload-time generation with optional breakpoint warming
- Responsive macro with pre-generated variant reuse
