# Guide on the Side - Tutorial Data Model

**Author:** Daniel McGrath (Tech Lead)  
**Date:** February 2, 2026  
**Sprint:** 3  
**Status:** Draft Schema for Team Review

---

## Overview

This document defines the database schema for the Guide on the Side tutorial system. The schema supports:

- Tutorial creation and management by librarians
- Draft/publish workflow
- Reusable templates
- Multi-step tutorials with H5P quizzes and embedded resources
- Simple branching for remediation
- Anonymous student usage (no tracking)

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

## Integration Notes

### WordPress Integration

These tables would be prefixed with `wp_` when created (e.g., `wp_tutorials`, `wp_tutorial_steps`) to follow WordPress conventions.

**Alternative approach:** Use WordPress custom post types and postmeta instead of custom tables. This integrates better with WordPress admin but makes complex queries harder.

| Approach | Pros | Cons |
|----------|------|------|
| Custom tables | Clean schema, easy queries, clear relationships | More work to integrate with WP admin |
| Postmeta | Native WP integration, works with existing plugins | Data spread across tables, complex joins |

**Recommendation:** Start with custom tables for cleaner data structure. We can add WordPress admin integration via a custom plugin.

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

## Open Questions for Client

| Question | Options | Impact |
|----------|---------|--------|
| **Media storage** | A) Always use iframe/embed (YouTube, external URLs) | No additional tables needed |
| | B) Allow uploading media (images, PDFs, videos) to server | May need `tutorial_media` table or use WordPress Media Library |

**Recommendation:** Clarify with client whether librarians need to upload files or if embedding external content (YouTube, library resources) is sufficient. This affects storage requirements and complexity.

---

## Future Considerations (v2)

- **Cross-tutorial branching:** `branch_to_tutorial_id` field to jump to different tutorials
- **Prerequisites:** Tutorials that must be completed before accessing another
- **Optional analytics:** Aggregate stats only (session counts, completion rates) if client requests
- **Certificate generation:** Store certificate template settings per tutorial

---

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
