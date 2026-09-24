# Roadmap

Proposals, not commitments. Nothing here is scheduled; each item says what it is for and what has to be found out before it is built.

## 7.1 — Generate what is used

**Status:** proposal, September 2026. Written after moving five sites from v6 to v7.

### Goal

Save conversion time and disk space by generating each image only at the widths the site actually shows it at, and by removing what nothing shows.

### What 7.0 does today

- **At upload**, WordPress writes every canonical width below the image's own as a JPEG sub-size (`tavif-320` … `tavif-2560`), because they are registered image sizes, next to its own thumbnail and medium.
- **The worker** writes a modern copy of every candidate below the image's own width, plus the full-size file. Crops and widths below every configured one are made only when a template asks for them (`Index::want()`).
- **Where the image will be shown plays no part.** A photo used only in a card gets the same files as a hero.

Measured cost for one 2560×1707 photo, on the production setup (Imagick at one thread for JPEG, GD for AVIF), timed on an i5-9600K:

| Step | Time |
|---|---|
| Canonical JPEG sizes (only for images uploaded before v7; otherwise part of the upload request) | 4.0 s |
| AVIF through GD: load 0.27 s, 9 resizes 1.35 s, 9 encodes 1.47 s | 3.1 s |

Widths from 1600 up take 46% of the AVIF time. The 2560 encode alone takes 0.41 s of the 1.47 s.

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

Three things stand out:

1. **The full-size AVIF is a third of all AVIF bytes.** Every image gets one, whatever it is used for.
2. **The JPEG fallbacks weigh more than the AVIF copies** (848 MB against 611 MB), and only the few browsers without AVIF download them.
3. **Widths from 1600 up, full size included, are 51% of the AVIF bytes** and 23% of the JPEG ones.

### Principle

What an image is needed for cannot be known at upload. An editor uploads five images, then uses one as a header, two in cards and two in a gallery.

It is known at render. The template call says the context: `sizes`, `ratio`, `max`. v7 already works this way for crops and small widths: the render records the request, the worker builds the files, and `timber_avif/changed` purges the pages that show the image. The proposal is to treat every width that way.

### Design

**1. What a render records.** For each attachment it records the largest width needed, uncropped and per ratio.

- It is computed from `sizes` (see *Evaluating `sizes`*), multiplied by a density cap, and rounded up to the next configured width.
- `max`, when given, caps it.
- `|best_src(w)` records `w`.
- A content image records the need from the `sizes` attribute WordPress writes on it.

The render writes only when the need grows. Post meta is already loaded with the attachment, so comparing costs nothing, and the write happens once per image per new context.

The rows would be append-only, like `_tavif_want`, so a render never races the worker writing the index; the worker folds them into the index.

**2. The worker.** It builds only the configured widths up to the need, and the full size only when the need reaches it. Needs recorded by a render come first in the queue, because a page is waiting for them; new uploads come next.

**3. The renderer.** The `srcset` lists the widths up to the need. The all-or-nothing rule applies to that set. Until it is complete, the page serves the fallback files that exist, as it does today.

**4. The upload (second phase).** Stop writing the canonical JPEG sizes at upload: drop `tavif-*` in `intermediate_image_sizes_advanced`. The worker then makes each JPEG width when it is needed, as it already does for a crop's fallback. Uploads get faster, and no unused JPEGs are written.

  To keep the first view of an image in a new context from falling back on the full-size file, one mid-size JPEG (1024, say) could still be written at upload.

**5. Density cap.** Needs are computed at 2x by default, as a setting. A 3x phone then gets the 2x file, slightly upscaled. On photos it does not show, and it is the difference between 1280 and 1920 for a typical card.

### Evaluating `sizes`

Evaluating `sizes` in the browser's own way needs no interval arithmetic:

1. Sample viewport widths from 320 to 2560 CSS px.
2. For each, take the first entry whose media condition matches.
3. Evaluate its length.
4. The need is the largest result, times the density cap.

Only a small subset of the syntax is needed:

- **Conditions:** `min-width` and `max-width` in px, rem or em, joined by `and`.
- **Lengths:** px, rem, em and vw, plus `calc()` with `+`, `-`, `*` and `/`.
- **`auto`** is skipped: the list after it is the fallback.

Anything else counts as full width, which is today's behaviour, so a `sizes` the evaluator cannot read costs nothing compared with now.

Two examples from Thinkwater:

| `sizes` | Widest slot | At 2x | Need |
|---|---|---|---|
| `(min-width: 64rem) 380px, (min-width: 40rem) 48vw, calc(100vw - 3rem)` (event cards) | 591 px, the phone below 640 px | 1182 | 1280 |
| `(min-width: 102.5rem) 784px, (min-width: 64rem) 50vw, calc(100vw - 3rem)` (split blocks) | 975 px, below 1024 px | 1950 | 2560 / full |

The second case shows why a "small" image is not always small: the widest slot is on a tablet, not on desktop.

### The Optimize tool

**Analyse (read-only).**

