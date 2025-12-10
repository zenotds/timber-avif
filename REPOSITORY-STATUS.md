# Repository Status

**Last Updated:** 2025-12-10

## Current State

Both v2.5 (stable) and v3.0 (WIP) are now in the **main** branch after merging from v3-plugin.

## Repository Structure

```
timber-avif/
├── avif.php                    # v2.5 - Production-ready theme file
├── macros.twig                 # Responsive image macro for v2.5
├── macros-v3.twig              # Optimized macro for v3.0 (when working)
├── MACRO-GUIDE.md              # Migration guide between macros
├── README.md                   # Main documentation
├── LICENSE                     # MIT License
└── timber-avif-plugin/         # v3.0 WordPress Plugin (WIP)
    ├── timber-avif.php         # Main plugin file
    ├── README.md               # Plugin documentation
    ├── includes/
    │   ├── class-converter.php # Core conversion logic (1000+ lines)
    │   └── class-admin.php     # Admin interface (500+ lines)
    └── admin/
        ├── style.css           # Admin UI styles
        └── script.js           # Admin UI interactions
```

## Version Status

### v2.5 - ✅ Production Ready
**File:** `avif.php`
**Status:** Stable, recommended for production use

**Features:**
- ✅ On-the-fly AVIF conversion
- ✅ Multiple conversion methods (GD, ImageMagick, CLI)
- ✅ Non-blocking lock handling
- ✅ Stale lock cleanup
- ✅ File size comparison (only keeps if smaller)
- ✅ Auto-conversion on upload (optional)
- ✅ WP-CLI bulk conversion
- ✅ Structured logging with severity levels
- ✅ Works with `macros.twig` for responsive images

**Usage:**
```php
// In theme's functions.php
require_once get_template_directory() . '/inc/avif.php';
```

```twig
{# In Twig templates #}
{% import 'macros.twig' as img %}
{{ img.image(post.thumbnail, {
    sizes: {
        'xs': [800],
        'sm': [1200],
        'md': [1600],
        'lg': [2400]
    }
}) }}
```

---

### v3.0 - ⚠️ Work In Progress
**Directory:** `timber-avif-plugin/`
**Status:** WIP - Has Twig filter registration conflicts

**What Works:**
- ✅ Auto-conversion on upload
- ✅ Pre-generation of common sizes
- ✅ Admin UI with settings, statistics, tools
- ✅ WebP generation alongside AVIF
- ✅ WP-CLI commands with stats
- ✅ Backend conversion engine
- ✅ Statistics tracking and dashboard

**What Doesn't Work:**
- ❌ **Twig filter registration** - Cannot register `|toavif`, `|towebp`, `|smart` filters
- ❌ Theme's custom Twig code initializes extensions before plugin can register filters
- ❌ Error: `LogicException: Unable to add filter as extensions have already been initialized`

**Root Cause:**
WordPress hook timing issue where theme's `functions/twig.php` custom filters initialize Twig extensions before the plugin's `timber/twig` filter can execute. This locks the Twig environment, preventing any further filter registration.

**Potential Solutions (Not Implemented):**
1. Use differently named filters (e.g., `toavif_v3`, `smart_format`)
2. Create dedicated v3 macro that uses these new filter names
3. Implement custom Twig Extension class instead of using filter hooks
4. Document that v3.0 requires theme modifications to work
5. Change approach to not rely on Twig filters at all

**Recommendation:** Use v2.5 for production until this is resolved.

---

## Git Branch Structure

### `main` Branch
Contains both v2.5 and v3.0 (WIP):
- v2.5 stable files (`avif.php`, `macros.twig`)
- v3.0 plugin directory (`timber-avif-plugin/`)
- Documentation for both versions
- Macro migration guide

### `v3-plugin` Branch
Historical development branch for v3.0. Now merged into main. All v3 development history is preserved in the merge commit.

---

## Git History

All commits have been cleaned to:
- ✅ Remove "Co-Authored-By: Claude" notices
- ✅ Use email: zenotds@mac.com
- ✅ Maintain chronological development history
- ✅ Include comprehensive commit messages

---

## For Future Development

When revisiting v3.0 plugin to fix Twig filter conflicts:

### Investigation Needed:
1. Document exact WordPress/Timber/Twig initialization order
2. Identify which hook fires before Twig extensions lock
3. Test if custom Extension class can register later than filters

### Implementation Options:
1. **Option A: Rename Filters**
   - Use `|toavif_v3`, `|smart_format`, etc.
   - Create new `macros-v3.twig` that uses these names
   - No conflicts with theme's existing filters

2. **Option B: Custom Twig Extension**
   - Implement `Timber_AVIF_Extension extends AbstractExtension`
   - Register via different mechanism
   - May bypass filter registration timing issue

3. **Option C: Theme Integration**
   - Document that v3.0 requires theme's `twig.php` to be modified
   - Provide template code for theme integration
   - Less portable but guaranteed to work

4. **Option D: No Filters Approach**
   - Plugin only handles backend (upload, resize hooks)
   - Dedicated v3 macro directly calls PHP functions
   - Most reliable but requires macro changes

---

## Testing Checklist for v3.0 Fix

When testing a v3.0 fix:

- [ ] Plugin activates without errors
- [ ] Twig filters register successfully (`|toavif`, `|smart`)
- [ ] No conflicts with theme's custom Twig filters
- [ ] Upload conversion works (AVIF + WebP generated)
- [ ] Pre-generation creates common sizes
- [ ] Timber resize hook triggers conversions
- [ ] Admin settings page loads without errors
- [ ] Statistics dashboard displays correctly
- [ ] Bulk conversion UI works with progress
- [ ] WP-CLI commands execute successfully
- [ ] Templates using `macros-v3.twig` render properly
- [ ] Page with 20+ images loads without timeout
- [ ] Browser receives correct format (AVIF in Chrome, WebP in Safari)

---

## Documentation Files

- **README.md** - Main repository documentation with both v2.5 and v3.0 info
- **timber-avif-plugin/README.md** - Plugin-specific documentation
- **MACRO-GUIDE.md** - Migration guide between `macros.twig` and `macros-v3.twig`
- **REPOSITORY-STATUS.md** - This file, comprehensive status overview

---

## Quick Start

### For Production Use (v2.5):
1. Copy `avif.php` to your theme's `/inc/` directory
2. Require it in `functions.php`: `require_once get_template_directory() . '/inc/avif.php';`
3. Copy `macros.twig` to your theme's `/views/` or `/templates/` directory
4. Use in templates: `{% import 'macros.twig' as img %}`

### For Testing v3.0 (Not Recommended):
1. Copy `timber-avif-plugin/` to `wp-content/plugins/`
2. Rename to `timber-avif`
3. Activate in WordPress admin
4. Expect Twig filter registration errors if theme has custom filters

---

## Support & Issues

**GitHub Repository:** https://github.com/zenotds/timber-avif
**License:** MIT

---

**Note:** This document reflects the repository state after merging v3-plugin branch into main. Both versions coexist in main branch with clear documentation about their respective statuses.
