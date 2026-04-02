# Timber AVIF (v5.3.0)

Performance-first image optimization for Timber 2.x. This is a lightweight **theme drop-in** that adds AVIF/WebP support, background conversion, and a responsive Twig macro.

## Features
- **AVIF + WebP support** with tiered engines (GD > Imagick > CLI).
- **Hybrid Conversion:** Fast inline conversion (up to 10 per request) + Background queue (shutdown & WP-Cron).
- **Failure Awareness:** Remembers failed conversions for 24h to avoid wasteful retries.
- **Timber Integration:** Leverages native `|resize` and `|towebp`.
- **Twig Helpers:** `|toavif`, `|avif_src`, `|webp_src`, `|best_src`, `image.avif`, `image.webp`, `image.best`.
- **Responsive Macro:** Mobile-first `(min-width)`, 2x capping, and accessible alt/title fallbacks.
- **Admin UI:** Settings, Statistics, Tools, and detailed **Logs** (Settings > Timber AVIF).
- **WP-CLI:** `timber-avif detect`, `timber-avif bulk`, `timber-avif queue`, `timber-avif clear-cache`.

## Installation

1. Copy `avif.php` into your theme (e.g., `inc/avif.php`).
2. Require it in your `functions.php`:
   ```php
   require_once get_template_directory() . '/inc/avif.php';
   ```
3. Copy `macros.twig` into your Twig templates directory.

## Twig Usage

### Properties & Filters
```twig
{{ image.avif }}         {# AVIF URL or original #}
{{ image.webp }}         {# WebP URL or original #}
{{ image.best }}         {# Best available: AVIF > WebP > original #}

{{ image|toavif }}       {# Lookup/Convert to AVIF #}
{{ image|avif_src(800) }} {# Resize to 800px + Convert to AVIF #}
```

### Responsive Macro (`image`)
The `image` macro generates a `<picture>` element with optimized sources, automatic 2x (retina) support, and intelligent size cascading.

```twig
{% import "macros.twig" as macros %}
{{ macros.image(post.thumbnail, {
    sizes: {
        'xs': [400],
        'md': [800, 600],
        'xl': [1200]
    },
    imgClass: 'w-full h-auto',
    atf: true
}) }}
```

#### Parameters

| Option | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `sizes` | `hash` | `null` | Mapping of breakpoints to `[width, height]` arrays. See **Size Cascading** below. |
| `breakpoints` | `hash` | *(Tailwind defaults)* | Custom media query values (`xs` to `2xl`). |
| `pictureClass` | `string` | `''` | CSS class added to the `<picture>` wrapper. |
| `imgClass` | `string` | `object-contain...` | CSS class added to the `<img>` tag. |
| `atf` | `bool` | `false` | Above-The-Fold mode. If `true`, adds `fetchpriority="high"` and removes `loading="lazy"`. |
| `avif` | `bool` | `true` | Enable/disable AVIF generation/lookup for this instance. |
| `webp` | `bool` | `true` | Enable/disable WebP generation/lookup for this instance. |

#### Size Cascading
The `sizes` parameter uses a "carry-forward" logic. If you only define `xs` and `lg`, the `lg` dimensions will be used for `lg`, `xl`, and `2xl` automatically.
- `[400]`: Sets width to 400px, height is proportional.
- `[400, 300]`: Sets width to 400px and crops/resizes height to 300px.

#### 2x (Retina) Support
The macro automatically generates `1x` and `2x` descriptors for every breakpoint. To prevent quality loss, the `2x` variant is **capped** at the original image's dimensions—it will never upscale a small source image.

## Admin & Tools
Visit **Settings > Timber AVIF** to:
- Configure quality and conversion limits.
- View **Statistics** (space savings and progress).
- Run **Bulk Conversion** via AJAX.
- Process the **Background Queue** manually.
- Inspect **Logs** for skipped or failed conversions.

## WP-CLI
```bash
wp timber-avif detect     # Show available conversion engines
wp timber-avif bulk       # Process all Media Library images
wp timber-avif queue      # Show/Process background queue
wp timber-avif clear-cache # Flush capability caches
```

## Requirements
- PHP 8.3+
- WordPress 6.0+
- Timber 2.x
- GD (with AVIF/WebP), Imagick, or ImageMagick CLI

## License
MIT
