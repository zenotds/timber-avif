# Migration Guide

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
