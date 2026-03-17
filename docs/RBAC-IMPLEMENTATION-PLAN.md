---
output:
  word_document: default
  html_document: default
---
# RBAC Implementation Plan — Guide on the Side

**Date:** 2026-03-16
**Scope:** Role-based access control, user authentication roles, menus, permissions
**Approach:** Hybrid — custom plugin UI for librarian management, backed by a native WordPress role

---

## 1. Current State (What Exists Today)

The plugin has **no custom roles**. It uses WordPress built-in capabilities as ad-hoc gatekeepers:

| Checkpoint | Capability | File | Line |
|---|---|---|---|
| My Tutorials menu | `read` | `pb-split-guide.php` | 246 |
| Tutorial edit link | `edit_post` (per-post) | `pb-split-guide.php` | 304 |
| Save tutorial meta | `edit_post` (per-post) | `pb-split-guide.php` | 414 |
| Analytics dashboard menu | `edit_pages` | `class-pbsg-analytics-dashboard.php` | 43 |
| Analytics AJAX data | `edit_pages` | `class-pbsg-analytics.php` | 431 |
| CSV export | `edit_pages` | `class-pbsg-analytics.php` | 754 |
| Certificate download | `is_user_logged_in()` only | `class-pbsg-certificate.php` | 22 |

H5P plugin defines its own capabilities: `view_h5p_contents`, `edit_h5p_contents`, `manage_h5p_libraries`, `view_h5p_results`, `install_recommended_h5p_libraries`, `disable_h5p_security`.

**Problem:** No distinction between admin and librarian. Anyone with `edit_pages` sees everything. No user management UI. No WP lockdown.

---

## 2. Target Architecture

### 2.1 Two Roles

| Role | WP Role Slug | Who |
|---|---|---|
| **Admin (Super Admin)** | `administrator` / network super admin | System owner (e.g., Melissa Belvadi). Full access to everything. |
| **Librarian** | `pbsg_librarian` (custom) | Library staff who create and manage tutorials. Restricted from H5P management, WP settings, and plugin management. |

### 2.2 Capability Matrix

A custom WordPress role `pbsg_librarian` will be registered with these capabilities:

```
# WordPress core capabilities (what librarians CAN do)
read                        # Access WP admin dashboard
upload_files                # Upload media (PDFs, images for tutorials)
edit_pages                  # Create/edit tutorials (pages)
edit_others_pages           # Edit tutorials authored by other librarians
edit_published_pages        # Edit already-published tutorials
publish_pages               # Publish tutorials
delete_pages                # Delete own tutorials
delete_others_pages         # Delete other librarians' tutorials
delete_published_pages      # Delete published tutorials

# H5P capabilities (use H5P, but NOT manage content types)
view_h5p_contents           # See H5P content list
edit_h5p_contents           # Create/edit H5P quizzes
view_others_h5p_contents    # See all H5P content (not just own)
view_h5p_results            # View quiz results

# Plugin-specific capabilities (new, custom)
pbsg_view_analytics         # View Tutorial Analytics dashboard
pbsg_export_csv             # Export analytics as CSV
pbsg_manage_tutorials       # General tutorial management

# Capabilities librarians will NOT have:
# manage_options            → No WP Settings
# manage_h5p_libraries      → No H5P content type installation
# install_recommended_h5p_libraries → No H5P library installs
# activate_plugins          → No plugin management
# edit_theme_options        → No Appearance settings
# manage_network            → No network admin
# create_users / delete_users / list_users / promote_users → No user management
# pbsg_manage_librarians    → Cannot manage other librarians' accounts
```

Admin gets everything above **plus**:

```
pbsg_manage_librarians      # Access the "Manage Librarians" page
manage_options              # WP Settings
manage_h5p_libraries        # H5P content type management
install_recommended_h5p_libraries
activate_plugins
edit_theme_options
manage_network
create_users / list_users / promote_users / delete_users
```

### 2.3 Why This Split

Per your answers: librarians **can** view analytics, **can** delete tutorials, and **can** edit each other's tutorials. The only hard restriction is **H5P content type management** (network-level). We also lock down all non-tutorial WordPress admin screens.

---

## 3. Implementation — File by File

### 3.1 New File: `includes/class-pbsg-roles.php`

**Purpose:** Register the custom role on plugin activation, define capabilities, provide helper methods.

