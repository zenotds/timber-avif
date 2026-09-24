# Migration Guide

## From v6.1.x to v7.0

**Breaking in how it is installed, not in the templates.** Call sites stay as they are, `image_sources()` returns the same keys, and the settings carry over. What changes is where the code lives and when files are made.

The one thing to avoid is switching straight from v6 to v7: v7 does not read v6's files, so until its worker had been through the library every image would be served as JPEG. On a clone of a real catalogue that was pages three to fifteen times heavier, for the hour or more the conversion took. So v7 installs **next to** v6 first, converts the library while v6 keeps serving the site, and takes over when there is nothing left to do.

### Steps

1. **Install v7 next to v6.** With Composer (see [README](README.md#with-composer)) plus `TimberAVIF\Plugin::load();` in `functions.php`, or as a drop-in folder. Leave the `require` of `avif.php` where it is. v7 sees v6 and only *prepares*: its worker converts in the background, v6 still renders every page, owns the settings page and keeps its CLI commands. A notice on the dashboard and the media library shows how far it has got.
2. **Let it convert, or push it.** It runs on WP-Cron and after admin requests, a few images a minute on a site with traffic. To do it in one go:

   ```bash
   wp timber-avif prepare --all
   ```

   On the clone of a 1,454-image catalogue that was about 20 images a minute on a laptop — some 75 minutes — because every image uploaded before v7 also gets its canonical JPEG sizes built. A shared host is slower. v6 serves the site meanwhile, so the time is nobody's wait.
3. **Switch.** When the notice says the library is ready, remove the `require` of `avif.php` from `functions.php`, and the file. The first request stores the settings the way v7 reads them, retires v6's queue, log, crons and cached failures, and purges WP Rocket's cache — every page cached while v6 rendered points at v6's files. Nothing is re-encoded: the settings in effect are the same before and after, so every image the worker prepared is already done. **With another page cache or a CDN caching HTML, purge it now yourself**, before step 6.
4. **Point the macro at the package.** In `partial/macros.twig`, replace the body of `image()` with a delegation, and every call site keeps working:

   ```twig
   {% macro image(image, options = {}) %}
   	{% import '@timber-avif/macros.twig' as tavif %}
   	{{ tavif.image(image, options) }}
   {% endmacro %}
   ```

   From then on, updating the package updates the macro, `sizes="auto"` included. A theme that forked the macro can keep its fork: `image_sources()` returns the same keys it did.
5. **Remove theme code v7 now does itself, or no longer offers.**
   - A `wp_content_img_tag` filter that wraps editor images in a `<picture>` (Mobilissimo, `functions/custom.php`): v7 does it first, and the theme's filter finds the `<picture>` and returns. Anything else it did — dropping `fetchpriority` from content images — belongs in a filter of its own.
   - Calls to v6's static API (`TimberAVIF::filter_toavif()`, `TimberAVIF::forget_failure()`): the class no longer exists, so code guarded by `class_exists()` or `method_exists()` simply stops running. An import that replaces files (Dalmec's sync) needs nothing: v7 notices a replaced file when its metadata is saved, and purges WP Rocket once the new copies exist.
   - `|toavif`, `|avif_src`, `|webp_src`, `image.avif` / `.webp` / `.best`: gone. `|best_src` stays, for posters and placeholders; anything else is a `macros.image()`.
6. **Remove what v6 left.** Tools → *Remove v6 files*, or `wp timber-avif purge-v6` — both there only until it is done. It deletes `photo.avif` / `photo.webp` next to `photo.jpg` (for originals, sub-sizes and Timber resizes alike) and the `.lock` files. With *Also delete the JPEGs Timber resized* (`--timber-resizes`), Timber's `-640x0-c-default.jpg` files go too: v7 never reads them, and on Mobilissimo they were 1.2 GB, next to 650 MB of v6 AVIF. Every file WordPress lists for an attachment is protected, and Timber rebuilds any resize a template still asks for with `|resize`. The page cache is purged again afterwards.
7. **Look at Settings → Timber AVIF once.** A value that differs from the default shows the default next to it.

### Settings

v6 wrote every default into the database on its first run, so its option cannot tell a choice from a default nobody touched. v7 reads a v6 value equal to a v6 default as a default: it follows v7's defaults from then on. Two of them changed:

- **JPEG quality: 95 → 82.** v6 needed 95 because every AVIF was transcoded from those JPEGs; v7 encodes from the full-size file, so the JPEG is only the fallback. Existing JPEGs are not re-encoded: new sub-sizes use 82.
- **AVIF quality: 65 → 75** on sites installed with 6.0–6.1.1, whose default was 65 by mistake. That one does re-encode those sites' library, in the background: 75 is what the documentation always promised.

A value that differs from both was a choice, and stays. From v7 on only choices are stored, so future defaults reach every site that left them alone.

### What behaves differently

- **Nothing is converted while a page renders.** v6 converted up to ten files inline, then after the response, then in a queue. v7 only ever reads.
- **The Twig API is the macro, `image_sources()` and `|best_src`.** See step 5.
- **Only library images get modern formats.** A theme asset or external URL passed to the macro, or to `|best_src`, is served as it is. Those are better converted once by the theme's build.
- **`widths` picks from Settings → Widths.** Files exist only for the configured widths, so a per-call width outside them is ignored (and logged under `WP_DEBUG`). To add a width, add it in Settings: it is built for the whole library in the background.
- **`max` below every configured width** is built on request, like a crop: the first render asks for it, the worker builds it. v6 built it on the spot.
- **`ratio` is built on request.** The first render of a new ratio records it and returns the uncropped files in a box of that ratio; the worker builds the crops shortly after.
- **`|best_src(w, h)`** returns the closest existing width at or above `w` — cropped when `h` is given — instead of a file resized to exactly that size; a width well below every configured one is built on request.
- **Settings removed:** *Pre-generate on upload* and its widths (every width is built at upload now) and *Per page* (there is no inline conversion to budget). **Added:** *Content images*.
- **The Logs tab is now Issues**: the images that currently have a problem, with the reason, instead of the last 200 events.
- **Requirements:** PHP 8.1 and WordPress 6.5, for AVIF support in WordPress's image editors.

## From v6.1.2 to v6.1.3

**Not breaking.** No call site, macro or setting changes. Markup is identical, and there is nothing to run.

### Check the queue once, then forget it

Until v6.1.3 the background queue woke up once an hour and cleared 20 jobs a pass, because the two quick wake-ups that were supposed to fire between heartbeats guarded on a hook that the hourly event always kept scheduled. At 20 jobs an hour against a 500-job ceiling that drops the oldest entries, a busy site sat pinned at 500 indefinitely.

After replacing `avif.php`, look at Settings → Timber AVIF → Tools → Queue. A count at or near 500 is that backlog. It now clears on its own, about sixty times faster; **Process now** empties it in one go if you would rather not wait.

Entries the ceiling already dropped are gone and do not come back as queue entries. Nothing is lost by it: the front end rebuilds those variants the next time a page asks for them.

The queue is also drained during admin requests now, not only under `DISABLE_WP_CRON`. It runs after `fastcgi_finish_request()`, so no one waits for it, and it returns immediately when the queue is empty.

## From v6.1 to v6.1.2

**Not breaking.** No call site, macro or setting changes. Markup is identical.

### Run the backfill if your library holds WebP originals

Until v6.1.2, bulk convert, `wp timber-avif bulk` and the statistics panel queried only `image/jpeg`, `image/png` and `image/gif`. A WebP uploaded after the drop-in was installed still got its siblings on upload — `on_upload()` never filtered by mime — but **a WebP that was already in the library, or imported from an older site, was never backfilled and never counted.** The front end then converted it inline on every request, against the per-request budget, which is what a slow or timing-out category page looks like.

After replacing `avif.php`:

```bash
wp timber-avif bulk
```

Or Settings → Timber AVIF → Tools → Convert everything. The statistics total will go up by the number of WebP originals you have; that is the count that was missing before, not new files.

Nothing to undo if you run `format_mode: webp`. There the destination *is* the source, `sibling_path()` returns null, and WebP sources are skipped exactly as they were.

### Discarded conversions are no longer retried nightly

A conversion thrown away for coming out heavier than its source used to be remembered for 24h, like a busy lock or a timeout. But that verdict is deterministic for a given quality and engine, so it was re-encoding the same files every day, forever — most visibly on WebP sources, which are already compressed. It is now remembered for a year, keyed as before to quality, engine and SAPI, so changing any of them still retires it. Tools → Clear cache retires it too.

### The discard test now has a tolerance

The test was `converted >= original`, down to the byte. A 5 KB WebP whose AVIF came out 5 KB was discarded and re-encoded daily, for nothing. Now a conversion is discarded only when it is heavier by **more than 10% and more than 4 KB**, or by more than 50 KB whatever the ratio. The three bars split the range between them: the floor decides below ~40 KB, the ratio between ~40 and ~500 KB, the ceiling above that.

The practical effect is that small images keep their AVIF and large regressions are still thrown out. If you prefer the old byte-exact behaviour there is no setting for it — change `OVERSIZE_MIN_RATIO`, `OVERSIZE_MIN_BYTES` and `OVERSIZE_HARD_LIMIT` at the top of `avif.php`.

Files discarded under the old rule are not regenerated on their own — their verdict is cached — so the migration steps below re-test them.

### The AVIF quality default is 75

It had drifted to 65 in the code while both this guide and the README still said 75. The constant is back at 75.

This reaches **new installs only**: an existing site has `avif_quality` saved in its settings and keeps whatever is there. To pick it up, set it under Settings → Timber AVIF. Raising the quality invalidates cached verdicts on its own — quality is part of the failure key — so files discarded at 65 are re-tested at 75, and a few more of them will land over the source and be discarded, AVIF at 75 being the larger file.

### Migration steps

1. Replace `avif.php`. `macros.twig` is unchanged: no call site, no macro, no template needs touching.
2. `wp timber-avif bulk`, or Tools → Convert everything. **Required if the library holds WebP originals**, worth running regardless: it retries every original and every registered size whatever verdict was cached, so the old byte-exact discards get re-tested under the tolerance.
3. Tools → Clear cache. Bulk does not reach the resized variants the front end builds from the canonical widths — those carry their own cached verdicts, and clearing is what retires them, so the next page view re-tests them.

Step 3 now works under an external object cache too. Flushing bumps a counter that every failure key carries, rather than relying on a `DELETE` against the options table that a Redis- or Memcached-backed transient never reaches. That mattered little while everything expired within 24h; it matters when a verdict is held for a year.

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
- Up to v6, variants were found on disk next to the original, by `sibling_path()`, with no index. From v7 each attachment keeps an index in its post meta (`_tavif`), and the render path never touches the disk.
