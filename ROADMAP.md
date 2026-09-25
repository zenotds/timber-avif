# Roadmap

Proposals, not commitments. Each item says what it is for and what has to be found out before it is built.

## 7.1 — done

Planned in September 2026, after moving five sites from v6 to v7, and built as planned. 7.0's way of making files stays: the upload writes the canonical JPEG sizes, the worker converts the whole library. The README describes what 7.1 added.

1. **The v6 code is gone**: `Migration\V6`, prepare mode, *Remove v6 files*, `wp timber-avif prepare` and `purge-v6`, `MIGRATION.md`, and the 7.0.3 re-encode for sites upgrading with GD.
2. **The worker carries on by itself**: a pass that leaves work pending starts the next one with a loopback request (`Worker::relay()`). *Process now* drives the queue alone while it runs, and the Library and Queue cards follow it without a reload.
3. **Optimize** reads where each image is placed (`Optimize\Usage`) and how the Twig shows those places (`Optimize\Templates`), and removes the widths past what each image needs, rounded up to a configured width and never below 1024. The width is kept as the image's cap (`Index::caps()`), which the worker and the renderer both respect. A render that shows a capped image wider records the need (`Index::need()`), and the worker raises the cap and builds what is missing. Orphans go too.

On the templates of the five sites, the Twig reading follows every image call to the field it shows — 21 field and ratio pairs on Dalmec, 18 on Thinkwater, 26 on Mobilissimo, 21 on Saip, 23 on Dalsanto — except four calls in page headers that some templates include without passing an image (three on Dalsanto, one on Dalmec). The report lists those.

### What the first analyses showed

Run on the local copies of the sites, September 25th (7.1.1):

| Site | Images | Limited | Used nowhere | Keep every file | To delete |
|---|---|---|---|---|---|
| Mobilissimo | 1,648 | 236 | 158 | 1,254 | 717 files, 116 MB |
| Saip | 274 | 24 | 103 | 147 | 528 files, 118 MB |

Most of it is images no content uses: 102 MB and 115 MB. On Mobilissimo 1,030 images are needed whole, legitimately: the product gallery is a `100vw` carousel below 1024 px and 71vw on large screens. Dalmec's local library is mostly unconverted, so it has nothing to delete yet.

The same runs found what 7.1.0 missed: Yoast options that are plain strings (a fatal error), `Timber::$dirname` as a list, fields of cloned modules (the key ACF stores belongs to the cloned group), values of removed rows, WPML's `referenced_media_ids`, and copies of files the local database no longer knows.

### To find out on the real sites

- **The dry run's numbers** on production, where the libraries are converted: Dalmec's above all.
- **Loopbacks on staging.** Sites behind HTTP authentication cannot reach themselves: the Queue card says so. Production should be checked once after the deploy.
- **Purges after a deploy that widens a `sizes`.** Each capped image shown wider is purged once its widths are built; the existing debounce covers a burst, but it is worth watching on the first redesign.

## Later

- **Last-seen pruning.** A per-image timestamp, updated at most once a day on render, would find images no page shows without reading the templates. It means a front-end write per image per day; Optimize is probably enough.
- **Parallel workers.** Two or three workers claiming attachments one by one, for multi-core servers. Set aside: the worker carrying on by itself came first, and shared hosts may not like the load.

## Not planned

- **Making files only when a template asks for them.** Proposed in September 2026 (ROADMAP.md at v7.0.5): the worker would have built only the widths a render recorded. 7.0's pipeline stays as it is, and Optimize removes the excess afterwards.
- **Queuing translations again when their file is replaced.** Dalmec's sync wrote the translations' metadata with `update_post_meta()`; it now uses `wp_update_attachment_metadata()`. WPML itself only copies metadata into translations that have none. Revisit if another tool writes translation metadata directly.