```php
class PBSG_Roles {

    const LIBRARIAN_ROLE = 'pbsg_librarian';

    /**
     * Called on plugin activation.
     * Registers the pbsg_librarian role with correct capabilities.
     */
    public static function activate() {
        // Remove if exists (to refresh caps during development)
        remove_role(self::LIBRARIAN_ROLE);

        add_role(self::LIBRARIAN_ROLE, 'Librarian', [
            // WP core
            'read'                  => true,
            'upload_files'          => true,
            'edit_pages'            => true,
            'edit_others_pages'     => true,
            'edit_published_pages'  => true,
            'publish_pages'         => true,
            'delete_pages'          => true,
            'delete_others_pages'   => true,
            'delete_published_pages'=> true,
            // H5P (use, not manage)
            'view_h5p_contents'     => true,
            'edit_h5p_contents'     => true,
            'view_others_h5p_contents' => true,
            'view_h5p_results'      => true,
            // Plugin custom
            'pbsg_view_analytics'   => true,
            'pbsg_export_csv'       => true,
            'pbsg_manage_tutorials' => true,
        ]);

        // Grant admin the custom caps too
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('pbsg_view_analytics');
            $admin->add_cap('pbsg_export_csv');
            $admin->add_cap('pbsg_manage_tutorials');
            $admin->add_cap('pbsg_manage_librarians');
        }
    }

    /**
     * Called on plugin deactivation.
     */
    public static function deactivate() {
        remove_role(self::LIBRARIAN_ROLE);
        // Optionally clean admin caps
    }

    /**
     * Check if the current user is a GOTS admin (super admin or has manage_librarians cap).
     */
    public static function is_admin(): bool {
        return current_user_can('pbsg_manage_librarians') || is_super_admin();
    }

    /**
     * Check if current user is a librarian.
     */
    public static function is_librarian(): bool {
        $user = wp_get_current_user();
        return in_array(self::LIBRARIAN_ROLE, $user->roles, true);
    }
}
```

**Activation hook wiring** (in `pb-split-guide.php`):
```php
register_activation_hook(__FILE__, ['PBSG_Roles', 'activate']);
register_deactivation_hook(__FILE__, ['PBSG_Roles', 'deactivate']);
```

---

### 3.2 New File: `includes/class-pbsg-librarian-manager.php`

**Purpose:** Admin page for managing librarian accounts — list, register, edit, deactivate.

**Admin menu entry:**
```php
add_menu_page(
    'Manage Librarians',
    'Manage Librarians',
    'pbsg_manage_librarians',  // Only admins see this
    'pbsg-manage-librarians',
    [__CLASS__, 'render_page'],
    'dashicons-groups',
    4  // Right after "My Tutorials"
);
```

**Page sections:**

**A. Librarian List Table** (extends `WP_List_Table`)

| Column | Source |
|---|---|
| Name | `display_name` |
| Email | `user_email` |
| Registered | `user_registered` |
| Tutorials Created | COUNT of pages where `post_author = user_id` AND template = split-guide |
| Last Login | `last_login` usermeta (set via `wp_login` hook) |
| Actions | Edit / Deactivate |

**B. Register New Librarian Form**

Fields:
- Username (required, unique)
- Email (required, unique, validated)
- First Name / Last Name (optional)
- Password (auto-generated with "Send credentials via email" checkbox, OR manual entry)

On submit:
1. `wp_insert_user()` with role `pbsg_librarian`
2. Optionally `wp_new_user_notification()` to email credentials
3. Redirect back with success notice

**C. Edit Librarian**

- Edit display name, email, first/last name
- Reset password (generates new + emails)
- Deactivate (sets role to `subscriber` or a custom `pbsg_inactive` role, preventing login to admin)
- Reassign tutorials option when deactivating

---

### 3.3 New File: `templates/admin-manage-librarians.php`

**Purpose:** The HTML template for the Manage Librarians page.

Follows UPEI Design System:
- Lusitana headings, Roboto body, Roboto Condensed buttons
- `#517E1B` green primary, `#8C2004` red for destructive actions
- Card-based layout consistent with analytics dashboard
- Registration form in a collapsible/toggleable panel

---

### 3.4 New File: `assets/admin/admin-librarians.css`

Styles for the Manage Librarians page, extending the existing UPEI design system tokens already used in `analytics-dashboard.css`.

---

### 3.5 New File: `assets/admin/admin-librarians.js`

Minimal JS for:
- Toggle registration form panel
- Confirm deactivation dialog
- Client-side email validation
- AJAX search/filter on the librarian table (optional, progressive enhancement)

---

### 3.6 Modified File: `pb-split-guide.php`

Changes:

1. **Require new files:**
```php
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-roles.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-librarian-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-admin-menu-filter.php';
```

