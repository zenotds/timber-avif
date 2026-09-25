# Roadmap

7.1 is planned; what follows it is proposals, not commitments. Each item says what it is for and what has to be found out before it is built.

## 7.1

**Status:** planned, September 2026. Written after moving five sites from v6 to v7.

7.0's way of making files stays: the upload writes the canonical JPEG sizes, the worker converts the whole library. 7.1 adds to it. The worker no longer needs traffic or a reloaded admin page, and a tool removes, on request, the files no template serves. The v6 code goes.

In this order:

1. **The v6 code**, first, because it takes code out of `Plugin`, `Config`, `Admin` and `Cli` before anything else changes there.
2. **The worker**, which is small.
3. **Optimize**, last.

### 1. Remove the v6 code

No site runs v6 any more, and new projects start from v7. What only served the move from v6 goes:

- `src/Migration/V6.php`: reading v6's settings, prepare mode's notice, the take-over, *Remove v6 files*, `wp timber-avif prepare` and `purge-v6`.
- In `Plugin`: prepare mode (`preparing()`, the check for a global `TimberAVIF` class, the early return in `boot()`), `V6_LEFTOVERS`, and the call to `take_over()` in `init()`.
- In `Config`: reading an option v6 wrote (`Migration\V6::settings()` in `normalize()`, and `migrate()`). The `_v` marker can stay, as a schema version.
- In `Admin` and `Cli`: the tool and the commands.
- The 7.0.3 re-encode in `Plugin::init()`. It runs only on a site that upgrades from before 7.0.3 and encodes AVIF through GD, and every site has run 7.0.5.
- `tests/prepare.php`, `tests/fake-v6.php`, `MIGRATION.md`, and the README's v6 paragraphs.
- Comments that explain v7 by contrast with v6 are rewritten to stand on their own.

**Before:** on each site, `timber_avif_v6_leftovers` must be gone, which means *Remove v6 files* has run. Where it has not, v6's files stay on disk until Optimize's orphan report finds them.

### 2. The worker carries on by itself

Today a pass starts from:

- WP-Cron, which needs a visitor;
- the end of an admin request (`Worker::on_admin_shutdown()`, 8 s once the response is sent), which is why reloading Settings → Timber AVIF moves the queue;
- *Process now*, one AJAX pass after another while the page stays open;
- `wp timber-avif work`.

On a site without traffic, a library of a thousand images stops between visits.

**Design.**

- At the end of a pass that converted something and leaves work pending, the worker starts the next one: a non-blocking loopback request to an endpoint that runs one pass.
- Not `spawn_cron()`, although it makes the same kind of request: it returns at once inside a cron request, which is where the pass runs.
- The endpoint answers anonymous requests, so it checks a single-use token the worker stores before the call.
- The lock is released before the call, and the next pass takes it as today.
- The chain stops when the queue is empty, when a pass converts nothing (only failing images left), or when the loopback fails. WP-Cron and the heartbeat then carry on as today.

**Admin.**

- While work is pending, the queue card fetches the count every few seconds and updates without a reload.
- *Process now* stays, for hosts that block loopback requests. It shows the remaining count while another worker holds the lock, and no WP-Cron pass is scheduled while it runs, so the two stop alternating.

**To find out:** whether the production host allows loopback requests (Site Health tests it), and whether a security plugin or HTTP authentication on staging blocks them.

### 3. Optimize

**Goal.** Reclaim disk space by removing, on request, the files no template serves. Nothing changes until it runs, and a dry run says first how much it would free.

Disk use on Mobilissimo, fully converted (1,648 images, widths 480–1920 plus full size):

| Width | AVIF files | AVIF MB | Canonical JPEG files | JPEG MB |
|---|---|---|---|---|
| 480 | 1,645 | 31 | 1,645 | 74 |
| 640 | 1,568 | 48 | 1,572 | 111 |
| 768 | 1,512 | 63 | 1,513 | 141 |
| 1024 | 1,130 | 73 | 1,144 | 155 |
| 1280 | 904 | 83 | 904 | 171 |
| 1600 | 491 | 65 | 491 | 119 |
| 1920 | 186 | 40 | 186 | 78 |
| full size | 1,538 | 206 | — | — |
| **Total** | **9,002** | **611** | **7,462** | **848** |