- **Crawl:** go through every public URL (sitemap, then permalinks, every WPML language, paginated archives) with loopback requests, so every image's need is recorded.
- **Report:**
  - files beyond the need;
  - images no page shows;
  - orphans: files no attachment owns, v6 and Timber leftovers;
  - WordPress sizes nobody serves (`medium_large`, `1536x1536`, `2048x2048` on sites that never removed them).

The same crawl is also phase 0: it collects the numbers below.

**Optimize.**

- Delete what the report lists, dry run by default, then purge the page cache.
- The same pattern as *Delete conversions* and *Remove v6 files*: a cached page pointing at a deleted AVIF shows a broken image.
- CLI: `wp timber-avif analyse`, `wp timber-avif optimize [--dry-run]`.

**What the tool never deletes by default:**

- originals and `-scaled` files;
- the sizes WordPress lists for an attachment. `og:image` from Yoast, newsletters and other sites may link them directly.

**"Last seen" (optional, later).** A per-image timestamp, updated at most once a day on render, would let images unseen for N days be pruned without a crawl. It means a front-end write per image per day, so the crawl is probably enough.

### Scenarios to work through

| Scenario | Expected behaviour | To find out |
|---|---|---|
| Five uploads: one hero, two cards, two gallery images | Hero gets every width, cards up to 1280, gallery what its `sizes` says | — |
| An image in a card is later used in a hero | The need grows, the missing widths are built, the page is purged | How long the first views stay in JPEG; whether a mid-size upload fallback is needed |
| An image no page uses | Nothing but thumbnail and medium | Share of such images on real sites (crawl) |
| Import of hundreds of images (Dalmec's sync) | The import writes almost nothing; work starts when pages are visited | Whether the first crawl after a big import should be triggered |
| Template redesign: `sizes` grows | Needs grow on the next render | Purge churn right after a deploy |
| Template redesign: `sizes` shrinks | Nothing is removed until Optimize runs | — |
| Page cache (WP Rocket) and its preload | The preload renders the pages, so it records the needs | A cache hit records nothing: is the cache-miss render enough? |
| CDN caching HTML | As today: purge it by hand after Optimize | — |
| Staging → production | Needs live in post meta, and move with the database | Files must move with `uploads`, as today |
| WPML translations sharing one file | Needs of all twins combined: the files are shared | Where to store the union |
| Content images (`the_content`, ACF WYSIWYG) | WordPress's `sizes` is usually the full width | Whether to read `auto` and the layout width instead |
| `\|best_src` (posters, backgrounds, placeholders) | The requested width is the need | — |
| Settings change (quality, widths) | Only the needed widths are re-encoded | — |
| 3x phones | 2x files, slightly upscaled | Whether any site needs 3x (the setting) |
| Sites already fully converted on 7.0 | Nothing changes until Analyse and Optimize run | Space reclaimed on Mobilissimo and Dalmec |
| Drafts, previews, pages behind a login | A preview records needs; a draft never published records none | Whether the crawl should include logged-in pages |
| Feeds, REST API, emails | A width linked from outside is not a need the site knows | Keep WordPress sizes out of Optimize (above) |

### Numbers to collect first

With the Analyse crawl, on Mobilissimo (fully converted) and Dalmec (the largest library):

- the share of attachments no page shows;
- the distribution of needs by width;
- the disk space and conversion time Optimize would reclaim;
- how often needs grow in the weeks after the first crawl, which is page-cache churn.

### Risks

- **The first view in a new context is JPEG**, and may fall back on a heavy file until the worker is done. Measure it; keep a mid-size JPEG at upload if needed.
- **More page-cache purges**, as needs grow over time. The existing debounce covers it; measure.
- **Front-end writes** on the first render of each image. It happens once per image per context, as crops do today.
- **A misread `sizes`** can only mean more files than needed, never fewer: anything unparsed counts as full width.
- **Deleting files that something outside the site links to**, which is why WordPress's own sizes stay by default.

### Phases

0. **Measure:** the Analyse crawl, read-only.
1. **Needs and a worker capped by them**, for the modern copies only. The JPEG sizes are still written at upload. This saves AVIF time and space.
2. **JPEG widths on demand**, no longer written at upload. Consider fewer fallback widths for the `<img>`, since so few browsers use them.
3. **Optimize:** delete files beyond the need, images never shown, orphans.
4. **Optional:** last-seen pruning.

## Also for 7.1

- **The worker carries on without traffic.** At the end of a pass that leaves work pending, the worker starts the next pass itself, through a non-blocking loopback request, instead of waiting for a visitor to trigger WP-Cron. A B2B site at night is otherwise idle for hours.
- **Optional parallel workers.** A setting for 2–3 concurrent workers, claiming attachments one by one instead of holding the site-wide lock. With Imagick held to one thread, a multi-core server converts 2–3 times faster; the default stays at one, since shared hosts may not like the load.
- **Process now.** Show the remaining count while another worker holds the lock. Don't schedule a WP-Cron pass while the button is running, so the two stop alternating.

## Not planned

- **Queuing translations again when their file is replaced.** Dalmec's sync wrote the translations' metadata with `update_post_meta()`; it now uses `wp_update_attachment_metadata()`. WPML itself only copies metadata into translations that have none. Revisit if another tool writes translation metadata directly.