2. **Activation/deactivation hooks:**
```php
register_activation_hook(__FILE__, ['PBSG_Roles', 'activate']);
register_deactivation_hook(__FILE__, ['PBSG_Roles', 'deactivate']);
```

3. **Init the librarian manager and menu filter:**
```php
PBSG_Librarian_Manager::init();
PBSG_Admin_Menu_Filter::init();
```

---

### 3.7 Modified File: `class-pbsg-analytics-dashboard.php`

Change the capability from `edit_pages` to the new custom capability:

```php
// Line 43: Change from 'edit_pages' to 'pbsg_view_analytics'
add_menu_page(
    __('Tutorial Analytics', 'pb-split-guide'),
    __('Tutorial Analytics', 'pb-split-guide'),
    'pbsg_view_analytics',  // ← was 'edit_pages'
    'pbsg-analytics',
    ...
);
```

Both librarians and admins have `pbsg_view_analytics`, so both still see analytics (per your requirement). But now the capability is semantically correct and independently controllable.

---

### 3.8 Modified File: `class-pbsg-analytics.php`

Two changes:

```php
// Line 431: Analytics AJAX data
// Change: 'edit_pages' → 'pbsg_view_analytics'
if (!current_user_can('pbsg_view_analytics')) {
    wp_send_json_error(['message' => 'Unauthorized'], 403);
}

// Line 754: CSV export
// Change: 'edit_pages' → 'pbsg_export_csv'
if (!current_user_can('pbsg_export_csv')) {
    wp_die('Unauthorized');
}
```

---

### 3.9 New File: `includes/class-pbsg-admin-menu-filter.php`

**Purpose:** WP lockdown — hide non-GOTS admin menus from librarians.

```php
class PBSG_Admin_Menu_Filter {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'filter_menus'], 9999);
        add_action('admin_init', [__CLASS__, 'redirect_unauthorized'], 1);
    }

    /**
     * Remove admin menu items that librarians should not see.
     */
    public static function filter_menus() {
        if (is_super_admin() || current_user_can('pbsg_manage_librarians')) {
            return; // Admins see everything
        }

        if (!PBSG_Roles::is_librarian()) {
            return; // Not our user, don't touch
        }

        // Menus to KEEP for librarians:
        $allowed_slugs = [
            'index.php',                    // WP Dashboard (home)
            'pbsg-my-tutorials',            // My Tutorials
            'edit.php?post_type=page',      // Tutorials (Pages)
            'pbsg-analytics',               // Tutorial Analytics
            'upload.php',                   // Media Library (for uploads)
            'h5p',                          // H5P Content (use, not manage)
            'profile.php',                  // Own profile
        ];

        global $menu;
        foreach ($menu as $position => $item) {
            if (!in_array($item[2], $allowed_slugs, true)) {
                remove_menu_page($item[2]);
            }
        }

        // Also remove H5P "Libraries" submenu (manage_h5p_libraries)
        remove_submenu_page('h5p', 'h5p_libraries');
        // Remove H5P "Settings" submenu
        remove_submenu_page('options-general.php', 'h5p_settings');
    }

    /**
     * Server-side guard: redirect librarians if they try to access
     * a restricted admin page directly via URL.
     */
    public static function redirect_unauthorized() {
        if (!is_admin() || wp_doing_ajax()) return;
        if (is_super_admin() || current_user_can('pbsg_manage_librarians')) return;
        if (!PBSG_Roles::is_librarian()) return;

        $allowed_pages = [
            'index.php', 'edit.php', 'post.php', 'post-new.php',
            'upload.php', 'media-new.php',
            'pbsg-my-tutorials', 'pbsg-analytics',
            'h5p', 'h5p_new',
            'profile.php',
            'admin-ajax.php', 'admin-post.php',
        ];

        $current_page = basename($_SERVER['SCRIPT_NAME']);
        $page_param = $_GET['page'] ?? '';

        $is_allowed = in_array($current_page, $allowed_pages, true)
                   || in_array($page_param, $allowed_pages, true);

        if (!$is_allowed) {
            wp_safe_redirect(admin_url('admin.php?page=pbsg-my-tutorials'));
            exit;
        }
    }
}
```

---

## 4. Menu Structure (After Implementation)

### Admin sees:

