# Timber AVIF (v6.1.2)

Responsive images for Timber 2.x. A **theme drop-in** that generates AVIF/WebP next to the original and builds a `<picture>` the browser can actually choose from.

## What v6.1.2 fixes

Three fixes, all of which surface on a library that holds WebP originals — imported from an older site, or uploaded as WebP.

**WebP originals were invisible to every recovery tool.** `sibling_path()` has always accepted a `.webp` source and `on_upload()` never filtered by mime, so a WebP uploaded today got its siblings normally. But bulk convert, `wp timber-avif bulk` and the statistics panel all queried `image/jpeg`, `image/png`, `image/gif` — so a pre-existing WebP was never backfilled, never counted, and the front end fell back to converting it inline on every single request. On one site those were the featured images of most products and the category pages timed out. The three queries now share one `SOURCE_MIMES` constant that includes `image/webp`. Under `format_mode: webp` a WebP source is a no-op, because `sibling_path()` returns null when source and destination are the same file.

**A discarded conversion was re-encoded every 24 hours, forever.** "The output weighs more than the source" is deterministic for a given quality and engine — both of which are already in the transient key — so it is now remembered for a year rather than a day, and retires itself the moment either changes. Genuinely transient failures (busy lock, timeout, missing engine) still expire after 24h. Tools → Clear cache retires both.

**Discarding is no longer decided on a single byte.** The old test was `converted >= original`, which threw away an AVIF that came out 200 bytes over — routine on an already-compressed WebP source, where the better format is still worth having. A conversion is now discarded only when it is heavier by **more than 10% *and* more than 4 KB**, or by more than 50 KB whatever the ratio. So 3 KB → 3 KB and 64 KB → 69 KB are kept; 85 KB → 97 KB and 389 KB → 431 KB are discarded. The toggle under Settings → Discard controls the whole test, as it always did.

Two smaller things came out of the same pass. **The AVIF quality default is 75 again** — it had drifted to 65 in the code while both this file and the migration guide still said 75. It reaches new installs only; an existing site keeps the value saved in its settings. And **Tools → Clear cache now works under an external object cache**: it bumps a counter carried by every failure key instead of relying on a `DELETE` against the options table, which a Redis- or Memcached-backed transient never reaches. That was survivable while nothing outlived 24h, and is not once a verdict is held for a year.

## What v6.1 adds