On the same site, originals and `-scaled` files take 489 MB and WordPress's other sizes (thumbnail, medium, 1536, crops) 183 MB.

1. **The full-size AVIF is a third of all AVIF bytes.** Every image gets one, whatever it is used for.
2. **The JPEG fallbacks weigh more than the AVIF copies** (848 MB against 611 MB), and only the few browsers without AVIF download them.
3. **Widths from 1600 up, full size included, are 51% of the AVIF bytes** and 23% of the JPEG ones.

#### What it reads

1. **Where each image is placed**, from the database: featured images (`_thumbnail_id`), ACF fields, options pages, term meta and `post_content`. ACF's field definitions (`acf-json`) say which keys hold an image or a gallery, down to repeaters and flexible content layouts.
2. **Which template shows it**, from the theme's Twig: every call to `macros.image()`, `image_sources()` and `|best_src`, with its `sizes`, `max` and `ratio`.
   - In the devkit's layout, `block-{acf_fc_layout}.twig` shows `content.<field>`, which links a layout's field to its call.
   - A `sizes` built by a condition counts as its widest branch. Dalmec's `block-blocks.twig` picks 310, 415 or 630 px by `count`: 630 it is.
3. **What the theme declares**, through a filter, for what neither of the above shows: a post type's featured image in a teaser, an image a PHP query picks (Dalmec's variants and accessories).

#### The need

For every place an image is found, the largest width its call can use:

- `sizes` evaluated (see *Evaluating `sizes`*), times a density cap of 2x, rounded up to the next configured width;
- `max` and `|best_src(w)` as they are;
- per ratio.

An image's need is the largest over all its places. An image with a place the tool cannot read keeps everything.

#### Report and Optimize

**Report (dry run)**, files and MB per category:

- widths beyond the need;
- images no content references (only what WordPress made is kept);
- orphans: files in `uploads` no attachment owns, v6 and Timber leftovers included;
- WordPress sizes nobody serves (`medium_large`, `1536x1536`, `2048x2048`), reported only;
- the images whose use was not found, so the theme's declarations can be completed.

**Optimize** deletes what the report lists, except the rows reported only, then purges the page cache. It is the same pattern as *Delete conversions*: a cached page pointing at a deleted AVIF shows a broken image. CLI: `wp timber-avif optimize [--dry-run]`.

**Never deleted:**

- originals and `-scaled` files;
- the sizes WordPress itself registers (thumbnail, medium…): `og:image` from Yoast, newsletters and other sites may link them directly;
- anything of an image whose use is not known.

#### After Optimize: the cap

Optimize writes each image's need into its index as a cap, per ratio. Deleting the files alone would not hold:

- **The worker would make them again** at the next settings change, which puts every image back in the queue.
- **The renderer would stop serving the modern format.** A candidate missing from the index makes the whole srcset unusable (`Renderer::modern_srcset()`), so the page falls back on JPEG.
- **`Sizes::candidates()` would take the image for one uploaded before v7.** A configured width without its `tavif-*` file makes it fill the set with every other proportional size WordPress made, until the worker builds the rest. With the devkit's `custom.php` that is `medium` (300) on a new upload; on images uploaded before a theme removed them, also `large`, `1536x1536` and `2048x2048`. The worker would convert them all.

So the cap goes where both the worker and the renderer read: `Sizes::candidates()` counts only the configured widths up to it, so a capped image is complete and nothing is filled in. The deleted JPEGs are also removed from the attachment's metadata, or the `<img>` srcset lists missing files.

WPML translations sharing a file share its cap: the largest need of all twins (`Index::twins()`).

#### Reuse

An image Optimize capped and a template later shows larger:

