# Timber AVIF Converter (Plugin)

This plugin bundles the Timber AVIF converter so you can enable AVIF/WebP support without touching the theme. See the root docs for full details:

- [README-PLUGIN.md](../README-PLUGIN.md) for plugin usage
- [README-THEME.md](../README-THEME.md) for the shared converter API

## Quick start
1. Copy this folder to `/wp-content/plugins/` and activate it.
2. Import the macro from `timber-avif/macros/picture.twig` if desired.
3. Configure options under Settings > Timber AVIF.

The plugin shares settings and behaviour with the theme drop-in (upload-time generation, Twig helpers, WP-CLI commands).
