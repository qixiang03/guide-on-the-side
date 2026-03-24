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

---

## Template Picker & Export/Import (Sprint 7)

- [ ] ✅/❌ Template picker blocks create when no card is selected (TC-20)
- [ ] ✅/❌ Template picker blocks create when title is empty (TC-21)
- [ ] ✅/❌ Import rejects non-PBSG JSON with clear error (TC-22)
- [ ] ✅/❌ Import blocks submission when no file is selected (TC-23)
- [ ] ✅/❌ Imported tutorial is draft-only and appears on My Tutorials after publish (TC-24)

---

## Run Log

- Date:
- Tester:
- Commit/PR version:
- Notes:
