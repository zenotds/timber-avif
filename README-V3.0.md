# Timber AVIF v3.0 – Theme Drop-in

Lightweight Timber integration that converts images to AVIF/WebP, pre-generates variants on upload, and ships a responsive macro.

## Highlights
- AVIF + WebP support with fallbacks (GD → Imagick → exec)
- `|toavif` filter for one-off conversions (works with remote URLs)
- New helpers: `image.avif`, `image.webp`, `|avif_src`, `|webp_src`
- Optional upload-time generation + breakpoint warming
- Simple admin screen (Tools → Timber AVIF) for settings, bulk convert, cache clear
- WP-CLI: `timber-avif bulk`, `timber-avif detect`, `timber-avif clear-cache`
- Updated macro that prefers pre-generated variants to avoid per-request thrash

## Install
1) Copy `avif.php` into your theme (e.g. `inc/avif.php`).
2) Require it in `functions.php`:
```php
require_once get_template_directory() . '/inc/avif.php';
// init is called inside the file
```
3) Copy `macros.twig` into your Twig path (or import from this repo).

## Settings (Tools → Timber AVIF)
- Auto-convert uploads (AVIF + optional WebP)
- Quality sliders for both formats
- Only keep converted file if smaller
- Max dimension / file size safeguards
- Optional breakpoint warming widths (comma-separated)
- Bulk convert existing media + clear detection caches

Settings are stored under `timber_avif_settings` and shared with the plugin.

## Twig Usage
```twig
{{ image|toavif }}                 {# classic filter #}
{{ image|avif_src(1200, 800) }}    {# resize + AVIF #}
{{ image|webp_src(1200) }}         {# resize + WebP #}
{{ image.avif }}                   {# property (uses metadata) #}
{{ image.webp }}
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
`avif_src`/`webp_src` are used internally so already-generated variants are reused before falling back to live conversion.

## WP-CLI
```bash
wp timber-avif detect            # show available methods
wp timber-avif bulk --webp       # convert all attachments to AVIF (+ WebP)
wp timber-avif clear-cache       # reset capability cache
```

## Notes
- Conversions run with file locks and are validated before use.
- Upload handler generates AVIF/WebP for the original + registered sizes; optional breakpoint widths can be pre-warmed.
- Shutdown callbacks are used for background conversions triggered by resizes to keep page loads snappy.