1. The render of a capped image evaluates its `sizes` (or `max`, or `|best_src(w)`). Above the cap, it records the new need, as `Index::want()` does for a crop: one row, the first time.
2. The worker raises the cap, builds the missing widths, JPEG fallbacks included, and `timber_avif/changed` purges the pages that show the image.
3. Meanwhile the page serves the capped set, slightly upscaled, still in the modern format.

On an image Optimize never capped, the render does nothing more than today.

#### Evaluating `sizes`

Evaluating `sizes` in the browser's own way needs no interval arithmetic:

1. Sample viewport widths from 320 to 2560 CSS px.
2. For each, take the first entry whose media condition matches.
3. Evaluate its length.
4. The need is the largest result, times the density cap.

Only a small subset of the syntax is needed:

- **Conditions:** `min-width` and `max-width` in px, rem or em, joined by `and`.
- **Lengths:** px, rem, em and vw, plus `calc()` with `+`, `-`, `*` and `/`.
- **`auto`** is skipped: the list after it is the fallback.

Anything else counts as full width, so a `sizes` the evaluator cannot read keeps every file.

Two examples from Thinkwater:

| `sizes` | Widest slot | At 2x | Need |
|---|---|---|---|
| `(min-width: 64rem) 380px, (min-width: 40rem) 48vw, calc(100vw - 3rem)` (event cards) | 591 px, the phone below 640 px | 1182 | 1280 |
| `(min-width: 102.5rem) 784px, (min-width: 64rem) 50vw, calc(100vw - 3rem)` (split blocks) | 975 px, below 1024 px | 1950 | 2560 / full |

The second case shows why a "small" image is not always small: the widest slot is on a tablet, not on desktop.

The same evaluator serves the report and the render of a capped image, so it is written once, in PHP, with the `sizes` of the five themes as its tests.

#### Scenarios to work through

| Scenario | Expected behaviour | To find out |
|---|---|---|
| Optimize on a fully converted site | Files beyond the need, unused images and orphans removed | Space reclaimed on Mobilissimo and Dalmec |
| An image in a card is later used in a hero | The render records a need above the cap, the widths are built, the page is purged | How long the first views stay on the capped set |
| An image no content referenced is placed | Its first render records a need, the worker builds it | Its first views are JPEG, from WordPress's sizes |
| Template redesign: `sizes` grows | Capped images raise their cap on the next render | Purge churn right after a deploy |
| Template redesign: `sizes` shrinks | Nothing is removed until Optimize runs again | — |
| Page cache (WP Rocket) | A cache hit records nothing; the render after the purge does | — |
| Content images (`the_content`, ACF WYSIWYG) | WordPress's `sizes` is usually the full width: everything kept | Whether to read the layout width instead |
| Images placed by PHP queries | Kept, unless the theme declares them | — |
| Import of hundreds of images (Dalmec's sync) | New images have no cap: converted in full, as today | — |
| Settings change (widths, quality) | Capped images get only the widths up to their cap | — |
| Staging → production | Caps live in post meta, and move with the database | Files must move with `uploads`, as today |
| Feeds, REST API, emails | WordPress's sizes stay, so external links keep working | — |

#### Risks

- **A place the tool misses.** Its image can lose widths it is shown at. The page keeps working on the capped set until the render raises the cap. The report lists what it could not read, and the dry run comes first.
- **More page-cache purges** after a deploy that grows a `sizes`. The existing debounce covers it; measure.
- **A misread `sizes`** can only mean more files kept, never fewer: anything unparsed counts as full width.
- **Deleting files that something outside the site links to**, which is why WordPress's own sizes stay.

## Not planned

- **Making files only when a template asks for them.** Proposed in September 2026 (ROADMAP.md at v7.0.5): the worker would have built only the widths a render recorded. 7.0's pipeline stays as it is, and Optimize removes the excess afterwards.
- **Parallel workers.** Two or three workers claiming attachments one by one, for multi-core servers. Set aside: the worker carrying on by itself comes first.
- **Queuing translations again when their file is replaced.** Dalmec's sync wrote the translations' metadata with `update_post_meta()`; it now uses `wp_update_attachment_metadata()`. WPML itself only copies metadata into translations that have none. Revisit if another tool writes translation metadata directly.
