# Migration Guide

## From v3.0 to v4.0

### Converter (`avif.php`)
1. Replace your `avif.php` with the new one.
2. New features are automatic — no code changes needed:
   - Statistics tab appears in Settings > Timber AVIF
   - AJAX bulk convert replaces the old synchronous one
   - "Optimized" column appears in the Media library

### Macro (`macros.twig`)
1. Replace your macro file with the new one.
2. Breaking change: the `ximage` macro has been removed. If you were using it, switch to `image` (same API).
3. Behavioral changes (no template edits required):
   - Media queries now use `(min-width)` only, ordered largest to smallest (fixes overlap at exact breakpoints)
   - 2x srcset is capped at original image dimensions (avoids requesting sizes larger than the source)
   - `alt` attribute uses `image.alt` with `image.title` fallback (proper accessibility field)

### Plugin
1. Replace the `/timber-avif/` folder with the new one.
2. The plugin version is now 4.0.0 (aligned with the theme drop-in).

## From v2.5.x to v3.0+
1. Replace your old `avif.php` with the new one (require it once in `functions.php`).
2. Replace your responsive macro with `macros.twig` (or import the plugin copy).
3. Visit **Settings > Timber AVIF** to set quality, enable WebP, and optionally warm breakpoint widths.
4. Run a bulk conversion:
   ```bash
   wp timber-avif bulk --webp
   ```
5. Update templates to take advantage of the new helpers:
   ```twig
   {{ image|avif_src(1200) }}
   {{ image.webp }}
   ```

## Notes
- The converter stores variant metadata in `_timber_variants` post meta so `image.avif` and `|avif_src` can reuse existing files.
- Capability detection is cached for a week; use `wp timber-avif clear-cache` after changing server libraries.
- Settings are shared between theme and plugin via the `timber_avif_settings` option.
