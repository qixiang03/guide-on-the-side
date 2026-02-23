# Guide on the Side - Tutorial Storage System

**Author:** Daniel McGrath (Tech Lead)  
**Sprint:** 4

---

## Summary

This document compares the Sprint 3 data model against the existing Pressbooks schema to determine if custom tables are needed or if we can use existing WordPress/Pressbooks tables.

**Recommendation:** Use custom tables (`wp_tutorials` and `wp_tutorial_steps`)

---

## Pressbooks Database Structure

Pressbooks runs on **WordPress Multisite** with two sites:

| Blog ID | Path | Purpose |
|---------|------|---------|
| 1 | `/` | Network admin dashboard |
| 39 | `/development/` | Book content (chapters, parts, etc.) |

Each sub-site gets its own table set (`wp_39_posts`, `wp_39_postmeta`, etc.).

### Pressbooks Custom Post Types

| Post Type | Purpose |
|-----------|---------|
| `part` | Book sections (e.g., "Main Body") |
| `chapter` | Chapters within parts |
| `front-matter` | Introduction sections |
| `back-matter` | Appendix sections |
| `metadata` | Book-level metadata |

### Pressbooks Custom Tables

| Table | Purpose |
|-------|---------|
| `wp_pressbooks_catalog` | Maps users to books |
| `wp_pressbooks_tags` | User-defined tags |
| `wp_pressbooks_tracking` | Basic analytics |

Pressbooks uses **5 custom tables** alongside WordPress core — so custom tables are an established pattern.

---

## Data Model Comparison

### Can wp_posts Store Tutorials?

| Tutorial Field | wp_posts Equivalent | Fit |
|----------------|---------------------|-----|
| title | `post_title` | ✅ Direct match |
| description | `post_excerpt` | ✅ Workable |
| learning_objectives | None — needs postmeta | ❌ Poor fit |
| status | `post_status` | ✅ Direct match |
| is_template | None — needs postmeta | ❌ Poor fit |
| created_by | `post_author` | ✅ Direct match |

### Can wp_posts Store Tutorial Steps?

| Step Field | wp_posts Equivalent | Fit |
|------------|---------------------|-----|
| tutorial_id | `post_parent` | ✅ Direct match |
| title | `post_title` | ✅ Direct match |
| left_content | `post_content` | ✅ Direct match |
| h5p_content_id | None — needs postmeta | ❌ Poor fit |
| iframe_url | None — needs postmeta | ❌ Poor fit |
| fallback_content | None — needs postmeta | ❌ Poor fit |
| order_index | `menu_order` | ✅ Direct match |
| branch_to_step_id | None — needs postmeta | ❌ Poor fit |

**Result:** 5 out of 8 step fields would require postmeta lookups.

---

## H5P Status

⚠️ **No H5P plugin is installed. No H5P tables exist.**

The data model's `h5p_content_id` field references `wp_h5p_contents` which will be created when H5P is installed separately.

---

## Why Custom Tables?

| Reason | Explanation |
|--------|-------------|
| **Performance** | A 10-step tutorial would need ~50 postmeta JOINs. Custom tables: 1 query. |
| **Referential Integrity** | `branch_to_step_id` needs FK constraints. Postmeta can't enforce this. |
| **Established Pattern** | Pressbooks already uses 5 custom tables. This isn't an anti-pattern. |
| **Schema Already Designed** | Sprint 3 produced clean SQL. No need to re-engineer for wp_posts. |

---

## What We Still Get From WordPress

| Feature | How We Use It |
|---------|---------------|
| `wp_users` table | `created_by` foreign key |
| Authentication | Librarian login, admin roles |
| Admin dashboard | Plugin settings, tutorial management UI |
| Media library | If client wants file uploads |

---

## Tables to Create

From Sprint 3 data model:

```sql
CREATE TABLE wp_tutorials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    learning_objectives TEXT,
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    is_template BOOLEAN NOT NULL DEFAULT FALSE,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES wp_users(ID)
);

CREATE TABLE wp_tutorial_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tutorial_id INT UNSIGNED NOT NULL,
    title VARCHAR(255),
    left_content TEXT,
    h5p_content_id INT,
    iframe_url VARCHAR(500),
    fallback_content TEXT,
    order_index INT NOT NULL,
    branch_to_step_id INT UNSIGNED,
    FOREIGN KEY (tutorial_id) REFERENCES wp_tutorials(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_to_step_id) REFERENCES wp_tutorial_steps(id) ON DELETE SET NULL
);
```

---

## Implementation Status

**Tables created on server:** ✅ February 17, 2026

```
Database: pressbooks_oss

wp_tutorials       - 9 fields, FK to wp_users(ID)
wp_tutorial_steps  - 9 fields, FK to wp_tutorials with CASCADE delete
```

**Verified with:**
```sql
SHOW TABLES LIKE 'wp_tutorial%';
DESCRIBE wp_tutorials;
DESCRIBE wp_tutorial_steps;
```

## Next Steps (Plugin Development)

1. Create WordPress plugin in `web/wp-content/plugins/guide-on-the-side/`
2. Register admin menu pages for tutorial CRUD
3. Install H5P plugin separately — our plugin references H5P content by ID
4. Frontend rendering via shortcode or custom page template

---

## Important Notes

⚠️ **H5P must be installed separately** — It's a standalone plugin, not part of Pressbooks

⚠️ **Use WordPress table prefix** — Tables should be `wp_tutorials` not just `tutorials`

⚠️ **Sprint 3 data model is validated** — No schema changes needed

---

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
