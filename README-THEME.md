# Timber AVIF – Theme Drop-in

Lightweight Timber integration that converts images to AVIF/WebP, pre-generates variants on upload, and ships a responsive macro.

## Highlights
- AVIF + WebP support with fallbacks (GD > Imagick > CLI)
- `|toavif` filter for one-off conversions (works with remote URLs)
- Helpers: `image.avif`, `image.webp`, `image.best`, `|avif_src`, `|webp_src`
- Upload-time generation + optional breakpoint warming
- Admin page (Settings > Timber AVIF) with Settings, Tools, and Statistics tabs
- AJAX bulk convert with progress bar and cancel support
- Media library "Optimized" column with AVIF/WebP badges
- WP-CLI: `timber-avif bulk`, `timber-avif detect`, `timber-avif clear-cache`
- Responsive macro with mobile-first media queries and 2x capping

## Install
1. Copy `avif.php` into your theme (e.g. `functions/avif.php`).
2. Require it in `functions.php`:
```php
require_once get_template_directory() . '/functions/avif.php';
// init is called inside the file
```
3. Copy `macros.twig` into your Twig templates path.

## Settings (Settings > Timber AVIF)

### Settings tab
- Generate AVIF / WebP on upload (toggles)
- Quality sliders for both formats
- Only keep converted file if smaller than original
- Max dimension / file size safeguards
- Optional breakpoint warming widths (comma-separated)

### Tools tab
- **Bulk Convert** — AJAX-powered, processes all media with progress bar
- **Purge Conversions** — deletes all generated AVIF/WebP files (originals untouched)
- **Clear Caches** — flushes capability detection and re-detects

### Statistics tab
- Total images, AVIF/WebP conversion counts
- Progress bars with percentages
- Space savings breakdown

Settings are stored under `timber_avif_settings` and shared with the plugin.

## Twig Usage
```twig
{{ image|toavif }}                 {# classic filter #}
{{ image|avif_src(1200, 800) }}    {# resize + AVIF #}
{{ image|webp_src(1200) }}         {# resize + WebP #}
{{ image.avif }}                   {# property (uses metadata) #}
{{ image.webp }}
{{ image.best }}                   {# AVIF > WebP > original #}
```

### Responsive macro
```twig
{% import 'macros.twig' as img %}
{{ img.image(post.thumbnail, {
    sizes: {
        'sm': [800, 600],
        'lg': [1600, 1200]
    },
    atf: false
}) }}
```

The macro uses mobile-first `(min-width)` media queries ordered largest to smallest, caps 2x srcset at the original image dimensions, and uses `image.alt` (with `image.title` fallback) for proper accessibility.

`avif_src`/`webp_src` are used internally so already-generated variants are reused before falling back to live conversion.

## WP-CLI
```bash
wp timber-avif detect            # show available methods
wp timber-avif bulk --webp       # convert all attachments
wp timber-avif clear-cache       # reset capability cache
```

## Notes
- Conversions use file locks and are validated before use.
- Upload handler generates AVIF/WebP for the original + registered sizes; optional breakpoint widths can be pre-warmed.
- Shutdown callbacks are used for conversions triggered by Timber resizes to keep page rendering fast.
