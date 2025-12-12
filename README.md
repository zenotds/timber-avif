# Timber AVIF

Modern AVIF/WebP support for Timber projects. Choose the drop-in file (v3.0) or the lightweight plugin (v4.0); both share the same converter, helpers, and macro.

- **v3.0 – Theme file:** copy `avif.php` + `macros.twig` into your theme. [Docs → README-V3.0.md](README-V3.0.md)
- **v4.0 – Plugin:** activate `/timber-avif/` to load the same features without touching the theme. [Docs → README-V4.0.md](README-V4.0.md)

## Features
- AVIF + WebP generation with GD/Imagick/exec fallbacks
- Upload-time conversion (original + registered sizes) with optional breakpoint warming
- Twig helpers: `|toavif`, `|avif_src`, `|webp_src`, `image.avif`, `image.webp`
- Responsive macro that reuses pre-generated variants and supports Tailwind-style breakpoints
- Tools page for quality/options, bulk conversion, cache clearing
- WP-CLI helpers: `timber-avif detect`, `timber-avif bulk`, `timber-avif clear-cache`

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

Open **Settings → Timber AVIF** to configure (Settings/Tools tabs).

## Requirements
- WordPress 5.0+
- PHP 8.1+
- Timber 2.x
- GD with AVIF/WebP, Imagick, or ImageMagick CLI

## Migration
Coming from v2.5 or an earlier plugin attempt? See [MIGRATION.md](MIGRATION.md) for a short upgrade path.
