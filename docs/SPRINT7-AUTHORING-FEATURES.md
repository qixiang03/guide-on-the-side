# Sprint 7 — Authoring Interface Features (Stretch Goals 3 & 4)

**Author:** Daniel McGrath (Tech Lead)
**Sprint:** 7 (Mar 16–23, 2026)

---

## Overview

Two stretch goals implemented this sprint for the `pb-split-guide` WordPress plugin:

- **Stretch Goal 3:** Template picker — save tutorial slide configurations as reusable templates
- **Stretch Goal 4:** Export/Import — package a tutorial as a portable file and re-import it on another server

---

## Stretch Goal 3 — Template Picker

### What Was Built

When a librarian clicks **Add Tutorial**, instead of landing on a blank WordPress page editor, they now see a **template picker page** first. They choose a starting point, enter a title, and click **Create Tutorial** — which creates the page with the right template pre-applied and redirects straight to the editor.

Librarians can also **save any existing tutorial as a template** using the "Save as Template" button in the Split Guide Settings metabox.

### New Files

| File | Purpose |
|------|---------|
| `includes/class-pbsg-template-manager.php` | DB table CRUD — `get_templates()`, `save_as_template()`, `create_from_template()`, `delete_template()` |
| `templates/admin-new-tutorial.php` | Template picker UI — card grid, title input, Create Tutorial button |

### Database

New table: `wp_pbsg_tutorial_templates`

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT | Auto increment |
| name | VARCHAR(200) | Template display name |
| description | TEXT | Optional description shown on card |
| category | VARCHAR(100) | e.g. "General", "Research" |
| is_system | TINYINT | 1 = built-in (undeletable), 0 = user-created |
| steps_json | LONGTEXT | Full step configuration copied from source tutorial |
| header_note | VARCHAR(500) | Header note from source tutorial |
| created_by | BIGINT | User ID of creator |
| created_at | DATETIME | Timestamp |

Table is created on plugin activation and on `admin_init` if missing (handles already-active installs).

Seeded with one built-in template: **"Split Guide (Default)"** — one blank step, ready to edit.

### Variable Substitution in Templates

When `create_from_template()` creates a new page from a template, these tokens in the `steps_json` or `header_note` are replaced:

| Token | Replaced with |
|-------|--------------|
| `{{TUTORIAL_TITLE}}` | The title entered by the librarian |
| `{{AUTHOR_NAME}}` | Current user's display name |
| `{{CURRENT_DATE}}` | Today's date (site date format) |
| `{{LIBRARY_CATALOG_URL}}` | Value of `pbsg_library_catalog_url` site option |

### AJAX Endpoints Added

| Action | Auth | Purpose |
|--------|------|---------|
| `pbsg_get_templates` | logged-in, edit_pages | Returns all templates for the picker UI |
| `pbsg_save_as_template` | logged-in, edit_pages | Saves current tutorial's steps as a named template |
| `pbsg_create_from_template` | logged-in, edit_pages | Creates a new draft page from a template, returns edit URL |

All use nonce `pbsg_template_picker`.

### Changes to Existing Files

