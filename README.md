# Timber AVIF (v6.0)

Responsive images for Timber 2.x. A **theme drop-in** that generates AVIF/WebP next to the original and builds a `<picture>` the browser can actually choose from.

## What v6 changes

The macro used to emit one `<source media>` per breakpoint with fixed 1x/2x densities, and asked the caller for absolute pixel widths. That multiplies files (one theme measured 185 variants of a single photo, and 98 distinct sizes across the site), prevents reuse between templates, and hands the browser only two steps to choose from — so on a device at DPR 1.75 it is forced up to the 2x candidate and downloads roughly twice the bytes it needs.

v6 emits a `srcset` with `w` descriptors from **one shared set of widths**, and lets the browser pick using the `sizes` each template declares.

## Features

- **One modern format per request**, resolved from what the server can encode (AVIF, else WebP), with the original as fallback.
- **Canonical widths** shared across the whole theme, so the same photo reuses the same files everywhere.
- **Never upscales.** Candidates are capped at the original width — Timber's `resize` will happily invent pixels, this does not.
- **Hybrid generation:** the most-used widths are built on upload, the rest on first request with a per-request budget, then after the response, then in a queue.
- **Queue survives without WP-Cron**, drained during admin requests, and discards jobs whose source is gone.
- **Failure awareness** keyed to quality, engine and SAPI, so changing a setting retires stale failures.
- **Twig:** `image_sources()`, `|toavif`, `|avif_src`, `|webp_src`, `|best_src`, `image.avif`, `image.webp`, `image.best`.
- **Admin UI:** settings, tools, statistics and logs under Settings → Timber AVIF.
- **WP-CLI:** `timber-avif detect`, `bulk`, `queue`, `clear-cache`.

## Installation

1. Copy `avif.php` into your theme (e.g. `inc/avif.php`) and require it:
   ```php
   require_once get_template_directory() . '/inc/avif.php';
   ```
2. Copy `macros.twig` into your Twig templates directory.

## Usage

```twig
{% import "partial/macros.twig" as macros %}

{# Full-bleed #}
{{ macros.image(image, { sizes: '100vw', atf: true }) }}

{# Half the viewport from lg, full width below, inside a padded container #}
{{ macros.image(image, {
    sizes: '(min-width: 64rem) 50vw, calc(100vw - 3rem)',
    imgClass: 'h-full w-full object-cover'
}) }}

{# A small logo: cap the candidates so the set stays sane #}
{{ macros.image(logo, { sizes: '200px', max: 400, alt: '' }) }}
```

### Options

| Option | Default | What it does |
|---|---|---|
| `sizes` | `'100vw'` | The image's CSS width. Must be accurate, or the browser picks the wrong candidate. |
| `widths` | canonical set | Override the candidate widths for this image only. |
| `max` | — | Cap the candidates, for images displayed small. |
| `ratio` | — | Crop server-side (`'16/9'` or a float). Only worth it when the original's aspect ratio is far from how it is shown — normally `object-cover` does the cropping. |
| `atf` | `false` | `fetchpriority="high"` instead of lazy loading. |
| `alt` | image alt/title | Pass `''` for decorative images. |
| `pictureClass` / `imgClass` | — | Classes on the two elements. |

`width` and `height` are always emitted, derived from the original's aspect ratio, so the CLS audit is satisfied without cropping a file.

### Filters and properties

```twig
{{ image.avif }}   {# AVIF URL or original #}
{{ image.webp }}   {# WebP URL or original #}
{{ image.best }}   {# Best available #}

{{ image.src|toavif }}
{{ image|avif_src(800, 600) }}
```

## Quality

**The scales are not comparable across codecs.** AVIF 75 is already past JPEG 95 in perceived quality; pushing AVIF to 90 roughly triples the file size for a difference the eye does not find on photographs. The "never below 90" rule that makes sense for JPEG does not transfer.

Defaults: AVIF 75 · WebP 90 · JPEG 95. JPEG quality applies to every resize WordPress and Timber generate, not only to uploads.

## Notes

- Capability detection is cached per SAPI: wp-cli, php-fpm and cron can be different PHP builds with different extensions.
- With `DISABLE_WP_CRON`, the queue is drained during admin requests instead.
