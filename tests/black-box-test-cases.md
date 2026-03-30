# Black-box Test Cases (Guide-on-the-Side / Pressbooks)

## Purpose

These test cases validate user-facing workflows without inspecting internal implementation details.
They are designed to be repeatable by any team member.

## Test Environment

- Platform: Pressbooks local dev environment
- Browser: Chrome (latest)
- User roles tested: Admin / Librarian / Student

## Conventions

- Each test case includes Preconditions, Steps, Expected Result, Actual Result, Pass/Fail.
- Screenshots/logs are optional but recommended for failures.

---

## TC-01 — Create a New Tutorial (Admin)

**Preconditions**

- Logged in as Admin.
- Pressbooks site is running.

**Steps**

1. Navigate to the tutorial builder entry point (menu/admin page).
2. Click “Create Tutorial”.
3. Enter a tutorial title.
4. Click “Save”.

**Expected Result**

- Tutorial is created successfully.
- Tutorial appears in tutorial list/dashboard.
- No fatal errors or broken page state.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-02 — Add a Slide to an Existing Tutorial (Admin/Author)

**Preconditions**

- Logged in as Admin or Author.
- A tutorial already exists.

**Steps**

1. Open an existing tutorial.
2. Click “Add Slide”.
3. Enter slide title/content.
4. Save.

**Expected Result**

- New slide appears in the slide list.
- Slide content persists after refresh/reopen.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-03 — Slide Navigation (Next/Back)

**Preconditions**

- A tutorial exists with at least 3 slides.
- Logged in as a role allowed to view/run tutorial.

**Steps**

1. Start the tutorial.
2. Click “Next” to move from Slide 1 → Slide 2 → Slide 3.
3. Click “Back” to move Slide 3 → Slide 2 → Slide 1.

**Expected Result**

- Navigation moves to the correct slide every time.
- No skipped slides / no wrong ordering.
- No UI freeze or broken state.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-04 — Step Data Save & Reload (Core workflow)

**Preconditions**

- Logged in as Admin/Author.
- A tutorial and slide exist.
- Step editing UI exists.

**Steps**

1. Open a slide step editor (or equivalent step input form).
2. Add/update step data (e.g., instructions, selectors, metadata).
3. Save.
4. Refresh the page or reopen the tutorial editor.

**Expected Result**

- Step data persists correctly after refresh/reopen.
- No missing or duplicated step entries.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-05 — Legacy Step Data Migration (If legacy data exists)

**Preconditions**

- A tutorial exists that uses legacy/older step format (fixture or imported data).
- Logged in as Admin.

**Steps**

1. Open the legacy tutorial in editor.
2. Trigger load/migration by viewing or editing steps.
3. Save tutorial.
4. Reopen tutorial and verify steps.

**Expected Result**

- Legacy steps are converted/handled without errors.
- Behavior remains consistent (no data loss).
- No fatal errors.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-06 — Quiz Embed Loads (H5P integration layer)

**Preconditions**

- A slide includes an embedded H5P quiz (or equivalent).
- Logged in as a role allowed to view/run tutorial.

**Steps**

1. Start tutorial.
2. Navigate to the slide containing the quiz embed.
3. Wait for the quiz to render.
4. Interact minimally (e.g., click inside, answer one question if available).

**Expected Result**

- Quiz embed renders on the slide without breaking layout.
- No fatal errors in UI (we do NOT validate H5P internal logic).
- Tutorial navigation still works after interacting.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-07 — Permissions: Non-Admin Cannot Access Admin-Only Pages

**Preconditions**

- A non-admin user account exists (Author/Viewer).
- Admin-only page/route exists.

**Steps**

1. Log in as non-admin.
2. Attempt to open the admin dashboard / admin-only configuration page.

**Expected Result**

- Access is denied or page is not visible.
- No sensitive data is shown.
- System responds gracefully (no fatal error).

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-08 — Error Handling: Invalid/Empty Inputs Don’t Break the UI

**Preconditions**

- Logged in as Admin/Author.

**Steps**

1. Try creating a tutorial with an empty title (if UI allows).
2. Try saving a slide with empty content.
3. Try adding an empty step entry (if step UI exists).

**Expected Result**

- UI prevents save with clear feedback OR saves safely without crashing.
- No fatal error pages.
- No corrupted tutorial state.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-09 — Basic Page Load Health Check (Smoke-level)

**Preconditions**

- Pressbooks site is running.

**Steps**

1. Load tutorial builder page.
2. Load tutorial list/dashboard page.
3. Load a tutorial play/run page.

**Expected Result**

- All core pages load successfully (no 500/fatal error).
- Basic UI elements render.
- No obvious broken navigation.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-10 — Core Pages Load (Smoke Health)

**Preconditions**

- Pressbooks site is running.

**Steps**

1. Load tutorial builder / admin page.
2. Load tutorial list or analytics dashboard page.
3. Load a tutorial play/run page.

**Expected Result**

- All core pages load without fatal errors (no 500).
- Basic UI and navigation render.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

- Alias for regression checklist “Smoke Health”; same intent as TC-09.

---

## TC-11 — Analytics Dashboard (Admin)

**Preconditions**

- Logged in as Admin (or Librarian with `edit_pages`).
- Pressbooks site is running.
- Optionally: at least one page uses the Split Guide template (no data shows empty state).

**Steps**

1. In WordPress admin, find and click "Tutorial Analytics" in the sidebar.
2. Confirm the Overview tab loads and shows the tutorial list or empty state.
3. Optionally: switch to Tutorial Detail or Compare tab; optionally set date range or device filter and click Apply.
4. Optionally: click "Export CSV" and confirm the export entry is reachable.

