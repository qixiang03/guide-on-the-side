# Guide on the Side — Technical Manual

**Project:** Guide on the Side — Interactive Tutorial System for UPEI Library  
**Plugin:** `pb-split-guide` (`web/app/plugins/pb-split-guide/`)  
**Stack:** WordPress 6.9 · Pressbooks (multisite) · H5P · Lando (local) · nginx + Docker (staging)  
**Last Updated:** April 2026 · Sprint 9

> **AI Disclosure:** This document was produced with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Code Organization](#2-code-organization)
3. [Database Schema](#3-database-schema)
4. [Key Features & Implementation](#4-key-features--implementation)
5. [Dependencies](#5-dependencies)
6. [Local Development Setup](#6-local-development-setup)
7. [Deployment (Staging Server)](#7-deployment-staging-server)
8. [Extending the Plugin](#8-extending-the-plugin)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Architecture Overview

### 1.1 System Layers

```
Browser (student or librarian)
    │
    ├─ Staging: http://137.149.157.198:80
    │     └─ nginx reverse proxy (192.168.0.198:80)
    │           └─ Traefik HTTPS (127.0.0.1:443)
    │                 └─ Lando Docker containers
    │
    └─ Local dev: https://pressbooks.test
          └─ Lando Traefik (direct)

WordPress/Pressbooks
    └─ pb-split-guide plugin  ← this codebase
          ├─ H5P plugin (quiz engine)
          └─ TCPDF (certificate PDFs, via Composer)
```

### 1.2 WordPress Multisite Sites

The installation is a WordPress Multisite network. Two sites exist:

| Blog ID | Path | Purpose |
|---------|------|---------|
| `1` | `/wp/` | Network admin; root site |
| `39` | `/development/` | Active tutorial sub-site (librarian-facing) |

**Important:** Most `wp` CLI commands and admin URLs need `--url=https://pressbooks.test/development/` to target site 39. Options, users, and plugin settings differ per site. Network-wide settings (H5P hub, template table) use `$wpdb->base_prefix` (`wp_`). Per-site tables use `$wpdb->prefix` (`wp_39_` on site 39).

### 1.3 What the Plugin Does

`pb-split-guide` adds a **split-screen tutorial system** on top of Pressbooks:

- Librarians create tutorials as WordPress **Pages** with a custom page template
- Each tutorial has multiple **steps**: left pane = H5P quiz, right pane = embedded URL or file
- Students navigate step-by-step; optional branching redirects on wrong answers
- Completion triggers a downloadable PDF certificate
- Aggregate analytics are collected (no PII — PIPEDA compliant)
- Templates let librarians save and reuse tutorial structures

The plugin does **not** use custom tutorial tables. Tutorials are WordPress Pages; all tutorial data lives in post meta. See [Section 3](#3-database-schema).

---

## 2. Code Organization

### 2.1 Directory Layout

```
web/app/plugins/pb-split-guide/
├── pb-split-guide.php              # Main plugin file — bootstraps everything
├── class-pbsg-analytics.php        # Analytics engine (event ingestion, tables)
├── class-pbsg-analytics-dashboard.php  # Admin analytics UI
├── composer.json                   # Plugin-level Composer (TCPDF)
├── vendor/                         # TCPDF and other plugin deps
├── assets/
│   ├── admin-split-guide.js        # All admin-page JavaScript (steps editor, modals)
│   ├── split-guide.js              # Frontend tutorial JS (navigation, H5P events)
│   ├── split-guide-tracker.js      # Analytics event emitter (frontend)
│   ├── split-guide.css             # Frontend styles
│   ├── admin/
│   │   ├── admin-split-guide.css   # Admin metabox + modal styles
│   │   └── admin-librarians.css/js # Librarian management page styles/scripts
│   ├── analytics-dashboard.css/js  # Analytics dashboard styles/scripts
│   └── images/
│       └── logo.png                # Used on generated certificates
├── includes/
│   ├── steps-normalizer.php        # Pure PHP: validates/normalizes step arrays
│   ├── class-pbsg-roles.php        # Custom role, capabilities, H5P cap filtering
│   ├── class-pbsg-librarian-manager.php  # Librarian CRUD, last-login tracking
│   ├── class-pbsg-certificate.php  # PDF certificate generation via TCPDF
│   ├── class-pbsg-export-import.php  # Tutorial JSON export/import
│   ├── class-pbsg-h5p-factory.php  # Programmatic H5P content creation
│   ├── class-pbsg-template-manager.php  # Tutorial template CRUD
│   └── class-pbsg-admin-menu-filter.php # Admin menu filtering/hiding
└── templates/
    ├── split-guide-template.php    # Frontend tutorial renderer (the actual page)
    ├── admin-my-tutorials.php      # "My Tutorials" dashboard page
    ├── admin-new-tutorial.php      # Template picker (shown on "Add Tutorial")
    └── admin-manage-librarians.php # Librarian management page
```

### 2.2 Class Reference

| Class | File | Purpose |
|-------|------|---------|
| `PB_Split_Guide_Plugin` | `pb-split-guide.php` | Main class; registers all hooks, metabox, AJAX endpoints, menu renaming |
| `PBSG_Analytics` | `class-pbsg-analytics.php` | Creates 3 analytics tables; handles `pbsg_track_event` AJAX (nopriv); CSV export |
| `PBSG_Analytics_Dashboard` | `class-pbsg-analytics-dashboard.php` | Admin analytics page with charts |
| `PBSG_Certificate` | `includes/class-pbsg-certificate.php` | Marks tutorials complete in user meta; generates TCPDF certificates |
| `PBSG_Export_Import` | `includes/class-pbsg-export-import.php` | JSON export (with base64 attachments) and import for tutorial portability |
| `PBSG_H5P_Factory` | `includes/class-pbsg-h5p-factory.php` | Creates H5P content records (MultiChoice, Blanks) programmatically via H5P's internal API |
| `PBSG_Librarian_Manager` | `includes/class-pbsg-librarian-manager.php` | Register/deactivate librarians, track last login, reassign tutorials |
| `PBSG_Roles` | `includes/class-pbsg-roles.php` | `pbsg_librarian` role, cap filtering, H5P cap blocking at runtime |
| `PBSG_Template_Manager` | `includes/class-pbsg-template-manager.php` | CRUD for `pbsg_tutorial_templates` table; create-from-template with token replacement |
| `PBSG_Steps_Normalizer` | `includes/steps-normalizer.php` | Pure PHP (no WP deps) step array validation — unit-testable |
| `PBSG_Admin_Menu_Filter` | `includes/class-pbsg-admin-menu-filter.php` | Hides/filters admin menu items per role |

### 2.3 Plugin Bootstrap (`pb-split-guide.php`)

On every request, the file:
1. Requires all class files via `require_once`
2. Instantiates `new PB_Split_Guide_Plugin()` (registers all hooks in `__construct`)
3. Registers activation hooks for table creation
4. Calls `PBSG_Analytics::init()`, `PBSG_Analytics_Dashboard::init()`, `PBSG_Certificate::init()`

**Activation hooks (run once on plugin activate):**

```php
register_activation_hook(__FILE__, ['PBSG_Analytics',        'create_tables']);
register_activation_hook(__FILE__, ['PBSG_Template_Manager', 'create_tables']);
register_activation_hook(__FILE__, ['PBSG_Roles',            'activate']);
```

---

## 3. Database Schema

### 3.1 Tutorial Data (Post Meta)

Tutorials are **WordPress Pages** (`post_type = page`) with the `split-guide-template.php` page template. All tutorial-specific data lives in post meta — there is no custom `wp_tutorials` table in the live system (early design docs proposed one but it was not implemented).

| Meta Key | Type | Description |
|----------|------|-------------|
| `_wp_page_template` | `string` | Must be `split-guide-template.php` to be treated as a tutorial |
| `_pbsg_steps_json` | `string` (JSON) | Array of step objects (see below) |
| `_pbsg_header_note` | `string` | Optional plain-text header note shown above the tutorial |
| `_pbsg_cover_image_id` | `int` | Attachment ID of the cover image (shown on My Tutorials grid) |

**Step object shape** (one element of `_pbsg_steps_json` array):

```json
{
  "title": "Step title (optional)",
  "h5p_id": 5,
  "tutorial_type": "url",
  "tutorial_url": "https://example.com/resource",
  "tutorial_attachment_id": 0,
  "tutorial_file_name": "",
  "tutorial_file_url": "",
  "url": "https://example.com/resource",
  "branch_mode": "none",
  "branch_trigger_attempts": 1,
  "branch_title": "",
  "branch_intro": "",
  "branch_tutorial_type": "",
  "branch_tutorial_url": "",
  "branch_tutorial_attachment_id": 0,
  "branch_tutorial_file_name": "",
  "branch_tutorial_file_url": ""
}
```

`tutorial_type` is `"url"`, `"file"`, or `""` (no resource). `url` is a legacy mirror of `tutorial_url`. `branch_mode` is `"none"`, `"always"`, or `"on_fail"`.

`PBSG_Steps_Normalizer::normalize()` is the canonical validator — run any step array through it before saving.

**Certificate completion** is tracked in **user meta** (not post meta):

| User Meta Key | Value |
|---------------|-------|
| `pbsg_completed_{page_id}` | Unix timestamp of first completion |

### 3.2 Custom Tables

#### `{prefix}pbsg_tutorial_templates`

Stores reusable tutorial templates. Created by `PBSG_Template_Manager::create_tables()`.

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | PK |
| `name` | `VARCHAR(200)` | Display name |
| `description` | `TEXT` | Optional description |
| `category` | `VARCHAR(100)` | Grouping label (UI removed per client request; field retained in DB) |
| `is_system` | `TINYINT(1)` | 1 = seeded by plugin, cannot be deleted |
| `steps_json` | `LONGTEXT` | JSON array matching `_pbsg_steps_json` format |
| `header_note` | `VARCHAR(500)` | Pre-filled header note |
| `created_by` | `BIGINT UNSIGNED` | `wp_users.ID` of creator |
| `created_at` | `DATETIME` | Auto-set on insert |

One system template ("Split Guide (Default)") is seeded on activation.

#### `{prefix}pbsg_tutorial_stats`

Aggregate tutorial-level counters. One row per tutorial page, updated atomically.

| Column | Description |
|--------|-------------|
| `tutorial_page_id` | WP page ID (unique key) |
| `view_count` | Total page loads |
| `completion_count` | Times the summary screen was reached |
| `total_time_seconds` | Sum of all session durations |
| `total_sessions` | Session flush events received |

#### `{prefix}pbsg_question_stats`

Per-question aggregate stats. One row per `(tutorial_page_id, h5p_content_id, question_index)`.

Key columns: `total_attempts`, `correct_count`, `incorrect_count`, `giveup_count`, `first_attempt_correct`, `second_attempt_correct`.

#### `{prefix}pbsg_daily_stats`

Daily rollup for trend charts. One row per `(stat_date, tutorial_page_id, device_type)`.

Key columns: `view_count`, `completion_count`, `total_time_seconds`, `step_views` (JSON map of step index → view count).

### 3.3 H5P Tables (owned by H5P plugin)

| Table | Description |
|-------|-------------|
| `wp_h5p_contents` | H5P content records — we store `h5p_id` referencing this |
| `wp_h5p_libraries_hub_cache` | Content type cache (network-wide, `base_prefix`) |
| `wp_h5p_results` | Quiz attempt results (H5P-managed) |

See `docs/H5P-TROUBLESHOOTING.md` (in the Week 6 deliverables folder) for H5P-specific database issues.

---

## 4. Key Features & Implementation

### 4.1 Tutorial Page Rendering

**File:** `templates/split-guide-template.php`

When a page with `_wp_page_template = split-guide-template.php` is loaded:

1. Reads `_pbsg_steps_json` and `_pbsg_header_note` from post meta
2. Enriches steps: resolves `tutorial_attachment_id` → URL via `wp_get_attachment_url()`
3. Injects step data as a JSON blob into the page via `wp_localize_script`
4. Renders split-screen HTML: left pane (H5P iframe), right pane (resource iframe/video/PDF)
5. Navigation buttons (Prev/Next), progress bar, step menu, and summary screen are rendered client-side by `assets/split-guide.js`

H5P quizzes are embedded via `{site_url}/?action=h5p_embed&id={h5p_id}` in an iframe. The frontend JS listens for `H5P.externalDispatcher` `xAPI` events to detect correct/incorrect answers and trigger branching.

### 4.2 Librarian Admin Interface

**Entry point:** "My Tutorials" admin menu item → `templates/admin-my-tutorials.php`

Librarians see a card grid of their tutorials with edit and preview links. The plugin renames WordPress "Pages" to "Tutorials" everywhere in the admin UI via `gettext`/`ngettext` filters and direct `$menu`/`$submenu` patching.

**Adding a new tutorial:**  
Clicking "Add Tutorial" is intercepted by a `load-post-new.php` hook that redirects to a custom template picker page (`templates/admin-new-tutorial.php`). The picker fetches templates via `wp_ajax_pbsg_get_templates`, shows a card grid, and on "Create Tutorial" calls `wp_ajax_pbsg_create_from_template` → `PBSG_Template_Manager::create_from_template()` → `wp_insert_post()`, then redirects to the standard WP page editor.

**Step editor:**  
The Split Guide Settings metabox (`render_metabox()` in `pb-split-guide.php`) provides:
- Steps table with Add/Remove/Reorder (SortableJS)
- H5P picker (Thickbox, AJAX-fetched list from `wp_h5p_contents`)
- Tutorial source picker (URL input or WP Media upload)
- Branching configuration per step
- Inline quiz builder (creates H5P content via `PBSG_H5P_Factory`)
- Cover image picker
- "Save as Template" button (opens modal → `wp_ajax_pbsg_save_as_template`)

All metabox state is serialized to a hidden `<input id="pbsg_steps_json">` field and saved via `save_meta()` on `save_post_page`.

### 4.3 Roles & Capabilities

**File:** `includes/class-pbsg-roles.php`

| Role | Who | Key Caps |
|------|-----|----------|
| `administrator` | Site/network admin | All caps + `pbsg_manage_librarians` |
| `pbsg_librarian` | Teaching librarian | `edit_pages`, `publish_pages`, H5P view/edit, `pbsg_manage_tutorials` |

The librarian role **cannot**:
- Install/manage H5P content types (`install_recommended_h5p_libraries`, `manage_h5p_libraries`)
- Delete or publish other librarians' tutorials (when cross-edit is enabled)
- Access the network admin

**H5P cap blocking** is enforced at runtime via the `user_has_cap` filter in `PBSG_Roles::filter_librarian_caps()`. This prevents H5P's `assign_capabilities()` from silently re-granting admin-level H5P caps that get auto-mapped from caps librarians legitimately need (e.g., `edit_others_pages` → `install_recommended_h5p_libraries`).

**Cross-edit mode** (option `pbsg_cross_edit_enabled`, default `true`): when on, librarians can edit each other's tutorials but cannot delete or publish them.

### 4.4 Analytics

**Files:** `class-pbsg-analytics.php`, `class-pbsg-analytics-dashboard.php`, `assets/split-guide-tracker.js`

All analytics are **aggregate and anonymous** — no cookies, no localStorage, no IP storage, PIPEDA compliant.

**Event flow:**
1. `split-guide-tracker.js` fires AJAX events (`action=pbsg_track_event`, no nonce — students aren't logged in)
2. `PBSG_Analytics::handle_track_event()` validates event type, applies rate limiting (60 events/IP/minute via transients, hashed IP key), then increments the appropriate counter via `INSERT ... ON DUPLICATE KEY UPDATE`

**Valid event types:** `tutorial_view`, `tutorial_complete`, `slide_view`, `quiz_attempt`, `quiz_giveup`, `session_flush`

Device type (desktop/tablet/mobile) is derived from the User-Agent at ingestion time.

**Dashboard:** "Tutorial Analytics" admin page, shows overview charts, per-tutorial detail, and per-question drill-down. CSV export available.

### 4.5 Certificate Generation

**File:** `includes/class-pbsg-certificate.php`

1. Student clicks "Generate Certificate" on the summary screen
2. Frontend POSTs to `wp_ajax_pbsg_mark_completed` (logged-in only) → stores `pbsg_completed_{page_id}` in user meta
3. On success, browser navigates to `wp_ajax_pbsg_download_certificate?tutorial_id=...&name=...&final_score=...&nonce=...`
4. Server validates nonce + login + completion meta, then calls `PBSG_Certificate::output_pdf()`
5. TCPDF renders a letter-size PDF with logo, student name, tutorial title, score, and date

**Known issue:** The certificate URL is constructed on the frontend. If the site is behind an HTTP reverse proxy (as on staging) and WordPress thinks it's HTTPS, the constructed URL may use `https://` which fails. Ensure `X-Forwarded-Proto` headers are set correctly in nginx.

### 4.6 Export / Import

**File:** `includes/class-pbsg-export-import.php`

**Export** (`wp_ajax_pbsg_export_tutorial`):
- Reads `_pbsg_steps_json`, `_pbsg_header_note`, `post_content`, and `_pbsg_cover_image_id`
- Base64-encodes all local attachment files referenced in steps
- Replaces attachment IDs with portable tokens (`att_{original_id}`)
- Outputs a `.json` file download

**Import** (`wp_ajax_pbsg_import_tutorial`):
- Accepts the JSON bundle
- Re-uploads attachments to the Media Library, builds an ID remap
- Creates a new draft page, restores step meta with remapped IDs
- Returns the new page's edit URL

Export version: `1.0`. Future schema changes should increment this and add a migration branch.

### 4.7 Template System

**File:** `includes/class-pbsg-template-manager.php`

Templates are rows in `{prefix}pbsg_tutorial_templates`. The template `steps_json` and `header_note` support placeholder tokens replaced on `create_from_template()`:

| Token | Replaced With |
|-------|--------------|
| `{{TUTORIAL_TITLE}}` | The title entered by the librarian |
| `{{AUTHOR_NAME}}` | Creator's `display_name` |
| `{{CURRENT_DATE}}` | Formatted with site's `date_format` option |
| `{{LIBRARY_CATALOG_URL}}` | Option `pbsg_library_catalog_url` |

System templates (`is_system = 1`) cannot be deleted. Custom templates are hard-deleted (no soft-delete).

### 4.8 H5P Factory (Inline Quiz Builder)

**File:** `includes/class-pbsg-h5p-factory.php`

Allows librarians to create H5P quiz content directly from the tutorial editor without visiting the H5P admin. Supports:

| Quiz Type | H5P Library |
|-----------|-------------|
| `multichoice` | `H5P.MultiChoice` |
| `blanks` | `H5P.Blanks` |
| `singlechoice` | `H5P.MultiChoice` (single-point mode) |

Uses H5P's internal `saveContent()` API. Content created here is identical to content created via the H5P native editor.

**Display flags:** Download and copyright buttons are disabled (`DISABLE_FLAGS = 1`). The embed flag is intentionally **not** set — our frontend loads quizzes via the `h5p_embed` endpoint, which checks this bit.

---

## 5. Dependencies

### 5.1 Composer (project-level)

Managed in `composer.json` at the project root. Run `lando composer install` to install.

| Package | Version | Purpose |
|---------|---------|---------|
| `roots/wordpress` | `^6.9` | WordPress core (Bedrock layout) |
| `pressbooks/pressbooks` | `dev-dev` | Pressbooks plugin |
| `pressbooks/pressbooks-book` | `dev-dev` | Book theme |
| `pressbooks/pressbooks-aldine` | `dev-dev` | Network theme |
| `pressbooks/pressbooks-cas-sso` | `dev-dev` | CAS SSO plugin |
| `pressbooks/pressbooks-saml-sso` | `dev-dev` | SAML SSO plugin |
| `pressbooks/pressbooks-network-catalog` | `dev-dev` | Network catalog |
| `wpackagist-plugin/h5p` | `^1.16` | H5P interactive content |
| `owlsdepartment/multisite-url-fixer` | `dev-main` | Fixes multisite URL issues |
| `vlucas/phpdotenv` | `^5.6` | `.env` file loading |

### 5.2 Composer (plugin-level)

Managed in `web/app/plugins/pb-split-guide/composer.json`. Run from inside the plugin directory.

| Package | Version | Purpose |
|---------|---------|---------|
| `tecnickcom/tcpdf` | (latest) | PDF generation for certificates |

### 5.3 Dev Dependencies

| Package | Purpose |
|---------|---------|
| `lucatume/wp-browser` | WordPress integration test framework |
| `squizlabs/php_codesniffer` | Code style linting |
| `pressbooks/coding-standards` | Pressbooks PHPCS ruleset |
| `wp-cli/wp-cli-bundle` | WP-CLI for test bootstrapping |

### 5.4 JavaScript (CDN / Bundled)

| Library | Source | Purpose |
|---------|--------|---------|
| jQuery | WordPress bundled | DOM manipulation in admin JS |
| Thickbox | WordPress bundled | Modal dialogs in admin |
| SortableJS | Loaded in admin | Drag-to-reorder steps |
| H5P JS | H5P plugin | `H5P.externalDispatcher` for quiz events |

---

## 6. Local Development Setup

The authoritative setup guide is **`docs/DEV-SETUP-GUIDE.md`** (note: that doc describes an older Docker Compose approach; the active setup uses Lando).

### 6.1 Active Setup (Lando)

**Prerequisites:** Docker, Lando v3.21+, Git

```bash
# Clone the repo
git clone git@github.com:qixiang03/guide-on-the-side.git
cd guide-on-the-side
git checkout develop

# Install dependencies
lando start          # Downloads images, creates containers, runs composer install
lando db-import pb_local_db.sql   # Import the baseline database

# Activate plugins
lando wp --url=https://pressbooks.test/wp/ plugin activate h5p
lando wp --url=https://pressbooks.test/wp/ plugin activate pb-split-guide

# Apply H5P fixes for the /development/ sub-site (site 39)
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_hub_is_enabled 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_has_request_user_consent 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option add h5p_h5p_site_uuid 575494e6-7409-47ce-a3e9-3a2279aca75e

# Refresh H5P content type cache
lando wp --url=https://pressbooks.test/wp/ eval '
  $plugin = H5P_Plugin::get_instance();
  $core = $plugin->get_h5p_instance("core");
  $core->updateContentTypeCache();
  echo "Done.\n";
'
```

**Access:**

| URL | Purpose |
|-----|---------|
| `https://pressbooks.test/development/wp-admin/` | Tutorial admin (site 39) |
| `https://pressbooks.test/wp/wp-admin/` | Network admin (site 1) |
| `https://pressbooks.test/development/` | Public tutorial site |

**Default credentials:** `admin` / `admin`

### 6.2 `--skip-themes --skip-plugins` Pattern

The McLuhan theme on site 39 requires its own `composer install`. When running WP-CLI `option` commands on site 39, you'll get a "Dependencies Missing" error unless you add `--skip-themes --skip-plugins`. This flag is safe for option manipulation but should not be used for commands that need the full plugin stack.

### 6.3 Running Tests

```bash
lando composer lint     # PHPCS code style check
lando composer test     # PHPUnit integration tests
```

---

## 7. Deployment (Staging Server)

The authoritative reference is **`docs/DEPLOYMENT-STAGING.md`**.

### 7.1 Server Info

| Item | Value |
|------|-------|
| External IP | `137.149.157.198` |
| SSH port | `65022` |
| OS | Ubuntu 24.04 LTS |
| Project root | `/var/www/guide-on-the-side/` |
| Group | `team8` (shared write access) |

### 7.2 Deployment Flow

Code ships to staging via Git pull (no CI pipeline yet):

```bash
ssh -p 65022 <username>@137.149.157.198
cd /var/www/guide-on-the-side
git pull origin main     # or develop
```

Lando does **not** auto-start on reboot — must be started manually:

```bash
lando start
sudo systemctl start nginx   # if nginx is not running
```

### 7.3 Proxy Architecture

Staging exposes the Lando environment (which listens on `localhost:443`) through an nginx reverse proxy on the server's LAN interface:

```
Browser → nginx (192.168.0.198:80) → Traefik (127.0.0.1:443) → Lando appserver
```

nginx rewrites all `pressbooks.test` URLs in response bodies to `137.149.157.198` using `sub_filter`. JSON-encoded URLs (`https:\/\/pressbooks.test`) require escaped variants in the config. See `docs/DEPLOYMENT-STAGING.md` for the full nginx config.

**Critical warnings:**
- Do **not** run `wp search-replace` on the database. nginx handles all URL rewriting.
- Do **not** set WordPress `siteurl`/`home` to the IP. Leave them as `pressbooks.test`.
- Do **not** create `~/.lando/config.yml` with `bindAddress: 0.0.0.0` — it conflicts with nginx.

### 7.4 `/development/` vs `/wp/` Gotcha

Most tutorial work happens on **site 39** (`/development/`). The H5P content type cache table (`wp_h5p_libraries_hub_cache`) is network-wide, but H5P settings like `h5p_hub_is_enabled` are per-site. If H5P stops working on site 39, check its `wp_39_options` — options may differ from site 1.

---

## 8. Extending the Plugin

### 8.1 Adding a New Admin Page

1. Register the page in `PB_Split_Guide_Plugin::register_admin_menu()` via `add_submenu_page()`
2. Create a template in `templates/`
3. Enqueue any page-specific scripts in `enqueue_admin_assets()` by checking `$hook`
4. For AJAX handlers: add `add_action('wp_ajax_pbsg_your_action', [$this, 'your_handler'])` in `__construct()`

### 8.2 Adding a New Step Field

1. Add the field to the normalizer: `includes/steps-normalizer.php` — add it to the output array in `normalize()` with a default value
2. Add UI in `render_metabox()` — the step is rendered as a table row by `renderStepCards()` in `admin-split-guide.js`
3. Add JS handling in `admin-split-guide.js` — `normalizeStep()`, `syncStepsFromTable()` or the card renderer
4. Add rendering in `templates/split-guide-template.php` for the frontend

### 8.3 Adding a New AJAX Endpoint

```php
// In __construct():
add_action('wp_ajax_pbsg_my_action', [$this, 'ajax_my_action']);
// For public (nopriv) endpoints:
add_action('wp_ajax_nopriv_pbsg_my_action', [$this, 'ajax_my_action']);

// Handler method:
public function ajax_my_action() {
    check_ajax_referer('your_nonce_action', 'nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error(['message' => 'Forbidden'], 403);
    // ... logic ...
    wp_send_json_success(['result' => $data]);
}
```

Pass the nonce to JS via `wp_localize_script()` in `enqueue_admin_assets()`.

### 8.4 Adding a Database Table

Follow the pattern in `PBSG_Analytics::create_tables()`:
1. Define a `const TABLE_NAME = 'pbsg_your_table'` constant
2. Write the `dbDelta()` SQL — two spaces before `PRIMARY KEY`, no trailing comma on last field
3. Call from a `register_activation_hook`
4. Add a `maybe_upgrade_schema()` method checked on `admin_init` for post-activation upgrades

### 8.5 Available Hooks

The plugin does not currently define custom `do_action`/`apply_filters` hooks for external use. If you need to hook into plugin behaviour, the standard WordPress filter surface (e.g., `save_post_page`, `template_include`) is available.

**Useful internal checkpoints:**
- `save_post_page` → `PB_Split_Guide_Plugin::save_meta()` (post ID 2nd arg is `WP_Post`)
- `wp_ajax_pbsg_track_event` → `PBSG_Analytics::handle_track_event()`
- `load-post-new.php` → template picker redirect (in `PB_Split_Guide_Plugin`)

---

## 9. Troubleshooting

### 9.1 H5P "Failed to load data" / "Last update: 1970"

**Root causes and fixes:**

| Symptom | Cause | Fix |
|---------|-------|-----|
| "Failed to load data" | `h5p_hub_is_enabled` empty or false for site 39 | `lando wp --url=.../development/ --skip-themes --skip-plugins option update h5p_hub_is_enabled 1` |
| "Last update: 1970" | `wp_h5p_libraries_hub_cache` table empty | Run `updateContentTypeCache()` via `wp eval` (see Section 6.1) |
| H5P Hub registration fails | `hub-api.h5p.org/v1/sites` returns 302→404 (upstream bug) | Copy UUID from site 1: `option add h5p_h5p_site_uuid <uuid>` on site 39 |
| Cache shows stale after fix | WordPress object cache (Redis) serving stale option values | `lando wp cache flush` |

H5P network settings (`h5p_content_type_cache_updated_at`) are stored in `wp_sitemeta`, not `wp_options`. Use `get_site_option()`/`update_site_option()` if querying directly. See also the H5P troubleshooting doc in the Week 6 deliverables folder.

### 9.2 nginx / Staging URL Issues

| Symptom | Cause | Fix |
|---------|-------|-----|
| URLs still say `pressbooks.test` in browser | `sub_filter` not matching JSON-escaped URLs | Ensure nginx config has `sub_filter 'https:\/\/pressbooks.test'` lines (with escaped slashes) |
| `ERR_CONNECTION_REFUSED` on `https://137.149.157.198` | Browser following HTTPS redirect; port 443 not open | Check `proxy_cookie_flags ~ nosecure` in nginx; ensure `X-Forwarded-Proto https` header set |
| Login loop / "cookies blocked" | `Secure` flag on session cookies over HTTP | Add `proxy_cookie_flags ~ nosecure;` to nginx config |
| Blank page after login | nginx not running | `sudo systemctl start nginx` |

### 9.3 Lando / Database

| Symptom | Fix |
|---------|-----|
| `mysqld.pid: Permission denied` on `lando start` | `lando destroy -y && docker volume rm $(docker volume ls -q \| grep osspblocal) && lando start` |
| `lando wp db query` SSL error | Run MySQL directly: `lando ssh -s database -c "mysql -u pressbooks_oss_user -psecretpassword pressbooks_oss -e 'SHOW TABLES;'"` |
| `lando wp` on site 39 throws "Dependencies Missing" | Add `--skip-themes --skip-plugins` — McLuhan theme needs its own composer run |

### 9.4 Certificate Download Error

The certificate URL is assembled client-side. If the tutorial is at `http://...` but WordPress thinks it's `https://...`, the AJAX URL may be wrong.

**Check:** Verify `admin_url('admin-ajax.php')` returns the correct URL inside the container:
```bash
lando wp --url=https://pressbooks.test/development/ eval 'echo admin_url("admin-ajax.php");'
```

TCPDF must be installed: `cd web/app/plugins/pb-split-guide && lando composer require tecnickcom/tcpdf`

### 9.5 Pressbooks Conflicts

- Pressbooks rebuilds the admin sidebar at high priority. The plugin patches `$menu`/`$submenu` at priority 1001 (after Pressbooks at 999) and uses `add_menu_classes` (fires last) for final menu ordering.
- Pressbooks intercepts `post-new.php` for its own post types. The plugin's redirect to the template picker runs on `load-post-new.php` — if Pressbooks fires first and redirects, the template picker won't show. Check action hook priorities if this breaks.
- H5P's `assign_capabilities()` runs on every admin request and may auto-grant librarians management caps. `PBSG_Roles::filter_librarian_caps()` denies these at `user_has_cap` check time — no database change needed, but it must remain registered.

### 9.6 Cross-Site Option Confusion

A common debugging mistake: checking `wp_options` for settings that are actually in `wp_sitemeta` (network-wide) or `wp_39_options` (site 39). Always include `--url=` in WP-CLI commands to target the right site. H5P hub settings are split across both — `h5p_hub_is_enabled` is per-site, `h5p_content_type_cache_updated_at` is network-wide.
