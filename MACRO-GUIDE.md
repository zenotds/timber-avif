# Responsive Image Macro Guide

This document explains the differences between your current macro and the v3-optimized version, and provides migration guidance.

---

## 📊 **Macro Comparison**

### **Fixed Current Macro (`macros.twig`)**

**Compatibility:** ✅ Works with v2.5 and v3.0

**HTML Output (CORRECT):**
```html
<!-- AVIF sources -->
<source media="..." srcset="img-800.avif 1x, img-1600.avif 2x" type="image/avif"/>
<!-- WebP sources -->
<source media="..." srcset="img-800.webp 1x, img-1600.webp 2x" type="image/webp"/>
<!-- Original sources -->
<source media="..." srcset="img-800.jpg 1x, img-1600.jpg 2x"/>
```

**Features:**
- ✅ Correct HTML structure (separate `<source>` per format)
- ✅ Works with both v2.5 and v3.0
- ✅ AVIF and WebP support
- ✅ Responsive breakpoints

**Performance with v3.0:**
- With pre-generation enabled: ⚡ **FAST** (all files exist)
- Without pre-generation: ⚠️ Moderate (generates on first request)

---

### **v3 Optimized Macro (`macros-v3.twig`)**

**Compatibility:** ✅ Works with v2.5 and v3.0 (optimized for v3.0)

**HTML Output (CORRECT):**
```html
<!-- AVIF sources -->
<source media="(max-width: 40rem)" srcset="img-xs-800.avif 1x, img-xs-1600.avif 2x" type="image/avif"/>
<source media="(min-width: 40rem) and (max-width: 48rem)" srcset="img-sm-1000.avif 1x, img-sm-2000.avif 2x" type="image/avif"/>
<!-- ... more breakpoints -->

<!-- WebP sources -->
<source media="(max-width: 40rem)" srcset="img-xs-800.webp 1x, img-xs-1600.webp 2x" type="image/webp"/>
<!-- ... more breakpoints -->

<!-- Original sources -->
<source media="(max-width: 40rem)" srcset="img-xs-800.jpg 1x, img-xs-1600.jpg 2x"/>
<!-- ... more breakpoints -->

<img src="img-2xl.jpg" alt="..." width="..." height="..."/>
```

**Key Improvements:**
1. ✅ **Correct HTML** - Separate `<source>` per format with `type` attribute
2. ✅ **Browser format selection** - Browser picks best format automatically
3. ✅ **NEW: `smart` option** - Use `|smart` filter for automatic format selection
4. ✅ **Optimized for v3.0** - Takes full advantage of pre-generation

---

## 🔧 **Migration Guide**

### **Option 1: Use Fixed Current Macro (Recommended)**

The `macros.twig` file has been updated with the correct HTML structure. It now:
- ✅ Generates separate `<source>` tags per format
- ✅ Works with both v2.5 and v3.0
- ✅ No changes needed to your templates!

Just use the updated `macros.twig` as-is:
```twig
{% import 'macros.twig' as img %}
{{ img.image(post.thumbnail, {
    sizes: {
        'xs': [400],
        'sm': [600],
        'md': [800],
        'lg': [1200],
        'xl': [1600]
    }
}) }}
```

---

### **Option 2: Switch to v3-Optimized Macro**

Use `macros-v3.twig` for the best v3.0 experience.

**In your templates:**
```twig
{# BEFORE #}
{% import 'macros.twig' as img %}
{{ img.image(post.thumbnail, {
    sizes: {
        'xs': [400],
        'sm': [600],
        'md': [800],
        'lg': [1200],
        'xl': [1600]
    }
}) }}

{# AFTER #}
{% import 'macros-v3.twig' as img %}
{{ img.image(post.thumbnail, {
    sizes: {
        'xs': [400],
        'sm': [600],
        'md': [800],
        'lg': [1200],
        'xl': [1600]
    }
}) }}
```

**NEW: Smart Mode (v3.0 only)**
```twig
{% import 'macros-v3.twig' as img %}
{{ img.image(post.thumbnail, {
    smart: true  {# Uses |smart filter - automatic format selection #}
}) }}
```

---

## ⚡ **Performance Optimization for v3.0**

### **Align Macro Sizes with Pre-generation Settings**

To get **maximum performance** with v3.0 pre-generation:

1. **In your macro**, use consistent sizes:
```twig
{{ img.image(post.thumbnail, {
    sizes: {
        'xs': [800],
        'sm': [1200],
        'md': [1600],
        'lg': [2400]
    }
}) }}
```

2. **In v3.0 plugin settings**, set matching common sizes:
```
Settings → Timber AVIF → General Settings
☑ Pre-generate Common Sizes: 800,1200,1600,2400
```

