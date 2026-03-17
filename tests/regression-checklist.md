# Regression Checklist (Run every sprint / before demo)

## When to run

- After merging PRs into main/develop
- Before sprint demo
- Before release/deploy

## How to record results

For each checkbox:

- Mark ✅ Pass / ❌ Fail
- If fail: add a short note + screenshot/error message + link to issue/bug ticket

---

## Core Workflow

- [ ] ✅/❌ Create a new tutorial (TC-01)
- [ ] ✅/❌ Add a slide (TC-02)
- [ ] ✅/❌ Next/Back navigation works across multiple slides (TC-03)
- [ ] ✅/❌ Step data saves and persists after refresh (TC-05)

## Integration Layer (3rd-party friendly)

- [ ] ✅/❌ Quiz embed slide loads without breaking layout/navigation (TC-07)

## Permissions & Stability

- [ ] ✅/❌ Non-admin cannot access admin-only pages (TC-07)
- [ ] ✅/❌ Invalid/empty inputs do not crash the system (TC-08)

## Smoke Health

- [ ] ✅/❌ Core pages load without fatal errors (TC-09 / TC-10)

## Analytics & Certificate

- [ ] ✅/❌ Analytics dashboard loads and tabs work (TC-11)
- [ ] ✅/❌ Tutorial completion is recorded for certificate (TC-12)
- [ ] ✅/❌ Certificate PDF downloads after completion (TC-13)

## Librarian & Manage Librarians

- [ ] ✅/❌ Librarian login redirects to My Tutorials (TC-14)
- [ ] ✅/❌ Librarian sees only allowed menu items (TC-15)
- [ ] ✅/❌ Librarian direct URL access to restricted pages is redirected to My Tutorials (TC-16)
- [ ] ✅/❌ Admin can register new Librarian in Manage Librarians (TC-17)
- [ ] ✅/❌ Admin can deactivate Librarian and optionally reassign tutorials (TC-17)
- [ ] ✅/❌ Native Add User shows Assign Librarian prompt and role can be assigned (TC-18)
- [ ] ✅/❌ Network Users list shows GOTS Role column (TC-19)

---

## Run Log

- Date:
- Tester:
- Commit/PR version:
- Notes:
