# Usability Testing Report — pb-split-guide
**Sprint 8 | CS4820 Team 8**
**Date:** 2026-03-31
**Author:** Daniel McGrath (Tech Lead)

> **AI disclosure:** This report was produced with assistance from Claude (Anthropic). The persona profiles, walkthrough analysis, and recommendations are based on real project artifacts (black-box test cases, Sprint 7 feature docs, plugin README, and live server debugging conducted during Sprint 8). Per course policy, AI assistance is disclosed here.

---

## 1. Purpose

This report documents simulated usability walkthroughs for the `pb-split-guide` Pressbooks plugin. Three representative personas were defined and each was walked through Reagan's black-box test cases (TC-01 through TC-13) to identify:

- Points of confusion
- Hard-to-find features
- Missing user feedback
- Flow problems

Findings are grouped by persona and then aggregated into cross-cutting themes with recommendations.

---

## 2. Scope

**Plugin version:** develop branch as of 2026-03-31 (post-Sprint-8 hotfix, see note below)
**Server:** `VirtualProjectServer08` (137.149.157.198)
**Features in scope:** Core tutorial authoring, template picker (Sprint 7 SG3), export/import (Sprint 7 SG4), analytics dashboard, certificate generation, RBAC/librarian management, accessibility dashboard.
**Test cases used:** TC-01 through TC-13 from `tests/black-box-test-cases.md`.

> **Sprint 8 regression note:** During Sprint 8 server deployment, it was discovered that Enzo's merge `8f14a70` (2026-03-24) silently deleted six template picker method implementations from `pb-split-guide.php` and regressed `templates/admin-my-tutorials.php` to a pre-Sprint-7 version. The `development/wp-admin/` returned HTTP 500 as a result. Both files were hotfixed on the server by restoring code from commit `c235882`. See `docs/SERVER-DEPLOYMENT-FIX.md` for the full fix procedure. The usability walkthrough below reflects the system **after** these fixes were applied.

---

## 3. Personas

### Persona A — Margaret Chen (Librarian)

| Attribute | Detail |
|-----------|--------|
| Role | Academic librarian, 12 years at UPEI |
| Primary job | Instruction librarian — runs 1-on-1 and group research sessions |
| Tech comfort | Moderate. Fluent with email, LibGuides, Google Docs, Canvas LMS. Has used Guide-on-the-Side before. Not a developer — has never touched WordPress admin before this project. |
| Device | Windows 11 laptop, Chrome |
| Goal | Build interactive tutorials to replace paper handouts; embed catalog demos and H5P quizzes so students can practice during sessions |
| Mental model | Thinks in terms of "slides" (like PowerPoint) rather than "steps." Expects a Word-processor-like editor, not a developer metabox. |
| Frustration triggers | Technical jargon, having to search for save buttons, silent failures |

---

### Persona B — Dr. James Foster (Admin)

| Attribute | Detail |
|-----------|--------|
| Role | Systems librarian / Pressbooks site administrator |
| Primary job | Manages the Pressbooks server, user accounts, and plugin configurations. Is the primary contact when something breaks. |
| Tech comfort | High. Comfortable with WordPress admin, cPanel, databases, SSH. Not a developer but can read error logs. Understands concepts like "roles" and "capabilities." |
| Device | MacBook Pro, Chrome, sometimes Firefox for testing |
| Goal | Keep the system healthy, onboard new librarians quickly, track tutorial usage via analytics, ensure students can't access admin areas |
| Mental model | Thinks in terms of roles, permissions, and system health. Wants audit trails. Expects clean error messages, not PHP stack traces. |
| Frustration triggers | Ambiguous URLs, no error context, silent permission failures |

---

### Persona C — Priya Patel (Student)

| Attribute | Detail |
|-----------|--------|
| Role | First-year undergrad, INFO 1000 |
| Primary job | Complete a mandatory library orientation tutorial to get class credit |
| Tech comfort | Moderate. Heavy user of Canvas, TikTok, Google Docs. Comfortable with modern web UIs but unfamiliar with academic software conventions. |
| Device | Chromebook (1366×768 screen), Chrome |
| Goal | Finish the tutorial, download the certificate, attach it to the Canvas assignment — ideally in under 20 minutes |
| Mental model | Expects it to work like a Canvas module: click next, answer questions, get badge/certificate at the end. Minimal tolerance for unclear navigation. |
| Frustration triggers | Anything that looks broken, long load times with no feedback, having to find a download in an unexpected location |

---

## 4. Walkthrough Results

### 4.1 Persona A — Margaret Chen (Librarian)

---

#### TC-01 — Create a New Tutorial

Margaret navigates to the WordPress admin sidebar looking for a tutorial builder. She's used to LibGuides' top-nav menus and expects something labeled "Tutorials" or "Library Instruction."

**Confusing:**
- The admin entry point isn't labeled consistently. The README says to use `pressbooks.test/development/wp-admin/` but never explains *why* that URL matters or that there's another admin at `/wp/wp-admin/` that won't work. If a colleague shares the wrong URL, Margaret will land on a stripped admin where the metaboxes and scripts are missing, with no explanation.
- On the template picker, the default template is named **"Split Guide (Default)"**. "Split Guide" is an internal technical name, not a plain-English label. Margaret's mental model is "library tutorial," not "split guide."

**Hard to find:**
- It's unclear which sidebar menu item leads to the tutorial builder. The label isn't obvious to first-time users.

**Missing feedback:**
- After "Create Tutorial" is clicked, Margaret expects a confirmation ("Tutorial created!") before being redirected to the editor. A silent redirect with no toast/banner may feel like nothing happened on a slow network.

**Flow problem:**
- The template picker is a good addition but is positioned as a developer concept. For Margaret it should be framed as "Choose a starting layout" rather than "Pick a template."

---

#### TC-02 — Add a Slide to an Existing Tutorial

Margaret opens an existing tutorial in the WordPress editor. She looks for an "Add Slide" button (her mental model from the test case wording) but the actual UI has "Add Step" inside the **Split Guide Settings metabox** on the right sidebar.

