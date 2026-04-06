# PR #30 — Accessibility Dashboard: Commit Log

**Repository:** `qixiang03/guide-on-the-side`
**Branch:** `feature/accessibility-dashboard` → `main`
**Author:** cnjones24
**Merge Commit:** [`0519f62`](https://github.com/qixiang03/guide-on-the-side/commit/0519f6248a44e55d6599a818ca0b0d0bf4fc5d64)

---

## Commit 1 — `537f679`

**Message:** Added custom accessibility shortcuts, custom fonts, and fixes to tutorial accessibility
**Parent:** `c4b6d68`
**Date:** ~March 2026
**Files changed:** 5 (+238 −27 lines)

### Files Modified

| File | Change |
|------|--------|
| `web/app/plugins/accessibility-dashboard/README.md` | +12 −3 |
| `web/app/plugins/accessibility-dashboard/accessibility-dashboard.php` | +128 −18 |
| `web/app/plugins/accessibility-dashboard/styles/profile.css` | +19 −6 |
| `web/app/plugins/pb-split-guide/assets/split-guide.css` | +7 |
| `web/app/plugins/pb-split-guide/assets/split-guide.js` | +72 |

---

### `accessibility-dashboard/README.md` (+12 −3)

Updated key features, how it works section, and usage instructions.

```diff
@@ -19,12 +19,15 @@ Many users can face difficulties navigating and operating online web application

 ## Key Features

-- Custom, per-user, focus indicators which can be changed in the 'Accessibility Settings' section of a users profile
+- Colorblind friendly color scheme alongside a default UPEI Library color scheme
+- Custom focus indicators
+- Sitewide font selection with choices from the UPEI Library Default (consisting of Lusitana, Roboto, and Roboto Condensed), Arial, Verdana, and Tahoma
+- Custom keyboard shortcuts for Guide-on-the-Side tutorials allowing for easy forward and backward navigation or focus changes


 ---

 ## How It Works
-
+Defines custom user metadata values which contain user accessibility preferences for color schemes, focus indication, font families, and tutorial keyboard shortcuts.

 ### Installation

@@ -44,11 +47,17 @@ Many users can face difficulties navigating and operating online web application

 3. Modify User Profile:

 ```
+Profile → Administration Color Scheme
 Profile → Accessibility Settings → Enable Custom Focus Indicators
+Profile → Accessibility Settings → Sitewide Font Family
+Profile → Accessibility Settings → Custom Keyboard Shortcuts
 ```

 ### Usage

-- Adjust focus indicators across the site with custom outline color and width
+- Adjust focus indicators across the site with custom outline color and width
+- Enable colorblind friendly administration color schemes
+- Select preferred font family
+- Define custom shortcuts for use within Guide-on-the-Side tutorials
```

---

### `accessibility-dashboard/accessibility-dashboard.php` (+128 −18)

Added custom keyboard shortcuts system, font fixes, and profile field updates.

```diff
@@ -27,6 +27,9 @@ public function __construct() {
         add_action('wp_enqueue_scripts', array($this, 'enqueue_custom_fonts'), 10);
         add_action('admin_enqueue_scripts', array($this, 'enqueue_custom_fonts'), 10);

+        // Expose custom shortcuts to frontend
+        add_action('wp_enqueue_scripts', array($this, 'localize_shortcuts_script'), 10);
+
         // Pressbooks-specific hooks
         add_action('pressbooks_head', array($this, 'add_accessibility_styles'), 999);
         add_action('pb_head', array($this, 'add_accessibility_styles'), 999);

@@ -46,7 +49,6 @@ public function __construct() {
         // Set default color scheme for new users
         add_action('user_register', array($this, 'set_default_admin_color'));

-
         // Pressbooks custom CSS filters
         add_filter('pb_pdf_css_override', array($this, 'add_pdf_accessibility'));
         add_filter('pb_epub_css_override', array($this, 'add_epub_accessibility'));

@@ -64,6 +66,29 @@ public function enqueue_custom_fonts() {
     }
     }

+    /**
+     * Pass shortcut configuration to the frontend javascript
+     */
+    public function localize_shortcuts_script() {
+        $user_id = get_current_user_id();
+        if (!$user_id) return;
+
+        $enable_shortcuts = get_user_meta($user_id, 'ae_enable_shortcuts', true);
+
+        if ($enable_shortcuts) {
+            $shortcuts = array(
+                'prev' => get_user_meta($user_id, 'ae_shortcut_prev', true) ?: 'ArrowLeft',
+                'next' => get_user_meta($user_id, 'ae_shortcut_next', true) ?: 'ArrowRight',
+                'focus_quiz' => get_user_meta($user_id, 'ae_shortcut_quiz', true) ?: 'q',
+                'focus_tutorial' => get_user_meta($user_id, 'ae_shortcut_tutorial', true) ?: 't',
+            );
+
+            wp_register_script('accessibility-enhancer-shortcuts', false);
+            wp_enqueue_script('accessibility-enhancer-shortcuts');
+            wp_add_inline_script('accessibility-enhancer-shortcuts',
+                'window.aeShortcuts = ' . wp_json_encode($shortcuts) . ';');
+        }
+    }
+

@@ -233,13 +258,13 @@ private function get_css_rules() {
     <?php if ($font_family && $font_family !== 'default') : ?>
         <?php if ($font_family === 'upei-default') : ?>
         /* UPEI Library Default Typography */
-        body, p, span, div, td, th, strong, h2, h3, h4, h5, h6, b {
+        body, p, span, div, li, td, th {
             font-family: 'Roboto', sans-serif !important;
         }
-        h1, .entry-title {
+        h1, h2, h3, h4, h5, h6, strong, b, .entry-title {
             font-family: 'Lusitana', serif !important;
         }
-        button, input, select, textarea, .nav, .menu, a.button, .page-navigation a, .a11y-skip-link, li {
+        button, input, select, textarea, .nav, .menu, a.button, .page-navigation a, .a11y-skip-link {
             font-family: 'Roboto Condensed', sans-serif !important;
         }

@@ -332,11 +357,18 @@ public function add_profile_fields($user) {
             $focus_color = get_user_meta($user->ID, 'ae_focus_color', true) ?: '#0066cc';
             $focus_width = get_user_meta($user->ID, 'ae_focus_width', true) ?: '3px';
             $font_family = get_user_meta($user->ID, 'ae_font_family', true) ?: 'default';
+
+            // Shortcut Settings
+            $enable_shortcuts = get_user_meta($user->ID, 'ae_enable_shortcuts', true);
+            $shortcut_prev = get_user_meta($user->ID, 'ae_shortcut_prev', true) ?: 'ArrowLeft';
+            $shortcut_next = get_user_meta($user->ID, 'ae_shortcut_next', true) ?: 'ArrowRight';
+            $shortcut_quiz = get_user_meta($user->ID, 'ae_shortcut_quiz', true) ?: 'q';
+            $shortcut_tutorial = get_user_meta($user->ID, 'ae_shortcut_tutorial', true) ?: 't';
             ?>

             <div class="ae-profile-section">
                 <h2>Accessibility Settings</h2>
-                <p>Customize keyboard focus indicators and typography to improve readability and navigation visibility.</p>
+                <p>Customize keyboard focus indicators, typography, and keyboard shortcuts to improve readability and navigation.</p>

             <table class="form-table">

@@ -365,9 +397,6 @@ public function add_profile_fields($user) {
                                     name="ae_focus_color"
                                     id="ae_focus_color"
                                     value="<?php echo esc_attr($focus_color); ?>" />
-                                <p class="description">
-                                    Choose the color for keyboard focus outlines (default: #0066cc – blue)
-                                </p>
                             </td>
                         </tr>

@@ -382,9 +411,6 @@ public function add_profile_fields($user) {
                                     value="<?php echo esc_attr($focus_width); ?>"
                                     class="small-text"
                                     placeholder="3px" />
-                                <p class="description">
-                                    Enter a CSS width value (e.g., 2px, 3px, 4px, 5px)
-                                </p>
                             </td>

@@ -406,13 +432,85 @@ class="small-text"
                         </tr>
                     </table>
+
+                    <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;" />
+                    <h3>Custom Keyboard Shortcuts</h3>
+                    <p>Define custom keys to control tutorial pages efficiently. Use character keys (e.g. 'q') or key names (e.g. 'ArrowLeft').</p>
+
+                    <table class="form-table">
+                        <tr>
+                            <th scope="row">Enable Custom Shortcuts</th>
+                            <td>
+                                <label>
+                                    <input type="checkbox"
+                                        name="ae_enable_shortcuts"
+                                        id="ae_enable_shortcuts"
+                                        value="1"
+                                        <?php checked($enable_shortcuts, '1'); ?> />
+                                    Use custom keyboard shortcuts on tutorial pages
+                                </label>
+                            </td>
+                        </tr>
+
+                        <tr class="ae-shortcut-row">
+                            <th scope="row">
+                                <label for="ae_shortcut_prev">Previous Button Key</label>
+                            </th>
+                            <td>
+                                <input type="text"
+                                    name="ae_shortcut_prev"
+                                    id="ae_shortcut_prev"
+                                    value="<?php echo esc_attr($shortcut_prev); ?>"
+                                    class="regular-text" />
+                            </td>
+                        </tr>
+
+                        <tr class="ae-shortcut-row">
+                            <th scope="row">
+                                <label for="ae_shortcut_next">Next Button Key</label>
+                            </th>
+                            <td>
+                                <input type="text"
+                                    name="ae_shortcut_next"
+                                    id="ae_shortcut_next"
+                                    value="<?php echo esc_attr($shortcut_next); ?>"
+                                    class="regular-text" />
+                            </td>
+                        </tr>
+
+                        <tr class="ae-shortcut-row">
+                            <th scope="row">
+                                <label for="ae_shortcut_quiz">Focus Quiz Button Key</label>
+                            </th>
+                            <td>
+                                <input type="text"
+                                    name="ae_shortcut_quiz"
+                                    id="ae_shortcut_quiz"
+                                    value="<?php echo esc_attr($shortcut_quiz); ?>"
+                                    class="regular-text" />
+                            </td>
+                        </tr>
+
+                        <tr class="ae-shortcut-row">
+                            <th scope="row">
+                                <label for="ae_shortcut_tutorial">Focus Tutorial Button Key</label>
+                            </th>
+                            <td>
+                                <input type="text"
+                                    name="ae_shortcut_tutorial"
+                                    id="ae_shortcut_tutorial"
+                                    value="<?php echo esc_attr($shortcut_tutorial); ?>"
+                                    class="regular-text" />
+                            </td>
+                        </tr>
+                    </table>


@@ -411,509 @@ public function add_profile_fields($user) {
-                $preview_style = 'font-family: ' . esc_attr($font_family) . ';';
+                $preview_style .= 'font-family: ' . esc_attr($font_family) . '; ';

             } elseif ($font_family === 'upei-default') {
-                $preview_style = "font-family: 'Roboto', sans-serif;";
+                $preview_style .= "font-family: 'Roboto', sans-serif; ";


@@ -441 @@ save_profile_fields
-        // Save enable/disable setting
+        // Save focus custom settings

@@ -448 @@
-        // Save color setting
         // Save color setting (unchanged)

@@ -456 @@
-        // Save width setting
-            // Validate CSS width format
+            // Validate CSS width format (comment removed, logic unchanged)

@@ -467 @@
-            // Because the font family string contains quotes/commas, we use wp_unslash and sanitize_text_field sparingly
             // (comment removed, logic unchanged)

+        // Save Custom Shortcuts settings
+        if (isset($_POST['ae_enable_shortcuts'])) {
+            update_user_meta($user_id, 'ae_enable_shortcuts', '1');
+        } else {
+            delete_user_meta($user_id, 'ae_enable_shortcuts');
+        }
+
+        $shortcut_fields = ['ae_shortcut_prev', 'ae_shortcut_next',
+            'ae_shortcut_quiz', 'ae_shortcut_tutorial'];
+        foreach ($shortcut_fields as $field) {
+            if (isset($_POST[$field])) {
+                // Keep the raw string (allowing 'ArrowLeft', 'a', etc)
+                // but remove potentially dangerous characters.
+                $key = sanitize_text_field(wp_unslash($_POST[$field]));
+                update_user_meta($user_id, $field, $key);
+            }
+        }
```

---

### `accessibility-dashboard/styles/profile.css` (+19 −6)

Added h3 styling, shortcut row styling, and dark mode fixes.

```diff
@@ -13,11 +13,19 @@
 }

 /* Section heading */
-.ae-profile-section h2 {
+.ae-profile-section h2, .ae-profile-section h3 {
     margin-top: 0;
+    color: #23282d;
+}
+
+.ae-profile-section h3 {
+    margin-top: 15px;
+    margin-bottom: 10px;
+}
+
+.ae-profile-section h2 {
     border-bottom: 2px solid #0073aa;
     padding-bottom: 10px;
-    color: #23282d;
 }

@@ -48,9 +56,10 @@
     vertical-align: middle;
 }

-/* Text input for width */
+/* Text input for width and keys */
 .ae-field-row input[type="text"],
-#ae_focus_width {
+#ae_focus_width,
+.ae-shortcut-row input[type="text"] {
     width: 100px;
 }

@@ -139,7 +148,8 @@
 }

 /* Checkbox label styling */
-#ae_enable_custom + label {
+#ae_enable_custom + label,
+#ae_enable_shortcuts + label {
     font-weight: normal;
 }

@@ -196,8 +206,11 @@
         border-color: #3c3c3c;
     }

-    .ae-profile-section h2 {
+    .ae-profile-section h2, .ae-profile-section h3 {
         color: #e0e0e0;
+    }
+
+    .ae-profile-section h2 {
         border-bottom-color: #2271b1;
     }
```

---

### `pb-split-guide/assets/split-guide.css` (+7)

Added rule to hide footer when tutorial is active.

```diff
@@ -826,4 +826,11 @@ body.pbsg-standalone .pbsg-wrap{
     align-items: center;
     justify-content: center;
     flex-wrap: wrap;
+}
+
+/* When the tutorial is active, completely hide the footer */
+body.tutorial-active footer,
+body.tutorial-active .site-footer,
+body.tutorial-active #colophon {
+    display: none !important;
 }
```

---

### `pb-split-guide/assets/split-guide.js` (+72)

Added tutorial-active body class and full custom keyboard shortcuts listener.

```diff
@@ -13,6 +13,78 @@ const introScreen = document.getElementById('pbsgIntroScreen');

 const mainContent = document.getElementById('pbsgMainContent');
 const startTutorialBtn = document.getElementById('pbsgStartTutorial');

+// Add a class to the body when the tutorial is active
+document.body.classList.add('tutorial-active');
+
+document.addEventListener('DOMContentLoaded', function() {
+
+    /**
+     * Accessibility Dashboard: Custom Shortcuts Listener
+     */
+    function initCustomShortcuts() {
+        // Check if the user has shortcuts enabled and defined (passed from PHP)
+        if (typeof window.aeShortcuts === 'undefined') {
+            return; // Shortcuts not enabled for this user
+        }
+
+        const shortcuts = window.aeShortcuts;
+
+        document.addEventListener('keydown', function(event) {
+            // Ignore keypresses if the user is typing inside an input, textarea, or contenteditable
+            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) ||
+                event.target.isContentEditable) {
+                return;
+            }
+            // Map the pressed key to the corresponding action
+            switch (event.key) {
+                case shortcuts.prev:
+                    event.preventDefault(); // Prevent default browser scrolling if using arrow keys
+                    triggerPreviousAction();
+                    break;
+
+                case shortcuts.next:
+                    event.preventDefault();
+                    triggerNextAction();
+                    break;
+
+                case shortcuts.focus_quiz:
+                    event.preventDefault();
+                    triggerFocusQuiz();
+                    break;
+
+                case shortcuts.focus_tutorial:
+                    event.preventDefault();
+                    triggerFocusTutorial();
+                    break;
+            }
+        });
+    }
+
+    // Helper functions to trigger the actions.
+
+    function triggerPreviousAction() {
+        if (prevBtn && !prevBtn.disabled) {
+            prevBtn.click();
+        }
+    }
+
+    function triggerNextAction() {
+        if (nextBtn && !nextBtn.disabled) {
+            nextBtn.click();
+        }
+    }
+
+    function triggerFocusQuiz() {
+        toggleFocus('quiz');
+    }
+
+    function triggerFocusTutorial() {
+        toggleFocus('tutorial');
+    }
+
+    // Initialize the listener
+    initCustomShortcuts();
+});
```

---
---

## Commit 2 — `47c51ca`

**Message:** Removed erroring profile css, added accessibility labels for several profile and tutorial elements
**Parent:** `537f679`
**Date:** ~March 2026
**Files changed:** 3 (+8 −53 lines)

### Files Modified

| File | Change |
|------|--------|
| `web/app/plugins/accessibility-dashboard/accessibility-dashboard.php` | +4 −4 |
| `web/app/plugins/accessibility-dashboard/styles/profile.css` | −45 |
| `web/app/plugins/pb-split-guide/templates/split-guide-template.php` | +4 −4 |

---

### `accessibility-dashboard/accessibility-dashboard.php` (+4 −4)

Added `aria-label` attributes to focus-test preview elements.

```diff
@@ -516,10 +516,10 @@ class="regular-text" />
                 <div class="ae-preview-box">
                     <p>Test your focus settings (press Tab to navigate):</p>
                     <div class="ae-test-elements" style="<?php echo $preview_style; ?>">
-                        <button type="button" <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>Test Button</button>
-                        <input type="text" placeholder="Test Input" <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>
-                        <a href="#test">Test Link</a>
-                        <select <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>
+                        <button aria-label="Button Focus Test Element" type="button" <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>Test Button</button>
+                        <input aria-label="Text Focus Test Element" type="text" placeholder="Test Input" <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>
+                        <a aria-label="Link Focus Test Element" href="#test">Test Link</a>
+                        <select aria-label="Dropdown Focus Test Element" <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>
                             <option>Test Dropdown</option>
                         </select>
                     </div>
```

---

### `accessibility-dashboard/styles/profile.css` (−45)

Removed erroring high-contrast and dark-mode CSS blocks.

```diff
@@ -184,48 +184,3 @@
     }
 }

-/* Accessibility: High contrast mode support */
-@media (prefers-contrast: high) {
-    .ae-profile-section {
-        border: 2px solid #000;
-    }
-
-    .ae-profile-section h2 {
-        border-bottom-width: 3px;
-    }
-
-    .ae-preview-box {
-        border: 2px solid #000;
-    }
-}
-
-/* Accessibility: Dark mode support */
-@media (prefers-color-scheme: dark) {
-    .ae-profile-section {
-        background: #1e1e1e;
-        border-color: #3c3c3c;
-    }
-
-    .ae-profile-section h2, .ae-profile-section h3 {
-        color: #e0e0e0;
-    }
-
-    .ae-profile-section h2 {
-        border-bottom-color: #2271b1;
-    }
-
-    .ae-profile-section > p,
-    .ae-preview-box > p {
-        color: #d0d0d0;
-    }
-
-    .ae-preview-box {
-        background: #2c2c2c;
-        border-color: #3c3c3c;
-    }
-
-    .ae-field-row .description,
-    .ae-profile-section .description {
-        color: #a0a0a0;
-    }
-}
```

---

### `pb-split-guide/templates/split-guide-template.php` (+4 −4)

Added aria-labels to iframes and underline style to the "Open in new window" link.

```diff
@@ -174,7 +174,7 @@ class="pbsg-menu-item"
             </div>

             <div class="pbsg-iframe-wrap">
-                <iframe id="pbsgH5PFrame" class="pbsg-iframe"></iframe>
+                <iframe aria-label="H5P Frame" id="pbsgH5PFrame" class="pbsg-iframe"></iframe>
             </div>

             <div class="pbsg-nav">

@@ -192,17 +192,17 @@ class="pbsg-menu-item"
             <div class="pbsg-banner">
                 <div class="pbsg-banner-text">
                     <?php echo esc_html($note ? $note : 'If the webpage is not displaying below'); ?>
-                    <a class="pbsg-open-btn" id="pbsgOpenLink" href="#" target="_blank">Open in new window ↗</a>
+                    <a class="pbsg-open-btn" id="pbsgOpenLink" href="#" target="_blank" style="text-decoration: underline;">Open in new window ↗</a>
                 </div>
                 <div class="pbsg-banner-actions">
                     <button type="button" class="pbsg-focus-btn" id="pbsgFocusTutorial">Focus Tutorial</button>
                 </div>
             </div>

             <div class="pbsg-iframe-wrap" id="pbsgTutorialStage">
-                <iframe id="pbsgTutorialFrame" class="pbsg-iframe"
+                <iframe aria-label="Tutorial Frame" id="pbsgTutorialFrame" class="pbsg-iframe"
                     allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
-                    allowfullscreen></iframe>
+                    allowfullscreen ></iframe>
             </div>
             <div id="pbsgTutorialFallback" class="pbsg-fallback">
                 <a id="pbsgFallbackLink" href="#" target="_blank">Open file in new tab</a>
```

---
---

## Commit 3 (Merge) — `0519f62`

**Message:** Merge pull request #30 from qixiang03/feature/accessibility-dashboard — Feature/accessibility dashboard
**Parents:** `6e854c3` + `47c51ca`
**Verified:** Yes
**Date:** ~March 2026
**Total files changed:** 12 (+2989 −4468 lines)

This merge commit combines the feature branch into `main`. In addition to the changes from commits `537f679` and `47c51ca` above, the merge diff includes the initial creation of the accessibility-dashboard plugin (from earlier commits on the feature branch that predated `537f679`).

### All Files in the Merge Diff

| File | Change |
|------|--------|
| `.gitignore` | +1 |
| `package-lock.json` | +1 −4,464 |
| `web/app/plugins/accessibility-dashboard/README.md` | +63 (new) |
| `web/app/plugins/accessibility-dashboard/accessibility-dashboard.php` | +622 (new) |
| `web/app/plugins/accessibility-dashboard/assets/frontend.js` | +121 (new) |
| `web/app/plugins/accessibility-dashboard/assets/profile.js` | +76 (new) |
| `web/app/plugins/accessibility-dashboard/styles/admin-colors-colorblind.css` | +921 (new) |
| `web/app/plugins/accessibility-dashboard/styles/admin-colors-upei.css` | +915 (new) |
| `web/app/plugins/accessibility-dashboard/styles/profile.css` | +186 (new) |
| `web/app/plugins/pb-split-guide/assets/split-guide.css` | +7 |
| `web/app/plugins/pb-split-guide/assets/split-guide.js` | +72 |
| `web/app/plugins/pb-split-guide/templates/split-guide-template.php` | +4 −4 |

---

### `.gitignore` (+1)

```diff
@@ -11,6 +11,7 @@ web/app/languages/*
 web/app/plugins/*
 !web/app/plugins/guide-on-the-side/
 !web/app/plugins/pb-split-guide/
+!web/app/plugins/accessibility-dashboard
 web/app/mu-plugins/*
 web/app/themes/*
 web/app/uploads/*
```

---

### New File: `accessibility-dashboard/assets/frontend.js` (+121)

Frontend JavaScript handling skip links, keyboard navigation tracking, TOC accessibility, and clickable element enhancements.

```javascript
/**
 * Accessibility Dashboard – Frontend JavaScript
 * Handles skip links, keyboard navigation tracking, and accessibility enhancements
 */

(function() {
    'use strict';

    console.log('Accessibility Enhancer for Pressbooks: Loaded');

    /**
     * Cross-browser DOM ready function
     */
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /**
     * Initialize all accessibility features
     */
    ready(function() {
        addSkipLink();
        trackKeyboardNavigation();
        enhanceTOCAccessibility();
        enhanceClickableElements();

        console.log('Accessibility Enhancer: Initialized');
    });

    /**
     * Add skip-to-content link
     */
    function addSkipLink() {
        var skipLink = document.createElement('a');
        skipLink.href = '#content';
        skipLink.className = 'a11y-skip-link';
        skipLink.textContent = 'Skip to main content';

        skipLink.addEventListener('click', function(e) {
            e.preventDefault();

            // Try to find main content area (Pressbooks-specific selectors)
            var targets = ['#content', '.entry-content', 'main', '[role="main"]', 'article'];

            for (var i = 0; i < targets.length; i++) {
                var target = document.querySelector(targets[i]);
                if (target) {
                    target.setAttribute('tabindex', '-1');
                    target.focus();
                    window.scrollTo(0, target.offsetTop);
                    break;
                }
            }
        });

        // Insert at the beginning of body
        if (document.body) {
            document.body.insertBefore(skipLink, document.body.firstChild);
        }
    }

    /**
     * Track keyboard vs mouse navigation
     * Adds 'keyboard-navigation' class to body when Tab is used
     */
    function trackKeyboardNavigation() {
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                document.body.classList.add('keyboard-navigation');
            }
        });

        document.addEventListener('mousedown', function() {
            document.body.classList.remove('keyboard-navigation');
        });
    }

    /**
     * Enhance TOC and navigation links with better accessibility
     */
    function enhanceTOCAccessibility() {
        var tocLinks = document.querySelectorAll('#toc a, .page-navigation a, .nav-reading a');

        tocLinks.forEach(function(link) {
            // Add title attribute if missing
            if (!link.getAttribute('title')) {
                link.setAttribute('title', link.textContent.trim());
            }
        });
    }

    /**
     * Make elements with onclick handlers keyboard accessible
     */
    function enhanceClickableElements() {
        var clickables = document.querySelectorAll('[onclick]:not([tabindex])');

        clickables.forEach(function(el) {
            // Make focusable
            el.setAttribute('tabindex', '0');

            // Add button role if no role exists
            if (!el.getAttribute('role')) {
                el.setAttribute('role', 'button');
            }

            // Allow Enter and Space to trigger click
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    el.click();
                }
            });
        });
    }

})();
```

---

### New File: `accessibility-dashboard/assets/profile.js` (+76)

Profile page JavaScript for live preview and form interaction.

```javascript
/**
 * Accessibility Dashboard – Profile Page JavaScript
 * Handles live preview and form interactions on user profile page
 */

(function() {
    'use strict';

    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /**
     * Initialize profile page functionality
     */
    function init() {
        var enableCheckbox = document.getElementById('ae_enable_custom');
        var colorRow = document.getElementById('ae_color_row');
        var widthRow = document.getElementById('ae_width_row');
        var colorInput = document.getElementById('ae_focus_color');
        var widthInput = document.getElementById('ae_focus_width');
        var testElements = document.querySelectorAll('.ae-test-elements *');

        // Check if elements exist (in case we're not on profile page)
        if (!enableCheckbox) {
            return;
        }

        /**
         * Toggle visibility of color and width fields
         */
        function toggleFields() {
            var enabled = enableCheckbox.checked;
            colorRow.style.display = enabled ? 'table-row' : 'none';
            widthRow.style.display = enabled ? 'table-row' : 'none';
        }

        /**
         * Update live preview of focus styles
         */
        function updatePreview() {
            if (!enableCheckbox.checked) {
                return;
            }

            var color = colorInput.value;
            var width = widthInput.value;

            testElements.forEach(function(el) {
                el.style.setProperty('outline', width + ' solid ' + color, 'important');
                el.style.setProperty('outline-offset', '2px', 'important');
            });
        }

        // Attach event listeners
        enableCheckbox.addEventListener('change', toggleFields);
        colorInput.addEventListener('input', updatePreview);
        widthInput.addEventListener('input', updatePreview);

        // Update preview when test elements are focused
        testElements.forEach(function(el) {
            el.addEventListener('focus', function() {
                if (enableCheckbox.checked) {
                    updatePreview();
                }
            });
        });

        // Set initial state
        toggleFields();
    }

})();
```

---

### New File: `accessibility-dashboard/styles/profile.css` (+186)

Full styles for the accessibility settings section on the user profile page.

```css
/**
 * Accessibility Dashboard – Profile Page Styles
 * Styles for the accessibility settings section on user profile pages
 */

/* Main profile section container */
.ae-profile-section {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    margin-top: 20px;
}

/* Section heading */
.ae-profile-section h2, .ae-profile-section h3 {
    margin-top: 0;
    color: #23282d;
}

.ae-profile-section h2 {
    border-bottom: 2px solid #0073aa;
    padding-bottom: 10px;
}

.ae-profile-section h3 {
    margin-top: 15px;
    margin-bottom: 10px;
}

/* Description paragraph */
.ae-profile-section > p {
    color: #555;
    margin-bottom: 20px;
}

/* Field row styling */
.ae-field-row {
    margin-bottom: 15px;
}

.ae-field-row label {
    display: inline-block;
    width: 200px;
    font-weight: 600;
}

/* Color picker input */
.ae-field-row input[type="color"],
#ae_focus_color {
    width: 60px;
    height: 40px;
    border: 1px solid #ddd;
    border-radius: 3px;
    cursor: pointer;
    vertical-align: middle;
}

/* Text input for width and keys */
.ae-field-row input[type="text"],
#ae_focus_width,
.ae-shortcut-row input[type="text"] {
    width: 100px;
}

/* Font Selection Select Dropdown */
#ae_font_family {
    min-width: 250px;
    max-width: 100%;
}

/* Description text under inputs */
.ae-field-row .description,
.ae-profile-section .description {
    display: block;
    margin-top: 5px;
    color: #666;
    font-style: italic;
    font-size: 13px;
}

/* Preview box */
.ae-preview-box {
    background: white;
    border: 1px solid #ddd;
    padding: 20px;
    margin-top: 20px;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.ae-preview-box > p {
    margin: 0 0 15px 0;
    font-weight: 600;
    color: #23282d;
}

/* Test elements container */
.ae-test-elements {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.ae-test-elements button,
.ae-test-elements input,
.ae-test-elements a,
.ae-test-elements select {
    margin: 0;
}

.ae-test-elements button {
    padding: 6px 12px;
    background: #0073aa;
    color: white;
    border: none;
    border-radius: 3px;
    cursor: pointer;
}

.ae-test-elements button:hover {
    background: #005a87;
}

.ae-test-elements input[type="text"] {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    min-width: 150px;
}

.ae-test-elements a {
    color: #0073aa;
    text-decoration: none;
    padding: 6px 10px;
    border-radius: 3px;
}

.ae-test-elements a:hover {
    text-decoration: underline;
}

.ae-test-elements select {
    padding: 6px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

/* Checkbox label styling */
#ae_enable_custom + label,
#ae_enable_shortcuts + label {
    font-weight: normal;
}

/* Hidden rows (when checkbox is unchecked) */
#ae_color_row[style*="display: none"],
#ae_width_row[style*="display: none"] {
    display: none;
}

/* Responsive adjustments */
@media screen and (max-width: 782px) {
    .ae-field-row label {
        width: 100%;
        margin-bottom: 5px;
    }

    .ae-field-row .description {
        margin-left: 0;
    }

    .ae-test-elements {
        flex-direction: column;
        align-items: stretch;
    }

    .ae-test-elements button,
    .ae-test-elements input,
    .ae-test-elements a,
    .ae-test-elements select {
        width: 100%;
        text-align: center;
    }
}
```

---

### New Files (large diffs, not rendered by GitHub)

The following files were added but their diffs were too large for GitHub to render inline:

- **`admin-colors-colorblind.css`** (+921 lines) — A complete WordPress admin color scheme designed for colorblind accessibility.
- **`admin-colors-upei.css`** (+915 lines) — A complete WordPress admin color scheme using UPEI Library branding.
- **`accessibility-dashboard.php`** (+622 lines) — The main plugin file. A WordPress/Pressbooks plugin class that registers admin color schemes, manages per-user accessibility preferences via user meta, enqueues custom fonts, injects dynamic CSS, and renders a settings section on the user profile page.
- **`package-lock.json`** (+1 −4,464 lines) — Dependency lock file update.
