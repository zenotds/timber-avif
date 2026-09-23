# Timber AVIF (v7.0.0-dev)

Responsive images for Timber 2.x. Generates AVIF (or WebP) copies of every image in the media library **in the background**, and builds a `<picture>` whose markup depends only on what has been recorded — never on what a page render managed to convert.

> v7 is a rewrite, installed as a Composer package or a drop-in folder. Templates do not change. Upgrading from v6: see [MIGRATION.md](MIGRATION.md#from-v61x-to-v70) — v7 can prepare the whole library while v6 still serves the site.

## What changes in v7

v6 converted files while pages rendered, and asked the disk what existed. Most of its changelog came from there: a queue that drained twenty times slower than intended, WebP originals nobody saw, verdicts re-encoded nightly, a first page view that took 12–14 seconds on a catalogue. v7 moves all of it out of the render path.

- **Rendering never converts, resizes or touches the disk.** `image_sources()` reads the attachment's metadata and a small index in its post meta. A page cache stores exactly what a fresh render would produce. About 0.12 ms per image.
- **Conversion happens in a background worker**: WP-Cron, after an admin response has been sent, the admin's *Process now*, or `wp timber-avif work`. One worker at a time for the whole site; v6 counted its budget per request, so twenty concurrent requests could run two hundred encodes.
- **The queue is derived, not stored.** An image is pending when its stamp differs from the current settings' fingerprint. An upload has no stamp, a new quality changes the fingerprint, a template asking for a new crop deletes the stamp. There is no list to lose jobs from and no cron guard to get wrong.
- **The canonical widths are registered image sizes**, built by WordPress at upload like any other sub-size. `wp media regenerate`, the `-scaled` original and deletion behave as in core.
- **Encoding goes through WordPress's image editors**, from the file WordPress serves as full size. Colour profiles and EXIF orientation are kept: v6's Imagick engine stripped the ICC profile, and GD — which it tried first — never had one, so Display P3 photos changed colour.
- **Modern files are named after the file they replace** (`photo-640x427.jpg.avif`) and listed in the index, so deleting an image deletes them. v6 swapped the extension, so `photo.jpg`, `photo.png` and an uploaded `photo.avif` all claimed the same path, and nothing ever deleted its copies.
- **Images in post content** get a `<picture>` too, through `wp_content_img_tag`.
- **Settings store only what differs from the defaults**, so a value left alone follows future defaults. Still edited under Settings → Timber AVIF.
- **A Composer package** (or a drop-in folder), with the macro shipped as `@timber-avif/macros.twig`, so themes stop carrying copies that drift.

Measured on a clone of a real catalogue (Mobilissimo, 1,454 images): 2,306 AVIF files checked, none missing, invalid or of the wrong dimensions, 59% lighter than the JPEGs they replace, with a median visual difference (RMSE) of 0.003. Once converted, pages weigh what they did with v6 and render 5–15% faster; content images and small thumbnails, which v6 got wrong or not at all, are lighter.

## Requirements

- PHP 8.1+, WordPress 6.5+, Timber 2.3+
- Imagick with AVIF support (ImageMagick 7 + libheif) is recommended. GD works as a fallback, but keeps no colour profile; the admin says so when it is the engine in use.

## Installation

### With Composer

The theme already installs Timber through Composer. The package does not need to be on Packagist: Composer installs it straight from the GitHub repository. In the theme's `composer.json`:

```json
"repositories": [
	{ "type": "composer", "url": "https://wpackagist.org" },
	{ "type": "vcs", "url": "https://github.com/zenotds/timber-avif" }
],
"require": {
	"timber/timber": "^2.3",
	"zenotds/timber-avif": "^7.0"
}
```

Then start it from `functions.php`, next to Timber:

```php
require_once __DIR__ . '/vendor/autoload.php';
Timber\Timber::init();
TimberAVIF\Plugin::load();
```

Versions come from the repository's tags (`v7.0.0` → `7.0.0`); during development, require `dev-v7`. Each site's `composer.lock` pins the version it runs, and `composer update zenotds/timber-avif` moves it forward when you decide to. `vendor/` has to reach the server the same way it does today for Timber.

The call is explicit, rather than run by Composer's autoloader, because the autoloader is also loaded where WordPress is not — a PHPUnit bootstrap, a build script — and there hooking into WordPress is a fatal error.

### As a drop-in

Copy the whole folder into the theme (e.g. `inc/timber-avif/`) and require the bootstrap from `functions.php`:

```php
require_once get_template_directory() . '/inc/timber-avif/timber-avif.php';
```

## Usage

```twig
{% import "@timber-avif/macros.twig" as tavif %}

{# Full-bleed #}
{{ tavif.image(image, { sizes: '100vw', atf: true }) }}

{# Half the viewport from lg, full width below, inside a padded container #}
{{ tavif.image(image, {
    sizes: '(min-width: 64rem) 50vw, calc(100vw - 3rem)',
    imgClass: 'h-full w-full object-cover'
}) }}

{# An 80px square thumbnail #}
{{ tavif.image(post.thumbnail, { sizes: '80px', max: 320, ratio: '1/1' }) }}
```

A theme whose `partial/macros.twig` holds other macros too can keep every call site as it is and delegate:

```twig
{% macro image(image, options = {}) %}
	{% import '@timber-avif/macros.twig' as tavif %}
	{{ tavif.image(image, options) }}
{% endmacro %}
```

`image` can be a Timber image, an attachment ID, a `WP_Post`, an ACF image array, or the URL of an original upload. Anything that is not a library image — a theme asset, an external URL — is served as it is.

### Options

| Option | Default | What it does |
|---|---|---|
| `sizes` | `'100vw'` | The image's CSS width. Lazy images get `auto, ` in front of it: browsers that support it measure the real width, and the rest use yours. |
| `widths` | Settings → Widths | Pick a subset of the configured widths for this image. Widths not configured have no files and are ignored. |
| `max` | — | Cap the candidates, for images displayed small. One candidate past it is kept, for DPR 2. Below every configured width, the worker builds that width for this image. |
| `ratio` | — | Crop server-side (`'16/9'` or a float). The first render asks for it; until the worker builds it, the uncropped files are served in a box of that ratio and `object-cover` crops them. |
| `atf` | `false` | `fetchpriority="high"` instead of lazy loading. |
| `alt` | image alt/title | Pass `''` for decorative images. |
| `pictureClass` / `imgClass` | — | Classes on the two elements. |
| `disclosure` | — | Corner for the AI Act label on flagged images: `top-left`, `top-right`, `bottom-left`, `bottom-right` (default), or `none`/`false` to leave it off this placement. Inert unless a plugin implements the disclosure filter. |

`width` and `height` are always emitted, so the CLS audit is satisfied without cropping a file.

### Filters and properties

```twig
{{ image.avif }}   {# full size as AVIF, or the original #}
{{ image.webp }}   {# full size as WebP, or the original #}
{{ image.best }}   {# the format being served, or the original #}

{{ image|toavif }}
{{ image|best_src(1280, 720) }}   {# smallest width ≥ 1280, cropped to 16:9 #}
```

`avif_src`, `webp_src` and `best_src` pick the closest existing width at or above the one asked for; v6 resized to the exact size, inline. On the URL of one particular file — a sub-size — `|toavif` returns that file's own copy. A theme with its own Timber image class keeps it, and adds the three properties with `use \TimberAVIF\ModernSources;`.

### From PHP

v6's static API is still there, backed by v7, for themes that call it: `TimberAVIF::image_sources()`, `TimberAVIF::filter_toavif()`, `filter_avif_src()`, `filter_webp_src()`, `filter_best_src()`.

### Content images

Images in post content and ACF WYSIWYG fields — anything that goes through `wp_filter_content_tags` — are wrapped in a `<picture class="tavif-content" style="display:contents">` with the modern `<source>`. `display: contents` means the wrapper makes no box, so margins, floats and `align*` classes on the `<img>` behave as before; WordPress's `<img>` itself is untouched. Cropped sizes (thumbnails, a theme's squares) are left as they are. Settings → Content turns it off.