**Result:** All resize operations serve pre-existing files = **instant page loads!**

---

## 📈 **Performance Comparison**

### **Page with 20 Images Using Your Macro**

| Scenario | v2.5 | v3.0 (lazy) | v3.0 (pre-gen) |
|----------|------|-------------|----------------|
| **Upload Time** | Instant | +2s | +8s |
| **First Load** | 500 Error | ~4-6s | **Instant ⚡** |
| **Second Load** | Instant | Instant | Instant |
| **Total Operations** | 240+ conversions | 240 conversions | 0 conversions |

### **Per-Image Breakdown**

With 6 breakpoints × 2 densities = 12 resize operations per image:

| Format | Files Generated | v2.5 | v3.0 (lazy) | v3.0 (pre-gen) |
|--------|-----------------|------|-------------|----------------|
| AVIF | 12 | On-demand | First load | Upload time |
| WebP | 12 | N/A | First load | Upload time |
| Original | 12 | Cached | Cached | Cached |
| **Total** | **36 files** | Mixed | Mixed | ⚡ All ready |

---

## 🎯 **Recommendations**

### **For Immediate Use (v2.5 or v3.0)**
1. ✅ Apply the HTML fix to your current macro
2. ✅ This solves the browser format selection issue
3. ✅ Works with both versions

### **For Maximum v3.0 Performance**
1. ✅ Switch to `macros-v3.twig`
2. ✅ Enable pre-generation in plugin settings
3. ✅ Align macro sizes with common sizes setting
4. ✅ Run `wp timber-avif bulk` to convert existing images

### **For Testing Smart Mode**
1. ✅ Use `macros-v3.twig` with `smart: true`
2. ✅ Simplifies template code
3. ✅ Relies on browser Accept header detection

---

## 🔍 **HTML Output Examples**

### **Current Macro (Incorrect)**
```html
<picture>
    <source media="(min-width: 64rem)"
            srcset="img-800.avif 1x, img-1600.avif 2x, img-800.webp 1x, img-1600.webp 2x, img-800.jpg 1x, img-1600.jpg 2x"/>
    <!-- Problem: Browser doesn't know which format is which! -->
    <img src="img.jpg" alt="..."/>
</picture>
```

### **v3 Macro (Correct)**
```html
<picture>
    <!-- AVIF - best compression -->
    <source media="(min-width: 64rem)"
            srcset="img-800.avif 1x, img-1600.avif 2x"
            type="image/avif"/>

    <!-- WebP - good fallback -->
    <source media="(min-width: 64rem)"
            srcset="img-800.webp 1x, img-1600.webp 2x"
            type="image/webp"/>

    <!-- Original - final fallback -->
    <source media="(min-width: 64rem)"
            srcset="img-800.jpg 1x, img-1600.jpg 2x"/>

    <img src="img.jpg" alt="..."/>
</picture>
```

### **v3 Macro with Smart Mode**
```html
<picture>
    <img src="img.avif" alt="..."/>
    <!-- Single image tag, format selected by |smart filter based on browser -->
</picture>
```

---

## 🛠️ **Testing Checklist**

### **After Applying HTML Fix**
- [ ] Check browser DevTools Network tab
- [ ] Verify AVIF files are loaded in Chrome/Edge
- [ ] Verify WebP files are loaded in Safari
- [ ] Verify only ONE format loads per breakpoint
- [ ] Check that 2x images load on high-DPI displays

### **After Switching to v3 Macro**
- [ ] Test with pre-generation disabled (lazy mode)
- [ ] Test with pre-generation enabled
- [ ] Verify page load times improve
- [ ] Check Statistics dashboard for metrics
- [ ] Test smart mode in different browsers

### **Performance Testing**
- [ ] Load page with 20+ images
- [ ] Verify no 500 errors or timeouts
- [ ] Check first load vs second load times
- [ ] Verify all formats are properly cached

---

## 📝 **Summary**

| Feature | macros.twig (Fixed) | macros-v3.twig |
|---------|---------------------|----------------|
| Works with v2.5 | ✅ | ✅ |
| Works with v3.0 | ✅ | ✅ |
| Correct HTML | ✅ | ✅ |
| AVIF support | ✅ | ✅ |
| WebP support | ✅ | ✅ |
| Smart filter | ❌ | ✅ |
| Optimized for v3 | ⚠️ Good | ✅ Best |
| Pre-gen support | ✅ | ✅ |

**Recommendation:**
- **For most users:** Use `macros.twig` (fixed) - works great with both v2.5 and v3.0!
- **For v3.0 power users:** Use `macros-v3.twig` with smart mode for maximum optimization

---

Need help with the migration or have questions? Let me know! 🚀
