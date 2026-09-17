# Migration Guide

## From v6.0 to v6.1

**Not breaking.** No call site has to change, and any image not flagged as AI generated or modified renders byte-for-byte as it did in v6.0.

### What is new

`image_sources()` returns one more key, `disclosure`, and the `image()` macro takes one more option of the same name. Both stay inert until something implements the `bizen_ai_disclosure_badge` filter — see [AI disclosure](README.md#ai-disclosure).

### Migration steps

1. Replace `avif.php` and `macros.twig`. **Both are copies living inside each theme**, so this is once per theme: updating the repo propagates nothing on its own.
2. Nothing else is required. Stop here unless the site publishes AI-generated or AI-modified imagery.
3. If it does, install the `ai-disclosure` module, work through Media → AI Disclosure, then add `disclosure: '<corner>'` to the `macros.image()` calls whose composition covers the bottom right — heroes with an overlay card, anything with a caption or a gradient scrim at the foot of the image.

### If you forked the macro

Themes carrying a customised `macros.twig` need three additions by hand: `disclosure: null` in the config map, `disclosure: config.disclosure` in the `image_sources()` call, and the conditional wrapper around `<picture>`.

The label cannot go *inside* `<picture>`: its content model admits only `<source>`, `<img>` and script-supporting elements, and the browser will hoist anything else out of it.

## From v5.3 to v6.0

**Breaking: the `image()` macro changes signature.** Every call site has to be updated.

### The macro

`sizes` was a map of absolute pixel widths per breakpoint. It is now a **CSS `sizes` string** describing how wide the image is displayed. Heights are gone: the crop is optional and lives in `ratio`.

```twig
{# v5.3 #}
{{ macros.image(image, {
    sizes: { 'xs': [640, 480], 'md': [1024, 768], 'lg': [768, 640], '2xl': [1280, 900] }
}) }}

{# v6.0 #}
{{ macros.image(image, {
    sizes: '(min-width: 64rem) 50vw, calc(100vw - 3rem)'
}) }}
```

To translate a recipe, ignore the old numbers and describe the layout instead: read the CSS around the call and write how wide the image actually renders. Getting this wrong is the one way to make v6 worse than v5 — a `sizes` that overstates the width makes the browser download a larger candidate than it needs, and one that understates it serves a blurry image.

Where the old recipe genuinely changed the aspect ratio per breakpoint — real art direction, not just a different size — keep cropping with `ratio`, or keep a `<source media>` by hand. In practice this is rare: if `object-cover` is doing the cropping in CSS, the server-side crop was only ever costing files.

### Settings

- `generate_avif_uploads` and `generate_webp_uploads` are **removed**. The format to generate is the one being served, decided by the new `format_mode` (`auto` / `avif` / `webp` / `off`). Two separate flags could only contradict it.
- `pregenerate_breakpoints` and `breakpoint_widths` now do what they always promised — in v5.3 they were saved and never read.
- New: `pregenerate_widths` (built on upload), `jpeg_quality`, `max_upload_dimension`.
- **AVIF quality default drops to 75.** If you had it at 80+, note that v5.3 never applied it (see below), so your files are lighter than the setting suggested.

### Fixes worth knowing about

- **`Imagick::setImageCompressionQuality()` has no effect on AVIF.** The coder reads `image_info->quality`, i.e. `setCompressionQuality()` — and JPEG is the exact opposite. In v5.3 every AVIF came out at libheif's internal default regardless of the setting. v6 calls both.
- **The capability transient was shared across SAPIs.** `Imagick` can exist under php-fpm and not under wp-cli: the CLI read the engine written by the web process, called it, and every conversion failed with "Engine returned false". The cache key now includes the SAPI and the engine is revalidated before use.
- **The failure cache masked fixed problems.** A failed conversion stayed blocked for 24h even after the cause was corrected. The key now includes quality and engine.
- **Timber upscales silently.** A candidate wider than the original produced a file both heavier and blurrier than the original itself. Capped.
- Animated GIFs are skipped instead of being flattened to their first frame.

### Translations

The admin UI is now translatable (textdomain `timber-avif`), with Italian included. Copy `languages/` next to `avif.php`; without it the UI stays in English, as before.

### Migration steps

1. Replace `avif.php` and `macros.twig`, and copy `languages/` if you want a translated admin.
2. Update every `macros.image()` call: translate the `sizes` map into a CSS string.
3. Review Settings → Timber AVIF: pick `format_mode`, check the quality values, choose which widths to pre-build on upload.
4. **The old variants are now orphans.** Every `-WxH-c-default.*` file at a size nothing asks for any more stays on disk — in one theme that was 690 files and 35 MB. Delete them, or use Tools → Purge to drop all generated files and let them rebuild.
5. Run `wp timber-avif bulk` if you want the library rebuilt at the new quality.

## From v4.0 to v5.3.0

### Architecture Changes
- **Hybrid Conversion:** The engine now uses a per-request inline budget (default: 10 images). Any overflow is automatically sent to a background queue (processed via shutdown or WP-Cron). This fixes performance bottlenecks on image-heavy pages.
- **Failure Awareness:** Failed or skipped conversions (e.g., if the AVIF is larger than the original) are now remembered for 24 hours. This prevents the server from attempting impossible conversions on every page load.
- **Plugin Sunset:** The standalone plugin has been deprecated in favor of the theme drop-in (`avif.php`). All features (including Admin UI and CLI) are now fully contained in `avif.php`.

### Migration Steps
1. Replace your `avif.php` and `macros.twig` with the v5.3.0 versions.
2. If you were using the plugin, deactivate/delete it and require `avif.php` in your `functions.php` instead.
3. Check **Settings > Timber AVIF > Logs** to see if any images are failing conversion and why.
4. Run the queue via CLI if you have a massive backlog:
   ```bash
   wp timber-avif queue
   ```

## From v3.0 to v4.0
- **Admin:** Added Statistics and AJAX bulk conversion.
- **Macro:** Switched to mobile-first `(min-width)` only and added 2x capping.

## Notes
- Capability detection is cached for a week; use `wp timber-avif clear-cache` after changing server libraries.
- Variants are tracked in `_timber_variants` post meta for fast lookups.