**Confusing:**
- The term "slide" (used in TC-02 and in Margaret's PowerPoint mental model) does not match "step" (the UI term). This terminological inconsistency is a recurring pain point across the whole plugin.
- The **Split Guide Settings metabox** is a WordPress concept Margaret doesn't know. She may not realize the right sidebar panel is interactive — she might try to add content directly in the main content area.
- **"Save as Template"** button appears next to "Add Step" in the same metabox. Without a tooltip, Margaret won't know what saving a template does or why it's next to the step-adding button.

**Hard to find:**
- The metabox may be collapsed or below the fold on smaller screens. A first-time user has no signal to scroll right or look in the sidebar.

**Missing feedback:**
- After clicking "Add Step," if there's a brief AJAX delay, a spinner or optimistic update is needed so Margaret doesn't click twice.

---

#### TC-03 — Slide Navigation (Next/Back)

Margaret previews her tutorial in student view. Navigation works left-to-right (Next/Back).

**Confusing:**
- The progress indicator placement isn't described in the README or Sprint 7 docs. Margaret can't tell students "look at the top right for your progress" if she doesn't know where it is.

**Hard to find:**
- "Next" and "Back" buttons need to be visible without scrolling, even when step content is long. The README states navigation is "kept visible while content scrolls internally" — but this needs verification on the 1366×768 Chromebook viewport.

**Missing feedback:**
- No "you're on step X of Y" label visible by default in the described UI. Progress indicator exists but its content format isn't specified.

---

#### TC-04 — Step Data Save & Reload

Margaret edits step content and wants to save.

**Confusing:**
- The save mechanism is ambiguous. There is the WordPress "Update" button (top right of the editor) and potentially a separate "Save" in the Split Guide Settings panel. Which one saves the step data? Are they equivalent? If Margaret clicks "Update" without knowing Split Guide data is saved separately (or together), she may lose step edits.

**Missing feedback:**
- No explicit "Steps saved" confirmation tied to the Split Guide metabox. WordPress's generic "Post updated" message doesn't tell Margaret that her step configuration was included.

---

#### TC-05 — Legacy Step Data Migration

*Not applicable to Margaret as a first-time librarian. She wouldn't have legacy tutorials. Skip.*

---

#### TC-06 — Quiz Embed Loads (H5P)

Margaret wants to embed an H5P quiz into a step.

**Confusing:**
- The H5P factory (Sprint 7) was added as a developer feature. The authoring workflow for H5P from the librarian's side is not described in the README. Margaret would need to know the H5P content ID, which she'd have to find in the H5P admin area — a different part of WP admin.

**Hard to find:**
- There's no in-editor prompt or "Insert H5P" button described in the authoring UI.

**Missing feedback:**
- If an H5P ID is typed incorrectly, does the step show a fallback message or just render blank?

---

#### TC-07 — Permissions

Margaret is a Librarian (not full Admin). With RBAC, she has `edit_pages` but shouldn't access manage-librarians.

**Confusing:**
- If Margaret accidentally navigates to the manage-librarians page, the response should explain she doesn't have access — not just a generic WP "sorry, you are not allowed" page with no context.

**Missing feedback:**
- No in-app indicator of what Margaret *can* vs. *cannot* do as a Librarian.

---

#### TC-08 — Error Handling: Invalid/Empty Inputs

Margaret tries to save a tutorial with no title in the template picker, or adds a step with no content.

**Missing feedback:**
- An inline red error message below the title field would be clearer than the browser native tooltip. For steps: if an empty step is saved, does it appear in the student view as blank?

---

#### TC-09/TC-10 — Page Load Health

Margaret loads the tutorial list, builder, and student-facing tutorial.

**Confusing:**
- If a PHP error occurs, Margaret will see a white/broken page with no message. She doesn't know whether to refresh, log out, or report it.

**Missing feedback:**
- A user-facing error page ("Something went wrong — please contact your administrator") is needed for non-fatal plugin errors.

---

#### TC-11 — Analytics Dashboard

Margaret has `edit_pages` and can view the analytics dashboard.

**Confusing:**
- "Compare" tab doesn't indicate what is being compared (two tutorials? time periods?). Without a subtitle, Margaret may not click it.
- The "Export CSV" column headers may be machine-readable (`avg_time_ms`, `completion_rate_pct`), which won't be useful if Margaret opens in Excel.

**Hard to find:**
- "Tutorial Analytics" in the WP sidebar — is it a top-level item or under a submenu? If under a submenu, new users won't find it.

---

#### TC-12 / TC-13 — Certificate

*Margaret's concern here is authoring: does she need to configure anything for the certificate to generate?* The README only says to run `lando composer require tecnickcom/tcpdf`. If that step was missed during setup, certificates silently won't work and neither Margaret nor Priya will know why.

**Missing feedback:**
- No admin-side indicator that TCPDF is installed and certificate generation is functional.

---

### 4.2 Persona B — Dr. James Foster (Admin)

---

#### TC-01 — Create a New Tutorial

James navigates to the tutorial builder to verify it works after a plugin update.

**Confusing:**
- The Pressbooks admin URL issue (`/development/wp-admin/` vs. `/wp/wp-admin/`) is the most critical admin pain point. This is documented only in a developer doc buried in Sprint 7 deliverables.

**Hard to find:**
- Variable substitution tokens (`{{TUTORIAL_TITLE}}`, `{{AUTHOR_NAME}}`, etc.) in templates are powerful but undiscoverable.

---

#### TC-02 — Add a Slide

*James does this to verify the feature works after an update, not as a regular workflow.*

**Missing feedback:**
- If an AJAX request to save step data fails (network timeout, nonce expiry), does the UI show an error? A silent failure would cause data loss that James wouldn't catch until someone reports missing steps.

---

#### TC-04 — Step Data Save & Reload

**Confusing:**
- Nonce expiry is a real risk for long editing sessions. If a user leaves the editor open for hours and then saves, the nonce may have expired, causing the AJAX save to silently fail.

**Missing feedback:**
- Session expiry warning or auto-save for long sessions.

---

#### TC-05 — Legacy Step Data Migration

James opens an old tutorial that uses the legacy step format.

**Confusing:**
- Migration happens silently. There is no admin-visible log entry or notice that says "this tutorial was migrated from legacy format."

**Missing feedback:**
- A one-time admin notice ("This tutorial was migrated from legacy format — please verify step data") would give James confidence that migration occurred.

---

#### TC-07 — Permissions: Non-Admin Cannot Access Admin-Only Pages

James creates a Librarian account and tests that she can't access manage-librarians.

**Confusing:**
- The RBAC fallback (`e88cd40`) handles edge cases, but the permission denied message is still the generic WP message.
- James needs to verify that a student (Subscriber role or lower) cannot access *any* tutorial admin pages.

**Missing feedback:**
- An admin-visible RBAC capability summary ("Librarian role has: edit_pages, read — does not have: manage_options") would help James verify configurations without reading code.

---

#### TC-08 — Error Handling: Invalid/Empty Inputs

**Hard to find:**
- James needs to know which fields are validated client-side vs. server-side. Currently undocumented.

---

#### TC-11 — Analytics Dashboard

James's primary analytics use case.

**Confusing:**
- Empty state after applying a date filter may look like a load failure rather than "no data for this period."
- "Compare" tab: it's not clear whether this compares two tutorials or two time periods.
- Device filter: "device" categories (desktop, mobile, tablet) — are these inferred from user-agent or explicitly logged?

**Missing feedback:**
- After "Export CSV," no confirmation that the export was triggered.

---

#### TC-12 / TC-13 — Certificate

**Hard to find:**
- No admin page showing which users have completed which tutorials and whether their certificates were generated.
- If TCPDF is not installed, the download silently fails. There should be a plugin health check warning James on activation.

---

### 4.3 Persona C — Priya Patel (Student)

---

#### TC-03 — Slide Navigation (Next/Back)

Priya opens the tutorial on her 1366×768 Chromebook.

**Confusing:**
- The split-screen layout puts tutorial steps on the left and embedded content on the right. On a 1366px-wide screen, both panes are narrow. If the embedded content has a minimum width, it may overflow or be partially clipped with no explanation.
- "Prev" and "Next" — are these labeled or icon-only? Icon-only arrows are not accessible.

**Hard to find:**
- If the progress indicator doesn't show a count ("Step 3 of 8"), Priya can't estimate time to completion.

**Missing feedback:**
- No "you're almost done!" signal as she approaches the last step.

---

#### TC-06 — Quiz Embed Loads (H5P)

Priya reaches a step with an embedded H5P quiz.

**Confusing:**
- H5P content can take 2–5 seconds to initialize. If the spinner isn't clearly labeled ("Loading quiz…"), Priya may click Next thinking the step is empty.

**Missing feedback:**
- No H5P load failure message in the tutorial UI. If the quiz silently fails to load, Priya loses the interactivity without knowing it.

**Flow problem:**
- If completing the H5P quiz is required to unlock "Next," but the quiz didn't load, Priya is stuck with no fallback.

---

#### TC-07 — Permissions

Priya somehow navigates to an admin URL.

**Confusing:**
- WordPress's "You do not have sufficient permissions to access this page" gives no context. "This area is for library staff only" would reassure her.

---

#### TC-09/TC-10 — Page Load Health

Priya loads the tutorial page.

**Missing feedback:**
- No loading state while H5P/iframes initialize. On a slow connection, Priya may click back and retry, creating a duplicate session in analytics.

---

#### TC-12 — Certificate Completion Marking

Priya finishes the last step of the tutorial.

**Confusing:**
- The completion trigger is not clearly specified: is it reaching the last step, clicking "Next" on the last step, or a separate "Complete Tutorial" button?

**Hard to find:**
- If the certificate UI appears in the left pane and Priya is focused on the right pane (embedded content), she may miss the completion confirmation.

**Missing feedback:**
- A prominent success state ("Congratulations, you've completed [Tutorial Name]!") with a visible Download Certificate button.

**Flow problem:**
- If Priya refreshes mid-tutorial, does she resume where she left off? If she restarts from step 1, she'll be frustrated.

---

#### TC-13 — Certificate PDF Download

Priya clicks the download.

**Confusing:**
- If her WP account has no display name set, the certificate may show her username ("ppatel123") instead of her name ("Priya Patel").

**Hard to find:**
- Where is the "Download Certificate" button after she navigates away? Is there a student-facing "My Certificates" page?

**Missing feedback:**
- If the PDF download fails silently (TCPDF not installed), Priya sees nothing when she clicks the button.

---

## 5. Aggregated Findings

### Finding F-01 — Terminology Inconsistency: "Slide" vs "Step" (Severity: High)

**Affects:** Margaret (Librarian), TC-02, TC-03, TC-04
The word **"slide"** is used in test cases and in user-facing documentation, while the plugin UI uses **"step."** This creates confusion for all non-technical users. Recommendation: Standardize on "step" everywhere — test cases, README, UI labels, admin notices.

---

### Finding F-02 — Missing Save Feedback in Step Editor (Severity: High)

**Affects:** Margaret (Librarian), TC-04
The WordPress "Post updated" banner does not confirm that Split Guide step data was saved. Recommendation: Add a small "Steps saved" notice (green toast or metabox status label) after a successful step-data AJAX save.

---

### Finding F-03 — Wrong Admin URL Has No Warning (Severity: High)

**Affects:** Dr. James (Admin), TC-01, all admin TCs
The plugin only works at `/development/wp-admin/`, not at `/wp/wp-admin/`. Documented only in a developer Sprint 7 doc. Any user landing on the wrong admin URL will see a broken UI with no explanation. Recommendation: Add a dismissible admin notice on `/wp/wp-admin/` that detects the wrong context and links to the correct subsite admin.

---

### Finding F-04 — No Certificate Health Check (Severity: High)

**Affects:** Dr. James (Admin), Priya (Student), TC-12, TC-13
TCPDF must be installed separately via Composer for certificate generation to work. If missing, certificate download silently fails. Recommendation: Add a plugin activation check for the TCPDF class and display an admin notice if missing.

---

### Finding F-05 — Completion Flow Ambiguity (Severity: High)

**Affects:** Priya (Student), TC-12
The completion trigger and post-completion state are not clearly defined. Recommendation: Add an explicit "Complete Tutorial" button on the last step, followed by a full-width completion success state with a certificate download button.

---

### Finding F-06 — Analytics "Compare" Tab Unclear (Severity: Medium)

**Affects:** Dr. James (Admin), Margaret (Librarian), TC-11
The "Compare" analytics tab has no subtitle describing what it compares. Recommendation: Add a one-line description under the tab name and label all filter controls clearly.

---

### Finding F-07 — Template Picker Jargon (Severity: Medium)

**Affects:** Margaret (Librarian), TC-01
"Split Guide (Default)" uses a technical internal name. "Save as Template" is beside "Add Step" without explanation. Recommendation: Rename the default template to "Standard Library Tutorial." Add a tooltip on "Save as Template."

---

### Finding F-08 — H5P Load Failure Has No Student-Facing Fallback (Severity: Medium)

**Affects:** Priya (Student), TC-06
If an H5P quiz fails to load, the step renders blank and the student may be stuck. Recommendation: Wrap the H5P embed in a load-error handler that shows a fallback message and optionally allows skipping.

---

### Finding F-09 — Certificate Shows Username Instead of Display Name (Severity: Medium)

**Affects:** Priya (Student), TC-13
If a student's WP account has no display name, the certificate shows their username. Recommendation: Fall back from display_name → first + last name → user_email → user_login, and warn the student on the completion screen if only a username is available.

---

### Finding F-10 — No In-Progress State Persistence (Severity: Medium)

**Affects:** Priya (Student), TC-12
Page refresh mid-tutorial may reset the student to step 1. Recommendation: Persist the current step index in localStorage (keyed by post ID + user ID) so refresh resumes from the same step.

---

### Finding F-11 — Generic Permission Denied Messages (Severity: Low)

**Affects:** Margaret (Librarian), Priya (Student), TC-07
WordPress's default "You do not have sufficient permissions" gives no context. Recommendation: Override with role-specific messages ("This page is for library staff only" for students, "This page requires administrator privileges" for librarians).

---

### Finding F-12 — Export File Named Ambiguously (Severity: Low)

**Affects:** Margaret (Librarian), Sprint 7 SG4
The exported `.json` file name format is unspecified. If it's named `export.json`, Margaret won't know which tutorial it is. Recommendation: Name the export file `<tutorial-slug>-<date>.json`.

---

## 6. Recommendations Summary

| # | Finding | Severity | Recommended Action |
|---|---------|----------|--------------------|
| F-01 | Terminology: slide vs. step | High | Standardize on "step" everywhere |
| F-02 | No step-save confirmation | High | Add "Steps saved" toast after AJAX save |
| F-03 | Wrong admin URL has no warning | High | Admin notice on `/wp/wp-admin/` with redirect link |
| F-04 | No TCPDF health check | High | Plugin activation check + admin notice |
| F-05 | Completion trigger ambiguous | High | Explicit "Complete Tutorial" button + success state |
| F-06 | "Compare" tab unclear | Medium | Add subtitle; label all filter controls |
| F-07 | Template picker jargon | Medium | Rename default template; add tooltips |
| F-08 | H5P load failure no fallback | Medium | Error container + skip/contact option |
| F-09 | Certificate shows username | Medium | Display name fallback chain + profile notice |
| F-10 | No in-progress step persistence | Medium | LocalStorage step resume |
| F-11 | Generic permission denied | Low | Plugin-specific override messages |
| F-12 | Export file name unclear | Low | Name file `<slug>-<date>.json` |

---

## 7. Methodology Note

This was a **simulated** usability walkthrough: no live sessions were conducted with real users. Each persona was walked through the test cases using artifacts available in the repository (test cases, plugin README, Sprint 7 feature docs, git history, and code). Findings reflect inferred friction points based on the described UI and documented feature behaviour as experienced on the live server at `137.149.157.198`.

For Sprint 9, the team should consider:
- Running at least one live think-aloud session with a real library staff member (Librarian/Admin persona).
- Running a live session with a volunteer student.
- Re-executing TC-12 and TC-13 end-to-end with TCPDF installed to validate the completion + certificate flow.
- Implementing a post-merge smoke check to catch server regressions early.

---

## Appendix — Sprint 8 Pre-Testing Regression (Process Note)

Before this walkthrough could be conducted, two files required hotfixing on the server. This is documented here for completeness and is not a user-facing finding.

Enzo's merge `8f14a70` (2026-03-24) silently deleted six template picker method bodies from `pb-split-guide.php` while leaving their hook registrations intact (causing a PHP fatal on every admin page load), and regressed `templates/admin-my-tutorials.php` to a pre-Sprint-7 version (removing the "Add Tutorial" link, Import panel, and Export buttons). Both were restored from commit `c235882` before testing.

**Process recommendation:** Add a CI step (or a simple `curl` check in GitHub Actions) that verifies `development/wp-admin/` returns HTTP 302 after any merge to develop. Full details in `docs/SERVER-DEPLOYMENT-FIX.md`.
