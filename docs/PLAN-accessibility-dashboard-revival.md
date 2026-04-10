# Implementation Plan: Revive Accessibility Dashboard

**Date:** 2026-03-28
**Source:** Caleb's PR #30 (commits `537f679`, `47c51ca`) + refactoring commit `9e10c6b`
**Target branch:** `develop` (current HEAD: `491b2ad`)

---

## Status Audit — What's Already Done vs What Remains

### Already Restored (from earlier session)

| Item | File | Status |
|------|------|--------|
| `document.body.classList.add('tutorial-active')` | `split-guide.js` line 3 | Done |
| `window.aeShortcuts` keyboard listener (JS side) | `split-guide.js` ~line 670 | Done |
| `aria-label="Tutorial Frame"` on dynamic iframe | `split-guide.js` ~line 474 | Done |
| `aria-label="H5P Frame"` on static iframe | `split-guide-template.php` line 258 | Done |
| `aria-label="Tutorial Frame"` on static iframe | `split-guide-template.php` line 297 | Done |

### Still Missing — Must Implement

| # | Item | Source |
|---|------|--------|
| 1 | **Accessibility dashboard subdirectory** (7 files) | Commit `9e10c6b` |
| 2 | **`require_once` in pb-split-guide.php** | Commit `9e10c6b` line 21 |
| 3 | **`text-decoration: underline` on "Open in new window" link** | Commit `9e10c6b` template diff |
| 4 | **Split-guide.css theming changes** (remove hardcoded colors to inherit admin theme) | Commit `9e10c6b` CSS diff |
| 5 | **Analytics dashboard theming** (CSS custom properties use `--gots-color-*` fallbacks) | Commit `9e10c6b` analytics diff |

---

## Execution Plan

### Phase 1: Extract accessibility-dashboard subdirectory from `9e10c6b`

Create the directory `web/app/plugins/pb-split-guide/accessibility-dashboard/` and extract all 7 files:

```
accessibility-dashboard/
├── README.md
├── class-pbsg-accessibility-dashboard.php   (621 lines — the core)
├── assets/
│   ├── accessibility-dashboard.js           (121 lines — skip link, keyboard tracking, TOC a11y)
│   └── accessibility-dashboard-profile.js   (76 lines — live preview on profile page)
└── styles/
    ├── profile.css                          (186 lines — profile settings UI)
    ├── admin-colors-upei.css                (915 lines — UPEI Library admin color scheme)
    └── admin-colors-colorblind.css          (921 lines — Blue/Orange deuteranopia-safe scheme)
```

**Command:** `git checkout 9e10c6b -- web/app/plugins/pb-split-guide/accessibility-dashboard/`

This is a clean extraction — these files don't exist on develop, so there are zero conflicts.

**What the PHP class does (feature inventory):**

