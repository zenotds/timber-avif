# Migration Guide

Upgrade paths for the new v3.0 theme drop-in and v4.0 plugin.

## From v2.5.x (theme file) → v3.0
1. Replace your old `avif.php` with the new one (require it once in `functions.php`).
2. Replace your responsive macro with `macros.twig` (or import the plugin copy).
3. Visit **Tools → Timber AVIF** to set quality, enable WebP, and optionally warm breakpoint widths.
4. Run a bulk conversion:
   ```bash
   wp timber-avif bulk --webp
   ```
5. Update templates to take advantage of the new helpers if you want:
   ```twig
   {{ image|avif_src(1200) }}
   {{ image.webp }}
   ```

## From old plugin attempts (v3/v4 beta) → v4.0
1. Remove the previous `timber-avif-plugin` directory.
2. Copy the new `/timber-avif/` folder into `/wp-content/plugins/` and activate it.
3. The plugin shares settings with the theme version; revisit **Tools → Timber AVIF** to confirm.
4. Replace any custom macro copies with `timber-avif/macros/picture.twig`.

## Notes
- The converter now pre-generates AVIF/WebP on upload and stores variant metadata so `image.avif` and `|avif_src` can reuse existing files.
- Capability detection is cached for a week; use `wp timber-avif clear-cache` after changing server libraries.