## How it works

1. **Upload.** WordPress builds its sub-sizes, including one per canonical width (`tavif-640`, …) below the image's own width. Nothing is converted during the upload request.
2. **Pending.** The attachment has no stamp yet, so it is pending. The worker is woken through WP-Cron half a minute later, once the uploader is done.
3. **Worker.** Builds any canonical size an older image lacks, then encodes each candidate from the full-size file, writing it aside and renaming it into place. A copy meaningfully heavier than the file it replaces is discarded, and the verdict is recorded. The index and the stamp are saved.
4. **Render.** Candidates come from the metadata, modern copies from the index. The `<source>` is emitted only when every candidate has been processed and the largest has a modern copy; otherwise the fallback set alone is served, which is always complete.

Changing a setting that affects the files — format, quality, widths, limits — changes the fingerprint, and the whole library becomes pending. The current files keep being served until each one is replaced.

The worker is protected against the cases that stall a queue: an image slower than the time budget still advances one file per pass, an image that keeps killing the PHP process is set aside after three attempts, a worker that dies holding the lock releases it when the lock expires, and a failed encode is retried after a day, three times.

## Admin

Settings → Timber AVIF.

- **Settings**: format (auto, AVIF, WebP, none), quality per format, widths, content images, upload and conversion limits, the discard tolerance. Each value that differs from its default shows the default next to it; *Reset to defaults* removes them all.
- **Tools**: *Process now* works through the queue from the browser. *Rebuild everything* re-encodes the library, after upgrading the server's image libraries for instance. *Clear cache* detects the engines again and retries failures. *Delete conversions* removes every generated file, rebuilt in the background. *Remove v6 files* deletes what v6 left behind, optionally with Timber's resized JPEGs.
- **Issues**: the images the worker could not convert, with the reason.

