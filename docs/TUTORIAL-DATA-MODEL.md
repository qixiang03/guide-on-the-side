# Guide on the Side - Tutorial Data Model

**Author:** Daniel McGrath (Tech Lead)
**Date:** February 2, 2026 — Updated March 23, 2026
**Sprint:** 3 (original) / 7 (updated)
**Status:** Updated to reflect actual implementation

---

## Overview

This document defines the data model for the Guide on the Side tutorial system as implemented. The system supports:

- Tutorial creation and management by librarians
- Draft/publish workflow (WordPress native)
- Reusable templates (custom DB table, Sprint 7)
- Multi-step tutorials with H5P quizzes and embedded resources
- Simple branching for remediation
- Anonymous student usage (no tracking)
- Export/import of tutorials as portable `.json` files (Sprint 7)

> **Implementation note:** The Sprint 3 draft proposed custom `tutorials` and `tutorial_steps` tables. The final implementation chose the **postmeta approach** instead — tutorial content and steps are stored as WordPress page post meta (JSON). This integrates better with the WordPress admin, draft/publish, and revision system. The custom table approach is used only for the templates feature (Sprint 7). See the Integration Notes section for the full rationale.

---

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| H5P handles quiz logic | H5P already manages questions, answers, feedback, and scoring internally. We only store a reference ID. |
| No user tracking | Project requirement - students are anonymous, no login required, no personal data stored. |
| WordPress handles revisions | WordPress has built-in revision history, no need for custom history table. |
| Slide-level branching only | Keep v1 simple. Cross-tutorial branching can be added in v2. |

---

## Entity Relationship Diagram

```
┌─────────────────────┐
│     TUTORIALS       │
├─────────────────────┤
│ id (PK)             │
│ title               │
│ description         │
│ learning_objectives │
│ status              │
│ is_template         │
│ created_by (FK)     │
│ created_at          │
│ updated_at          │
└─────────┬───────────┘
          │
          │ 1:N (one tutorial has many steps)
          │
          ▼
┌─────────────────────┐
│   TUTORIAL_STEPS    │
├─────────────────────┤
│ id (PK)             │
│ tutorial_id (FK)    │──────────┐
│ title               │          │
│ left_content        │          │
│ h5p_content_id      │          │ branch_to_step_id
│ iframe_url          │          │ (self-referencing FK)
│ fallback_content    │          │
│ order_index         │          │
│ branch_to_step_id   │◄─────────┘
└─────────────────────┘
```

---

## Table Definitions

### tutorials

The main container for a tutorial or template.

| Field | Type | Null | Key | Default | Description |
|-------|------|------|-----|---------|-------------|
| id | INT | NO | PK | AUTO_INCREMENT | Unique identifier |
| title | VARCHAR(255) | NO | | | Tutorial title displayed to users |
| description | TEXT | YES | | NULL | Brief summary of the tutorial |
| learning_objectives | TEXT | YES | | NULL | "What You'll Learn" bullet points |
| status | ENUM('draft', 'published') | NO | | 'draft' | Publication status |
| is_template | BOOLEAN | NO | | FALSE | If true, can be copied to create new tutorials |
| created_by | BIGINT UNSIGNED | NO | FK | | WordPress user ID of the librarian |
| created_at | DATETIME | NO | | CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | DATETIME | NO | | CURRENT_TIMESTAMP | Last modification timestamp |

**SQL:**
```sql
CREATE TABLE tutorials (
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
```

---

### tutorial_steps

Individual slides/pages within a tutorial.

| Field | Type | Null | Key | Default | Description |
|-------|------|------|-----|---------|-------------|
| id | INT | NO | PK | AUTO_INCREMENT | Unique identifier |
| tutorial_id | INT UNSIGNED | NO | FK | | Parent tutorial |
| title | VARCHAR(255) | YES | | NULL | Step title (e.g., "Introduction", "Quiz 1") |
| left_content | TEXT | YES | | NULL | HTML content for left pane (instructions, text) |
| h5p_content_id | INT | YES | | NULL | H5P content ID for quiz/interactive element |
| iframe_url | VARCHAR(500) | YES | | NULL | URL to embed in right pane |
| fallback_content | TEXT | YES | | NULL | Shown if iframe fails to load |
| order_index | INT | NO | | | Step order (1, 2, 3...) |
| branch_to_step_id | INT UNSIGNED | YES | FK | NULL | Step to jump to on incorrect answer (remediation) |

**SQL:**
```sql
CREATE TABLE tutorial_steps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tutorial_id INT UNSIGNED NOT NULL,
    title VARCHAR(255),
    left_content TEXT,
    h5p_content_id INT,
    iframe_url VARCHAR(500),
    fallback_content TEXT,
    order_index INT NOT NULL,
    branch_to_step_id INT UNSIGNED,
    
    FOREIGN KEY (tutorial_id) REFERENCES tutorials(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_to_step_id) REFERENCES tutorial_steps(id) ON DELETE SET NULL
);
```

---

## Branching Logic

### How It Works

1. Student answers H5P quiz question incorrectly
2. H5P reports result to our system
3. If `branch_to_step_id` is set, redirect to that step (remediation)
4. Remediation step explains the concept
5. Student returns to original step or continues forward

### Example

```
Step 1 (order_index: 1)
├── title: "What is a Library Catalog?"
├── h5p_content_id: 5
├── iframe_url: "https://library.upei.ca/catalog"
├── branch_to_step_id: 4  ← On wrong answer, go to step 4
│
Step 2 (order_index: 2)
├── title: "Advanced Search"
├── ...
│
Step 3 (order_index: 3)
├── title: "Summary"
├── ...
│
Step 4 (order_index: 4)  ← Remediation step
├── title: "Catalog Basics Review"
├── left_content: "Let's review what a catalog is..."
├── h5p_content_id: 8  ← Simpler quiz
├── branch_to_step_id: NULL  ← After this, return to normal flow
```