```
├── Dashboard
├── My Tutorials          (pbsg_manage_tutorials)
├── Tutorials             (edit_pages)
│   ├── All Tutorials
│   └── Add Tutorial
├── Tutorial Analytics    (pbsg_view_analytics)
├── Manage Librarians     (pbsg_manage_librarians)  ← NEW, admin-only
├── Media                 (upload_files)
├── H5P Content           (view_h5p_contents)
│   ├── All H5P Content
│   ├── Add New
│   ├── Libraries         ← admin-only (manage_h5p_libraries)
│   └── My Results
├── [All other WP menus]  (manage_options, etc.)
└── Profile
```

### Librarian sees:

```
├── Dashboard
├── My Tutorials          (pbsg_manage_tutorials)
├── Tutorials             (edit_pages)
│   ├── All Tutorials
│   └── Add Tutorial
├── Tutorial Analytics    (pbsg_view_analytics)
├── Media                 (upload_files)
├── H5P Content           (view_h5p_contents)
│   ├── All H5P Content
│   ├── Add New
│   └── My Results
└── Profile
```

Everything else (Settings, Plugins, Appearance, Users, Tools, Comments, Posts, Manage Librarians, H5P Libraries) is **hidden and access-blocked**.

---

## 5. Authentication Flow

### 5.1 Librarian Registration (Admin Action)

```
Admin → Manage Librarians → "Register New Librarian" form
  ↓
wp_insert_user(username, email, role: pbsg_librarian)
  ↓
wp_new_user_notification() → librarian receives email with login link + password reset
  ↓
Librarian logs in at /wp/wp-login.php → lands on WP Dashboard
  ↓
Sees only: My Tutorials, Tutorials, Analytics, Media, H5P Content, Profile
```

### 5.2 Librarian Deactivation (Admin Action)

```
Admin → Manage Librarians → click "Deactivate" on a librarian
  ↓
Confirmation dialog: "Reassign their tutorials to [dropdown]?"
  ↓
set_role('subscriber') OR custom 'pbsg_inactive' role
  ↓
Librarian can no longer access admin panel (role lacks 'read' cap)
```

### 5.3 Login

No change — uses standard WordPress login at `/wp/wp-login.php`. Consider adding a redirect hook so librarians land on "My Tutorials" instead of the WP dashboard:

```php
add_filter('login_redirect', function($redirect_to, $request, $user) {
    if (in_array('pbsg_librarian', $user->roles ?? [])) {
        return admin_url('admin.php?page=pbsg-my-tutorials');
    }
    return $redirect_to;
}, 10, 3);
```

---

## 6. Database Changes

**None required.** The role and capabilities are stored in WordPress's `wp_options` table (serialized in the `wp_user_roles` option). User role assignments are stored in `wp_usermeta` with the key `wp_capabilities`.

Optional: add a `last_login` usermeta field for the librarian list table:

```php
add_action('wp_login', function($user_login, $user) {
    update_user_meta($user->ID, 'pbsg_last_login', current_time('mysql'));
}, 10, 2);
```

---

## 7. Implementation Order

### Phase 1: Role Foundation (Do First)
1. Create `includes/class-pbsg-roles.php` — role registration + capability definitions
2. Wire activation/deactivation hooks in `pb-split-guide.php`
3. Update `class-pbsg-analytics-dashboard.php` — swap `edit_pages` → `pbsg_view_analytics`
4. Update `class-pbsg-analytics.php` — swap capabilities
5. **Test:** Deactivate and reactivate plugin. Verify role appears in WP Users → Add New → Role dropdown.

### Phase 2: Menu Lockdown
6. Create `includes/class-pbsg-admin-menu-filter.php`
7. Wire it in `pb-split-guide.php`
8. Add login redirect for librarians
9. **Test:** Create a test librarian via WP Users screen. Log in as them. Verify only allowed menus appear. Try direct URL access to `/wp-admin/options-general.php` — should redirect.

### Phase 3: Manage Librarians Page
10. Create `includes/class-pbsg-librarian-manager.php`
11. Create `templates/admin-manage-librarians.php`
12. Create `assets/admin/admin-librarians.css` and `admin-librarians.js`
13. Wire admin menu entry (priority 1001, after Pressbooks)
14. Implement registration form + `wp_insert_user()` + email notification
15. Implement librarian list table (extending `WP_List_Table`)
16. Implement deactivation with tutorial reassignment
17. **Test:** Full flow — register librarian as admin, log in as librarian, verify access.

### Phase 4: Polish & Tests
18. Add PHPUnit tests for `PBSG_Roles` (capability checks, activation, deactivation)
19. Add PHPUnit tests for `PBSG_Admin_Menu_Filter` (menu filtering logic)
20. Add integration tests: register librarian, check capabilities, check menu visibility
21. Update existing tests if any break from capability changes
22. WCAG 2.1 AA check on the Manage Librarians page (keyboard nav, ARIA, contrast)