The media library gets a column with each image's state.

## WP-CLI

```bash
wp timber-avif status                        # format, engine, widths, pending count
wp timber-avif work [--all]                  # convert pending images; --all until the queue is empty
wp timber-avif rebuild                       # re-encode everything with the current settings
wp timber-avif purge                         # delete generated files
wp timber-avif purge --v6 [--timber-resizes] # delete what v6 left behind
wp timber-avif detect                        # which engine encodes AVIF / WebP in this PHP
wp timber-avif clear-cache                   # detect the engines again
wp timber-avif prepare [--all]               # while v6 is still loaded: convert for v7
```

`bulk` and `queue` still work, as aliases of `work --all` and `work`.

The CLI may be a different PHP build than the web server. If it cannot encode the format being served, `work` says so and does nothing, rather than recording failures the web server would not have.

## AI disclosure

AI Act art. 50(4) makes whoever publishes a deepfake disclose that it was generated or manipulated by AI, on first exposure, without the reader having to hover, click or open a file inspector. That rules out metadata and tooltips — and a watermark burnt into the pixels does not survive `object-cover`, which this macro applies by default.

So the label is a DOM sibling of `<picture>`, and this package only asks for it:

```php
apply_filters('bizen_ai_disclosure_badge', '', $attachment_id, $position)
```

**Nothing here implements that filter.** With no plugin listening it returns an empty string and the wrapper is never emitted. The implementation lives in the `ai-disclosure` module of Bizen Toolkit, which owns the per-attachment status, the review queue and the official EU icon set.

Pick the corner per call, because which corner is free is a property of the composition, not of the file:

```twig
{# Hero with a card over the bottom half: the only free corner is the top right #}
{{ tavif.image(image, { sizes: '100vw', atf: true, disclosure: 'top-right' }) }}
```

`disclosure: false` (or `'none'`) leaves the label off this placement, for a layout that discloses another way. The same photo keeps its label everywhere else.

The wrapper the macro emits is `.bizen-ai-media--fill`, which assumes the picture is stretched to a parent that sizes it — the default `imgClass`. A call site that sizes the image itself wants the plain `.bizen-ai-media`.

## Quality

**The scales are not comparable across codecs.** AVIF 75 is already past JPEG 95 in perceived quality; pushing AVIF to 90 roughly triples the file size for a difference the eye does not find on photographs.

Defaults: AVIF 75 · WebP 90 · JPEG 82. The JPEG is only the fallback now — v6 needed 95 because every AVIF was transcoded from those JPEGs; v7 encodes from the full-size file. It applies to every sub-size WordPress generates.

A converted file is kept unless it is meaningfully heavier than the one it replaces: more than 10% **and** more than 4 KB over, or more than 50 KB over regardless. The floor decides below ~40 KB, the ratio between ~40 and ~500 KB, the ceiling above that.

The modern copies keep the colour profile and nothing else: EXIF, XMP and IPTC were about 2 KB per file, 8% of the smallest ones, for data no browser reads. Provenance is read from the original upload, which keeps them.

## Translations

The admin ships in English and loads a `.mo` matching the user's admin language. **Italian is included.** The folder is looked up in the child theme's `languages/`, then the parent theme's, then the package's own.

```bash
wp i18n make-pot . languages/timber-avif.pot --domain=timber-avif --exclude=tests,vendor,assets
msgfmt -o languages/timber-avif-fr_FR.mo languages/timber-avif-fr_FR.po
```

## Notes

- Some Imagick builds (PECL from source, MAMP, a few hosts) report their version as `@PACKAGE_VERSION@`, and WordPress refuses them as too old. Timber AVIF accepts them, checking everything else WordPress checks.
- Capability detection is cached per SAPI: wp-cli, php-fpm and cron can be different PHP builds with different extensions.
- Animated GIF, WebP and PNG sources are served as the original: every encoder here keeps the first frame only.

## Development

```bash
php tests/unit.php                     # no WordPress needed
wp eval-file tests/integration.php     # a throwaway site with Timber and this package
```

The integration test uploads its own images, runs the worker, renders through Twig and deletes what it made. It changes settings: do not point it at a real site.
