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

## TC-14 — Librarian Login Redirects to My Tutorials

**Preconditions**

- A user with the Librarian (pbsg_librarian) role exists.
- Pressbooks site is running.

**Steps**

1. Log out if currently logged in.
2. Log in as the Librarian user.

**Expected Result**

- After login, the browser is redirected to the My Tutorials page (`admin.php?page=pbsg-my-tutorials`), not the default WP Dashboard.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-15 — Librarian Sees Only Allowed Menu Items

**Preconditions**

- Logged in as a user with the Librarian role.

**Steps**

1. In the WordPress admin sidebar, observe the visible menu items.

**Expected Result**

- Sidebar shows only: Dashboard, My Tutorials, Tutorials (Pages), Tutorial Analytics, Media, H5P, and Profile (or equivalent).
- The following are NOT visible: Settings, Plugins, Appearance, Users, Tools, Comments, H5P Libraries, Manage Librarians.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-16 — Librarian Direct URL Access to Restricted Pages Is Redirected

**Preconditions**

- Logged in as a user with the Librarian role.

**Steps**

1. In the browser address bar, navigate directly to `options-general.php` (or the full admin URL for Settings).
2. Then navigate directly to `admin.php?page=pbsg-manage-librarians`.

**Expected Result**

- In both cases, the user is redirected to the My Tutorials page (`admin.php?page=pbsg-my-tutorials`). Restricted admin pages are not displayed.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-17 — Admin: Manage Librarians Full Flow

**Preconditions**

- Logged in as Admin.
- Pressbooks site is running.

**Steps**

1. Open the "Manage Librarians" admin page from the sidebar.
2. Click to open the registration form and register a new Librarian (username and email required; password optional — if omitted, a generated password is emailed).
3. Confirm the new Librarian appears in the librarian list (with tutorial count and last login if available).
4. For one Librarian, trigger Deactivate; optionally choose another user to reassign their tutorials to.
5. Confirm the deactivated user no longer has Librarian access (e.g. log in as that user and verify they are redirected or have no GOTS admin menus).

**Expected Result**

- New Librarian is created and listed.
- Deactivation succeeds; the user becomes a Subscriber (or equivalent). Tutorial authorship is reassigned when a target is selected.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-18 — Native “Add User” Shows “Assign Librarian” Prompt

**Preconditions**

- Logged in as Admin (or user with permission to create users) in Network Admin.

**Steps**

1. In Network Admin, use the native “Add User” form to create a new user (do not assign the GOTS Librarian role).
2. After the user is created, load any Admin or Network Admin page (e.g. Dashboard or Users list).

**Expected Result**

- An admin notice appears: “New user … was created. Should they be a Librarian for Guide on the Side?” with an “Assign Librarian Role” link.
- Clicking the link assigns the Librarian role to that user (and redirects with success).

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-

---

## TC-19 — Network Users List Shows GOTS Role Column

**Preconditions**

- Logged in as Admin in Network Admin.
- At least one Admin and one Librarian user exist (optional: one user with no GOTS role).

**Steps**

1. Go to Network Admin → Users.
2. Inspect the list table columns.

**Expected Result**

- A “GOTS Role” column is present (e.g. after Email).
- Rows show “Admin”, “Librarian”, or “—” as appropriate for each user.

**Actual Result**

-

**Status (Pass/Fail)**

-

**Notes**

-