---

## 8. Security Considerations

1. **Nonce protection** on all forms (register, edit, deactivate) — `wp_nonce_field()` / `wp_verify_nonce()`
2. **Capability checks** on both menu registration AND the render callback (defense in depth)
3. **Server-side redirect** for direct URL access (not just hiding menus)
4. **Email validation** via `is_email()` on registration
5. **Password handling** — use `wp_generate_password()` for auto-generation, never store plaintext
6. **Username sanitization** — `sanitize_user()` on registration input
7. **Rate limiting** on registration form (prevent bulk account creation — WP nonces help here)
8. **Audit trail** — log librarian creation/deactivation to PHP error log or a custom log

---

## 9. Files Summary

### New Files (5)
| File | Purpose |
|---|---|
| `includes/class-pbsg-roles.php` | Role + capability registration |
| `includes/class-pbsg-librarian-manager.php` | Manage Librarians admin page |
| `includes/class-pbsg-admin-menu-filter.php` | WP menu lockdown for librarians |
| `templates/admin-manage-librarians.php` | HTML template for librarian management |
| `assets/admin/admin-librarians.css` | Styles for librarian management page |

### Modified Files (3)
| File | Change |
|---|---|
| `pb-split-guide.php` | Require new files, activation hooks, init new classes |
| `class-pbsg-analytics-dashboard.php` | `edit_pages` → `pbsg_view_analytics` |
| `class-pbsg-analytics.php` | `edit_pages` → `pbsg_view_analytics` / `pbsg_export_csv` |

### Test Files (3)
| File | Coverage |
|---|---|
| `tests/Unit/RolesTest.php` | Role registration, capabilities, helper methods |
| `tests/Unit/AdminMenuFilterTest.php` | Menu filtering, allowed slugs |
| `tests/Integration/LibrarianFlowTest.php` | End-to-end registration and access |

---

## 10. Rollback Plan

If anything breaks:
1. Deactivate the plugin → `PBSG_Roles::deactivate()` removes the custom role
2. All librarian users fall back to having no role (won't be deleted)
3. Reactivate plugin → role is re-created with correct caps
4. The `register_activation_hook` uses `remove_role()` then `add_role()`, so cap changes are always fresh

---

## 11. Native Pressbooks Integration (Added 2026-03-16)

The Manage Librarians page integrates with Pressbooks' native user management UI:

### 11.1 Edit Profile → Native user-edit.php

Instead of duplicating profile fields (name, email, password, Institution, Bio, Contact Info), the "Edit Profile" button in the librarian list links directly to the Pressbooks native `user-edit.php?user_id={ID}` screen. The custom "Manage" action retains GOTS-specific functionality (deactivation with tutorial reassignment).

**List table actions per librarian:**
- **Edit Profile** → Pressbooks native `network/user-edit.php?user_id={ID}`
- **Manage** → Custom page with profile summary + deactivation section

### 11.2 Native User Creation → Role Assignment Prompt

When an admin creates a user through Pressbooks' native "Add User" form (Network Admin → Users → Add User), the plugin hooks into `wpmu_new_user` and stores a transient. On the next admin page load, an admin notice appears:

> New user "jane.doe" (jane@example.com) was created. Should they be a Librarian for Guide on the Side? **[Assign Librarian Role]**

Clicking the button assigns `pbsg_librarian` role via a nonce-protected URL.

### 11.3 GOTS Role Column in Network Users List

A "GOTS Role" column is added to the Network Admin Users list table (via `wpmu_users_columns` filter), showing:
- **Admin** badge (green solid) for super admins
- **Librarian** badge (green outline) for `pbsg_librarian` users
- **—** for users with no GOTS role

This lets admins see at a glance who has what GOTS role without leaving the native interface.

### 11.4 Two-Path Registration

Librarians can now be created through either path:

| Path | Flow | Role Assignment |
|---|---|---|
| Custom (primary) | Manage Librarians → Register form | Automatic — role set on `wp_insert_user()` |
| Native (secondary) | Network Admin → Users → Add User | Manual — prompted via admin notice after creation |

---

## 12. Future Considerations

- **Bulk import** librarians from CSV (useful if 20+ librarians at once)
- **Activity log** per librarian (created tutorial X on date Y)
- **Role in multisite context** — if multiple "books" (sites), role may need per-site assignment
- **SSO integration** — if UPEI enables SAML/CAS, map the IdP group to `pbsg_librarian` role automatically