---

## Sample Data

### tutorials

| id | title | description | status | is_template | created_by |
|----|-------|-------------|--------|-------------|------------|
| 1 | Searching the Library Catalog | Learn to find books and articles | published | FALSE | 1 |
| 2 | Database Research Basics | Introduction to research databases | draft | FALSE | 1 |
| 3 | Basic Tutorial Template | Starting point for new tutorials | published | TRUE | 1 |

### tutorial_steps

| id | tutorial_id | title | h5p_content_id | iframe_url | order_index | branch_to_step_id |
|----|-------------|-------|----------------|------------|-------------|-------------------|
| 1 | 1 | Welcome | NULL | NULL | 1 | NULL |
| 2 | 1 | Finding Books | 5 | https://library.upei.ca/catalog | 2 | 5 |
| 3 | 1 | Advanced Search | 6 | https://library.upei.ca/catalog | 3 | NULL |
| 4 | 1 | Summary | 7 | NULL | 4 | NULL |
| 5 | 1 | Catalog Basics (Remediation) | 8 | https://youtube.com/embed/abc123 | 5 | NULL |

---

## Actual Implementation (Sprint 3 onwards)

Tutorials are stored as standard WordPress **pages** with the `split-guide-template.php` page template assigned. All step data lives in post meta.

### Post Meta Keys

| Meta Key | Type | Description |
|----------|------|-------------|
| `_wp_page_template` | string | `split-guide-template.php` — identifies page as a split guide tutorial |
| `_pbsg_steps_json` | JSON string | Array of step objects (see Step Object below) |
| `_pbsg_header_note` | string | Optional text shown at the top of the tutorial |

### Step Object (inside `_pbsg_steps_json`)

Each element of the steps array:

```json
{
  "title": "Step title",
  "tutorial_type": "url | h5p | attachment",
  "tutorial_url": "https://...",
  "h5p_id": 0,
  "tutorial_attachment_id": 0,
  "tutorial_file_name": "",
  "tutorial_file_url": "",
  "url": "https://...",
  "branch_mode": "none | review",
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

---

## wp_pbsg_tutorial_templates (Sprint 7)

Custom table for saved tutorial templates. Created on plugin activation and on `admin_init` if missing.

| Column | Type | Notes |
|--------|------|-------|
| id | BIGINT UNSIGNED | Auto increment primary key |
| name | VARCHAR(200) | Template display name |
| description | TEXT | Optional description shown on picker card |
| category | VARCHAR(100) | e.g. "General", "Research" |
| is_system | TINYINT(1) | 1 = built-in (undeletable), 0 = user-created |
| steps_json | LONGTEXT | Full step configuration copied from source tutorial |
| header_note | VARCHAR(500) | Header note from source tutorial |
| created_by | BIGINT UNSIGNED | User ID of creator |
| created_at | DATETIME | Auto-set on insert |

**SQL:**
```sql
CREATE TABLE IF NOT EXISTS wp_pbsg_tutorial_templates (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(200)    NOT NULL,
    description TEXT,
    category    VARCHAR(100)    DEFAULT '',
    is_system   TINYINT(1)      DEFAULT 0,
    steps_json  LONGTEXT        NOT NULL DEFAULT '[]',
    header_note VARCHAR(500)    DEFAULT '',
    created_by  BIGINT UNSIGNED DEFAULT 0,
    created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```

Seeded on install with one built-in template: **"Split Guide (Default)"** — one empty step. The built-in template has `is_system = 1` and cannot be deleted.

---

## Integration Notes

### WordPress Integration

The final implementation uses the **postmeta approach** rather than custom tables for tutorial content:

| Approach | Pros | Cons |
|----------|------|------|
| Custom tables | Clean schema, easy queries, clear relationships | More work to integrate with WP admin |
| Postmeta (chosen) | Native WP integration, draft/publish/revisions for free, works with existing plugins | Step data is denormalized JSON blob |

The postmeta approach was chosen because WordPress handles draft/publish, revisions, author, and page routing automatically. The only custom table is `wp_pbsg_tutorial_templates` for the reusable templates feature, where rows need to be listed independently of any post.

### H5P Integration

H5P stores its own content in these tables (already exist):
- `wp_h5p_contents` - The actual H5P content
- `wp_h5p_results` - Quiz results (if tracking enabled)

We only store `h5p_content_id` which references `wp_h5p_contents.id`.

---

## Not Included (By Design)

| Feature | Reason |
|---------|--------|
| User progress tracking | Anonymous usage - no login, no tracking per project requirements |
| Quiz answer storage | H5P handles this internally; we don't persist student answers |
| Analytics tables | Client requested no user tracking |
| Modification history | WordPress handles revisions natively |

---

## Previously Open Questions (Resolved)

| Question | Resolution |
|----------|------------|
| **Media storage** | Both approaches are supported. Steps can embed URLs (YouTube, catalog sites) or attach files uploaded to the WordPress Media Library. Attachment IDs are stored in `tutorial_attachment_id`; file URLs are cached in `tutorial_file_url`. No separate media table is needed. |

---

## Future Considerations (v2)

- **Cross-tutorial branching:** `branch_to_tutorial_id` field to jump to different tutorials
- **Prerequisites:** Tutorials that must be completed before accessing another
- **Optional analytics:** Aggregate stats only (session counts, completion rates) if client requests
- **Certificate generation:** Store certificate template settings per tutorial

---

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