An optional hook for the EU AI Act disclosure label, and nothing else. It is inert until a plugin answers it: for any image not flagged as AI generated or modified, v6.1 emits byte-for-byte what v6.0 emitted. See [AI disclosure](#ai-disclosure).

## What v6 changes

The macro used to emit one `<source media>` per breakpoint with fixed 1x/2x densities, and asked the caller for absolute pixel widths. That multiplies files (one theme measured 185 variants of a single photo, and 98 distinct sizes across the site), prevents reuse between templates, and hands the browser only two steps to choose from — so on a device at DPR 1.75 it is forced up to the 2x candidate and downloads roughly twice the bytes it needs.

v6 emits a `srcset` with `w` descriptors from **one shared set of widths**, and lets the browser pick using the `sizes` each template declares.

## Features

- **One modern format per request**, resolved from what the server can encode (AVIF, else WebP), with the original as fallback.
- **Canonical widths** shared across the whole theme, so the same photo reuses the same files everywhere.
- **Never upscales.** Candidates are capped at the original width — Timber's `resize` will happily invent pixels, this does not.
- **Hybrid generation:** the most-used widths are built on upload, the rest on first request with a per-request budget, then after the response, then in a queue.
- **Queue survives without WP-Cron**, drained during admin requests, and discards jobs whose source is gone.
- **Failure awareness** keyed to quality, engine and SAPI, so changing a setting retires stale failures. A conversion discarded for coming out heavier is remembered for a year instead of a day — the verdict cannot change while quality and engine hold. Tools → Clear cache retires the lot, external object cache or not.
- **AI Act disclosure hook**, dormant unless a plugin implements it (see [AI disclosure](#ai-disclosure)).
- **Twig:** `image_sources()`, `|toavif`, `|avif_src`, `|webp_src`, `|best_src`, `image.avif`, `image.webp`, `image.best`.
- **Admin UI:** settings, tools, statistics and logs under Settings → Timber AVIF.
- **WP-CLI:** `timber-avif detect`, `bulk`, `queue`, `clear-cache`.

## Installation

1. Copy `avif.php` into your theme (e.g. `inc/avif.php`) and require it:
   ```php
   require_once get_template_directory() . '/inc/avif.php';
   ```
2. Copy `macros.twig` into your Twig templates directory.
3. Optional: copy `languages/` into your theme root to get the admin UI in your language.

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
| `disclosure` | — | Corner for the AI Act label on flagged images: `top-left`, `top-right`, `bottom-left`, `bottom-right` (default), or `none`/`false` to leave it off this placement. Inert unless a plugin implements the disclosure filter. |

`width` and `height` are always emitted, derived from the original's aspect ratio, so the CLS audit is satisfied without cropping a file.

### Filters and properties

```twig
{{ image.avif }}   {# AVIF URL or original #}
{{ image.webp }}   {# WebP URL or original #}
{{ image.best }}   {# Best available #}

{{ image.src|toavif }}
{{ image|avif_src(800, 600) }}
```

## AI disclosure

AI Act art. 50(4) makes whoever publishes a deepfake disclose that it was generated or manipulated by AI, on first exposure, without the reader having to hover, click or open a file inspector. That rules out metadata and tooltips — and a watermark burnt into the pixels does not survive `object-cover`, which this macro applies by default.

So the label is a DOM sibling of `<picture>`, and this file only asks for it:

```php
apply_filters('bizen_ai_disclosure_badge', '', $attachment_id, $position)
```

**Nothing here implements that filter.** With no plugin listening it returns an empty string, the wrapper is never emitted, and the markup is identical to v6.0. The implementation lives in the `ai-disclosure` module of Bizen Toolkit, which owns the per-attachment status, the review queue and the official EU icon set.

Pick the corner per call:

```twig
{# Hero with a card over the bottom half: the only free corner is the top right #}
{{ macros.image(image, { sizes: '100vw', atf: true, disclosure: 'top-right' }) }}
```

`bottom-right` is the default. Which corner works is a property of the composition rather than of the file — the same photo is clear in the corner of a card and buried under an overlay panel in a hero — so it belongs at the call site, not on the image.

`disclosure: false` (or `'none'`) leaves the label off this placement, for a layout that discloses another way. It silences the placement, not the image: the same photo keeps its label everywhere else.

The wrapper the macro emits is `.bizen-ai-media--fill`, which assumes the picture is stretched to a parent that sizes it. That matches the default `imgClass` of `object-cover h-full w-full`. A call site that sizes the image itself wants the plain `.bizen-ai-media` instead, which is an edit to the macro.

## Quality

**The scales are not comparable across codecs.** AVIF 75 is already past JPEG 95 in perceived quality; pushing AVIF to 90 roughly triples the file size for a difference the eye does not find on photographs. The "never below 90" rule that makes sense for JPEG does not transfer.

Defaults: AVIF 75 · WebP 90 · JPEG 95. JPEG quality applies to every resize WordPress and Timber generate, not only to uploads.

A converted file is kept unless it is meaningfully heavier than its source: more than 10% **and** more than 4 KB over, or more than 50 KB over regardless. Each bar owns a range — the 4 KB floor decides below ~40 KB, the ratio between ~40 and ~500 KB, the 50 KB ceiling above that.

The floor is the one that says "a few KB is not worth giving up AVIF for", and it is deliberately small: it is paid once per image on the page, so a category page with 28 of them multiplies it by 28. It also has to stay under the ratio on tiny files, because AVIF is poor at small flat graphics and a 3 KB icon can come back at 12 KB. Below that the extra bytes are not worth giving up the better format, and on WebP sources — already compressed — landing a few hundred bytes over is the normal case, not a failure.

## Translations

The admin UI ships in English and loads a `.mo` matching the user's admin language. **Italian is included.** Without a `.mo` nothing breaks — the UI just stays in English.

The folder is looked up in this order, so it can sit wherever suits your theme:

1. `wp-content/themes/<child>/languages/` — lets a child theme override
2. `wp-content/themes/<parent>/languages/` — the WordPress convention, and where Loco Translate looks
3. next to `avif.php` — handy if you keep the drop-in self-contained

To add a language, translate `languages/timber-avif.pot` and compile it:

```bash
msgfmt -o languages/timber-avif-fr_FR.mo languages/timber-avif-fr_FR.po
```

To regenerate the template after editing strings:

```bash
wp i18n make-pot . languages/timber-avif.pot --domain=timber-avif --include=avif.php
```

## Notes

- Capability detection is cached per SAPI: wp-cli, php-fpm and cron can be different PHP builds with different extensions.
- With `DISABLE_WP_CRON`, the queue is drained during admin requests instead.
- The AVIF and WebP derivatives carry no XMP or C2PA — the encoders do not copy it across. Provenance is read from the original at upload time, so AI detection is unaffected, but a reader who downloads the served file gets one with no provenance embedded in it.