**`pb-split-guide.php`:**
- Added `require_once` for `class-pbsg-template-manager.php`
- `__construct()`: added hooks for redirect, picker page registration, 3 AJAX handlers, `maybe_create_tables`
- `enqueue_admin_assets()`: added `templateNonce` and `exportNonce` to `PBSG_ADMIN` localized object; added `currentTemplate` (post's template from DB) as fallback for Pressbooks which hides the `#page_template` dropdown
- Metabox render: added **"Save as Template"** button beside "Add Step"
- Activation hook: now also calls `PBSG_Template_Manager::create_tables()`
- New methods: `maybe_redirect_to_template_picker`, `register_template_picker_page`, `render_template_picker_page`, `ajax_get_templates`, `ajax_save_as_template`, `ajax_create_from_template`

**`assets/admin-split-guide.js`:**
- `isSplitGuideTemplateSelected()`: added fallback to `PBSG_ADMIN.currentTemplate` when Pressbooks removes the `#page_template` DOM element (fixes metabox being hidden on the edit page)
- Added "Save as Template" Thickbox modal handler (name / description / category fields)

### Pressbooks Compatibility Notes

- The template picker page must be accessed from `pressbooks.test/development/wp-admin/`, NOT from `pressbooks.test/wp/wp-admin/`. The `/wp/` root site is the Pressbooks network hub and strips non-Pressbooks admin scripts and metaboxes.
- The McLuhan/pressbooks-book theme on `/development/` requires `composer install` inside `web/app/themes/pressbooks-book/` before the subsite admin loads.
- Pressbooks hides the `#page_template` select from the DOM. The `isSplitGuideTemplateSelected()` JS function was patched to fall back to `PBSG_ADMIN.currentTemplate` (set server-side from post meta).

---

## Stretch Goal 4 — Export / Import

### What Was Built

Librarians can **export** any tutorial from the My Tutorials page as a self-contained `.json` file. That file can be sent to a librarian on a different server who can **import** it — the importer re-uploads any embedded file attachments to the new server's media library and creates a new draft tutorial.

### New File

| File | Purpose |
|------|---------|
| `includes/class-pbsg-export-import.php` | `handle_export()` streams a `.json` download; `handle_import()` re-uploads attachments and creates a new draft page |

### Export Format

```json
{
  "pbsg_version": "1.0",
  "exported_at": "2026-03-23T...",
  "title": "Tutorial Title",
  "post_content": "...",
  "header_note": "...",
  "cover_id": "att_5",
  "steps": [ ... ],
  "attachments": [
    {
      "original_id": 5,
      "filename": "tutorial.pdf",
      "mime_type": "application/pdf",
      "data": "<base64-encoded file contents>"
    }
  ]
}
```

**Portable attachment tokens:** Local attachment IDs (e.g. `5`) are replaced with portable tokens (`att_5`) in the `steps` array. The importer re-uploads each attachment, gets a new ID, and remaps the tokens before saving.

### AJAX Endpoints Added

| Action | Auth | Purpose |
|--------|------|---------|
| `pbsg_export_tutorial` | logged-in, edit_post | Streams `.json` file download (plain form POST, not XHR) |
| `pbsg_import_tutorial` | logged-in, edit_pages | Accepts multipart file upload, creates new draft, returns edit URL |

Both use nonce `pbsg_export_import`.

### Changes to Existing Files

**`pb-split-guide.php`:**
- Added `require_once` for `class-pbsg-export-import.php`
- Boot: added `PBSG_Export_Import::init()`
- `get_my_tutorials_data()`: added `post_id` to each tutorial item (needed for export form)

**`templates/admin-my-tutorials.php`:**
- Added **Import Tutorial** panel at top (file input + AJAX upload with success/error feedback and link to edit imported tutorial)
- Added **Export** button on each tutorial card (plain `<form>` POST to `admin-ajax.php` — browser handles the file download natively)

---

## Behaviour Notes & Gotchas

### Save as Template reads from the live editor — no save required
The JS passes the current `#pbsg_steps_json` DOM value directly in the AJAX call.
The PHP handler uses this live data, falling back to DB only if nothing was sent.
This means you can click "Save as Template" at any point without saving the post first —
the template will capture whatever is currently in the editor.

### Export requires the tutorial to be saved first
The export handler reads from `get_post_meta()`. Unpublished / unsaved edits are not
included. Always **Save Draft or Publish** before exporting.

### My Tutorials only shows published tutorials
`get_my_tutorials_data()` queries with `post_status = 'publish'`. Draft tutorials do not
appear on the My Tutorials card grid — and therefore have no Export button. To export,
publish the tutorial first.

### Blank template produces a blank result (expected)
If "Save as Template" is clicked before adding any steps, the template stores an empty
steps array `[]`. Creating a tutorial from it starts with zero steps. This is correct
behaviour — not a bug.

### Template picker only shows two built-in options on a fresh install
On a fresh DB the `wp_pbsg_tutorial_templates` table is seeded with only
"Split Guide (Default)". Any additional templates a librarian sees are ones they saved
themselves via "Save as Template". There is no way for librarians to delete the built-in
template (`is_system = 1`).

### Use /development/ admin, not /wp/ admin
All tutorial editing must be done from `pressbooks.test/development/wp-admin/`.
The `/wp/` root site is Pressbooks's network management hub — it strips non-Pressbooks
admin scripts, which causes our metabox and JS to not load at all.

---

## Testing Checklist

> **Reagan:** All testing must be done from `https://pressbooks.test/development/wp-admin/`.
> Do not use `pressbooks.test/wp/wp-admin/` — the plugin UI does not load there.

### Template Picker — Stretch Goal 3

**Setup:** Start from the development site admin sidebar → Tutorials → Add Tutorial.

- [x] Tutorials → Add Tutorial → redirects to picker page (not blank WordPress editor)
- [x] "Start from scratch" card is selectable (blue border on click)
- [x] "Split Guide (Default)" card visible with "Built-in" badge
- [ ] Entering a title without selecting a card → error message "Please choose a starting point"
- [x] Entering a title and clicking Create Tutorial → redirects to edit page
- [x] Edit page shows Split Guide Settings metabox with steps table
- [ ] Leaving title blank and clicking Create Tutorial → error message shown

**Save as Template:**

- [x] "Save as Template" button appears in metabox beside "Add Step"
- [x] Clicking it opens a modal (name, description, category fields)
- [x] No save required — template captures current editor state directly
- [x] Saving a template → it appears as a card on the next Add Tutorial visit
- [x] Creating a tutorial from a saved template → new edit page opens with those steps pre-filled
- [x] Creating from a blank/empty template → new tutorial opens with zero steps (expected)

### Export / Import — Stretch Goal 4

**Setup:** Tutorial must be **published** before Export is available (drafts do not appear on My Tutorials).

- [x] My Tutorials page shows Import Tutorial panel at top
- [x] Published tutorial card has an Export button
- [x] Clicking Export → `.json` file downloads immediately
- [x] Exported file contains `pbsg_version`, `title`, `steps`, `attachments` keys
- [x] My Tutorials → Import panel → choose exported `.json` → click Import
- [x] Success message: "Tutorial [name] imported successfully. Edit it now."
- [x] "Edit it now" link opens new draft edit page with steps intact
- [ ] Imported tutorial does **not** appear on My Tutorials automatically (it is a draft — must publish it first)
- [ ] Importing a non-PBSG `.json` file → error message "Invalid export file format"
- [ ] Importing with no file selected → error message shown

### Known Behaviours (not bugs)
- Imported tutorials land as **drafts**, not published. They will not appear on My Tutorials until published.
- Export does not include H5P quiz content — only the H5P ID reference. The target server must have the same H5P content ID for the quiz to load.
- Export file size scales with uploaded file attachments (PDFs etc). URL-based tutorials export with no attachments.

---

## AI Disclosure

This sprint's implementation and documentation were completed with assistance from Claude Code (Anthropic). Per course policy, all AI-assisted work is disclosed.
