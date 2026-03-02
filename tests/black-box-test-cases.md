# Black-box Test Cases (Guide-on-the-Side / Pressbooks)

## Purpose

These test cases validate user-facing workflows without inspecting internal implementation details.
They are designed to be repeatable by any team member.

## Test Environment

- Platform: Pressbooks local dev environment
- Browser: Chrome (latest)
- User roles tested: Admin / Author / Viewer (as applicable)

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