**Expected Result**

- Analytics page opens without 500 errors.
- Overview, Tutorial Detail, and Compare tabs are switchable.
- Filters and export do not error (data may be empty).

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-12 — Certificate Completion Marking (Student)

**Preconditions**

- Logged in as Student (or any user who can view the tutorial).
- A tutorial page exists that uses the Split Guide template and has multiple steps and a completion flow (e.g. reaching the last step or clicking complete).

**Steps**

1. Open that tutorial page and complete all steps (or reach the last step / click complete).
2. Observe whether a "Certificate" or "Completed" message / button appears.
3. Refresh the page or re-enter the tutorial and confirm completion state persists (e.g. certificate download still available).

**Expected Result**

- Completion is recorded after finishing the tutorial.
- Certificate area or download entry appears.
- State persists after refresh; no errors.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-13 — Certificate PDF Download

**Preconditions**

- Logged in as a user who has completed the target tutorial (i.e. TC-12 or equivalent has been done).

**Steps**

1. Open the completed tutorial page, or reach it from "My Tutorials" or similar.
2. Click "Download Certificate" or the equivalent PDF download button/link.
3. Confirm the browser downloads a PDF file; open it and check it shows a certificate (tutorial name, completion date, etc.).

**Expected Result**

- PDF downloads successfully.
- File opens and content is correct.
- If testable: a user who has not completed the tutorial gets an error or no download when attempting to download.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-14 — Template Picker: Create New Tutorial from Template

**Preconditions**

- Logged in as Admin or Librarian with permission to create pages/tutorials.
- At least one saved template exists (or system default template is available).

**Steps**

1. Go to **Tutorials** (Pages) and use the flow to add a new tutorial (e.g. “Add New Tutorial” / template picker if enabled).
2. Choose a template from the picker (not “blank” if testing template copy).
3. Enter a title when prompted and confirm creation.
4. Open the new draft in the editor and confirm steps/header note match the template (or empty step for blank).

**Expected Result**

- A new **draft** page is created with the Split Guide template.
- Editor loads without fatal errors; step structure is as expected.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

- Automated alignment: `PBSG_Template_Manager::create_from_template`, `ajax_create_from_template` — see `tests/Unit/PBSGTemplateManagerTest.php`, `tests/Unit/PBSGPluginAjaxHandlersTest.php`.

---

## TC-15 — Save Current Tutorial as Template

**Preconditions**

- Logged in as Admin or Librarian.
- A draft or published Split Guide tutorial exists with at least one configured step.

**Steps**

1. Open the tutorial in the block/editor screen used for Split Guide steps.
2. Use **Save as template** (or equivalent) from the template picker UI; enter name, optional description/category.
3. Confirm success message.
4. Start “new from template” flow and verify the new template appears in the list.

**Expected Result**

- Template is saved; appears in template list; steps and header note are preserved.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

- Automated alignment: `ajax_save_as_template`, `PBSG_Template_Manager::save_as_template` — see `tests/Unit/PBSGPluginAjaxHandlersTest.php`, `tests/Unit/PBSGTemplateManagerTest.php`.

---

## TC-16 — Export Tutorial as Portable JSON

**Preconditions**

- Logged in as a user who can edit the target tutorial.
- A Split Guide tutorial exists (optional: with file attachment steps to validate `att_*` tokens in export).

**Steps**

1. Open the tutorial editor for that page.
2. Trigger **Export** (export tutorial as JSON / Guide on the Side package).
3. Save the downloaded `.json` file; open in a text editor and confirm keys such as `pbsg_version`, `title`, `steps`, and (if applicable) `attachments`.

**Expected Result**

- File downloads; JSON is valid; no permission error for authorized editor.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

- Automated alignment: export permission and missing-post guards — `tests/Unit/PBSGExportImportTest.php` (`handle_export`).

---

## TC-17 — Import Tutorial from JSON (New Draft)

**Preconditions**

- Logged in as a user with `edit_pages` (e.g. Admin / Librarian).
- A valid export file from TC-16 (or a known-good fixture).

**Steps**

1. Use **Import tutorial** (AJAX/upload flow) and select the `.json` file.
2. Confirm success and follow the link to edit the new draft.
3. Verify title, steps, and header note; re-uploaded attachments open correctly if the package included files.

**Expected Result**

- New **draft** tutorial created; edit URL works; invalid file shows a clear error (no white screen).

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

- Automated alignment: `PBSG_Export_Import::handle_import` — `tests/Unit/PBSGExportImportTest.php`.

---

## Reference — Automated coverage (pb-split-guide)

The following complement black-box runs; they do **not** replace browser verification.

| Area | Primary test files |
|------|-------------------|
| Plugin wiring / hooks | `tests/Integration/PBSplitGuidePluginSmokeTest.php` (tag-based hook assertions) |
| Template DB / CRUD | `tests/Unit/PBSGTemplateManagerTest.php` |
| Export / import AJAX | `tests/Unit/PBSGExportImportTest.php` |
| H5P factory (params / reverse / errors) | `tests/Unit/PBSGH5PFactoryTest.php` |
| Plugin AJAX (templates, upload, H5P, list tutorials) | `tests/Unit/PBSGPluginAjaxHandlersTest.php`, `tests/Unit/PBSGH5PAjaxTest.php` |
| Accessibility (PDF/EPUB CSS hooks) | `tests/Unit/PBSGAccessibilityEnhancerTest.php` |
| JS — tracker / admin utils / badges | `tests/js/*.test.js` |
| JS — compare URL ids | `tests/js/analytics-dashboard-compare-url.test.js` |
| JS — step menu a11y | `tests/js/split-guide-menu.test.js` |