1. **Admin Color Schemes** — Registers two `wp_admin_css_color()` schemes:
   - "UPEI Library" (grey/green/red #333/#517E1B/#8C2004)
   - "Colorblind Friendly" (blue/orange #003f87/#0066cc/#ff6600)
   - Auto-sets UPEI Library as default for new users via `user_register` hook

2. **Custom Focus Indicators** — Per-user settings for outline color + width, applied sitewide via inline CSS. Includes `prefers-contrast: high` media query support.

3. **Sitewide Font Selection** — Dropdown on profile page:
   - System Default
   - UPEI Library Default (Lusitana headings / Roboto body / Roboto Condensed UI — loads from Google Fonts)
   - Arial, Verdana, Tahoma

4. **Keyboard Shortcuts Localization (PHP side)** — Reads `ae_shortcut_*` user meta and injects `window.aeShortcuts` via `wp_add_inline_script`. The JS consumer is already restored in split-guide.js.

5. **Skip Link** — Inserts "Skip to main content" link at top of body, tries Pressbooks-specific selectors (#content, .entry-content, main, [role="main"], article).

6. **Keyboard Navigation Tracking** — Adds/removes `keyboard-navigation` class on body based on Tab vs mouse usage, enabling enhanced focus styles via CSS.

7. **TOC Accessibility** — Adds `title` attributes to TOC and navigation links missing them.

8. **Clickable Element Enhancement** — Adds `tabindex="0"`, `role="button"`, and Enter/Space key handlers to elements with `onclick` but no keyboard support.

9. **Profile Settings UI** — Full WordPress profile page section with:
   - Enable/disable toggle for custom focus indicators
   - Color picker for focus outline color
   - Width input for focus outline
   - Font family dropdown
   - Enable/disable toggle for custom shortcuts
   - 4 key-binding inputs (prev, next, focus quiz, focus tutorial)
   - Live preview box with test elements

10. **Pressbooks PDF/EPUB hooks** — Adds underline to links in PDF exports via `pb_pdf_css_override` filter.

### Phase 2: Wire into pb-split-guide.php

Add one line after the existing `require_once` block (after line 26):

```php
require_once plugin_dir_path(__FILE__) . 'accessibility-dashboard/class-pbsg-accessibility-dashboard.php';
```

The class self-instantiates at the bottom of its file (`new Pressbooks_Accessibility_Enhancer()`), so this single require is the entire integration point.

### Phase 2b: Add missing aria-labels to test elements

Commit `47c51ca` added `aria-label` attributes to the 4 test elements in the profile preview box, but `9e10c6b` didn't carry them over during refactoring. Apply these to `class-pbsg-accessibility-dashboard.php` lines 520-523:

- `<button aria-label="Button Focus Test Element" ...>`
- `<input aria-label="Text Focus Test Element" ...>`
- `<a aria-label="Link Focus Test Element" ...>`
- `<select aria-label="Dropdown Focus Test Element" ...>`

### Phase 3: Template micro-fix

In `split-guide-template.php`, add `style="text-decoration: underline;"` to the "Open in new window" link (line 289). This was part of Caleb's accessibility improvements — making the link visually distinguishable without relying on color alone.

### Phase 4: CSS theming changes (EVALUATE CAREFULLY)

Commit `9e10c6b` also made two categories of CSS changes:

#### 4a. split-guide.css — Remove hardcoded colors

Caleb removed hardcoded `background`, `color`, and `:hover` styles from ~15 selectors (menu button, menu items, focus buttons, course cards, start button, progress bar container, etc.) so they inherit from the active WordPress admin color scheme instead of being locked to specific hex values.

**Risk assessment:** MEDIUM. These removals rely on the WordPress admin color scheme providing reasonable contrast. If a user hasn't activated one of Caleb's custom schemes, elements might inherit unexpected colors from the default WordPress theme or Pressbooks theme. However, this is the intentional design — the elements should respect the user's chosen scheme.

**Recommendation:** Apply these changes. They're the mechanism that makes the admin color schemes actually work on the split-guide frontend. Without them, the color schemes only affect wp-admin, not the tutorial view.

#### 4b. analytics-dashboard.css + analytics-dashboard.js + class-pbsg-analytics-dashboard.php

Caleb replaced hardcoded color variables with CSS custom property fallbacks (`var(--gots-color-dark, #333333)`, `var(--gots-color-primary, #8C2004)`, etc.) and removed explicit `color` properties from ~20 selectors so the analytics dashboard also inherits theme colors.

**Risk assessment:** LOW-MEDIUM. Same logic as 4a, but the analytics dashboard is admin-only, where the admin color scheme is more predictable.

**Recommendation:** Apply these changes too, but as a separate commit so they can be reverted independently if the analytics dashboard styling breaks.

---

## Execution Order

| Step | Action | Risk | Commit |
|------|--------|------|--------|
| 1 | Extract 7 accessibility-dashboard files from `9e10c6b` | NONE (new files) | Commit A |
| 2 | Add `require_once` to pb-split-guide.php | NONE (additive) | Commit A |
| 3 | Add underline to "Open in new window" link | NONE (additive) | Commit A |
| 4 | Apply split-guide.css theming removals | MEDIUM | Commit B |
| 5 | Apply analytics dashboard theming changes | LOW-MEDIUM | Commit C |
| 6 | Update smoke test hook count (new hooks from accessibility class) | NONE | Commit A or D |

### Hook Count Impact

The `Pressbooks_Accessibility_Enhancer` constructor registers these hooks:

**Actions (17 new):**
- `after_setup_theme` × 1
- `admin_init` × 1
- `admin_head` × 2 (color schemes + styles)
- `wp_head` × 1
- `wp_footer` × 1
- `wp_enqueue_scripts` × 3 (fonts, shortcuts, inline styles)
- `admin_enqueue_scripts` × 2 (fonts, profile assets)
- `pressbooks_head` × 1
- `pb_head` × 1
- `show_user_profile` × 1
- `edit_user_profile` × 1
- `personal_options_update` × 1
- `edit_user_profile_update` × 1
- `user_register` × 1

**Filters (2 new):**
- `pb_pdf_css_override` × 1
- `pb_epub_css_override` × 1

The smoke test currently expects 29 actions. It will need updating to 29 + 17 = **46 actions** (and filters count will increase by 2).

---

## What NOT to Touch

- **split-guide.js** — All JS-side accessibility code (keyboard shortcuts listener, aria-labels, tutorial-active class) is already restored. Don't duplicate.
- **`.tutorial-active` CSS in split-guide.css** — The footer-hiding rule already exists on develop.
- **The standalone `accessibility-dashboard` plugin** (if it exists elsewhere) — We're using Caleb's refactored version that lives inside pb-split-guide.

---

## Verification Checklist

After implementation:

- [ ] `accessibility-dashboard/` directory exists with all 7 files
- [ ] Plugin loads without PHP fatal errors
- [ ] Profile page shows "Accessibility Settings" section
- [ ] Color picker and width input toggle visibility with checkbox
- [ ] UPEI Library and Colorblind Friendly appear in Admin → Users → Profile → Administration Color Scheme
- [ ] Custom shortcuts appear as `window.aeShortcuts` in page source when enabled
- [ ] Skip-to-content link appears on focus (Tab from page load)
- [ ] `keyboard-navigation` class toggles on body with Tab/mouse
- [ ] PHPUnit tests pass (hook count updated)
- [ ] "Open in new window" link has underline
