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

- [ ] ✅/❌ Quiz embed slide loads without breaking layout/navigation (TC-06)

## Permissions & Stability

- [ ] ✅/❌ Non-admin cannot access admin-only pages (TC-07)
- [ ] ✅/❌ Invalid/empty inputs do not crash the system (TC-08)

## Smoke Health

- [ ] ✅/❌ Core pages load without fatal errors (TC-09 / TC-10)

## Analytics & Certificate

- [ ] ✅/❌ Analytics dashboard loads and tabs work (TC-11)
- [ ] ✅/❌ Tutorial completion is recorded for certificate (TC-12)
- [ ] ✅/❌ Certificate PDF downloads after completion (TC-13)

## Template Picker & Export / Import (Sprint 7 / pb-split-guide)

- [ ] ✅/❌ Create new tutorial from template — draft + correct steps (TC-14)
- [ ] ✅/❌ Save current tutorial as template — appears in list (TC-15)
- [ ] ✅/❌ Export tutorial as JSON — download + valid package keys (TC-16)
- [ ] ✅/❌ Import JSON — new draft + edit link; bad file shows error (TC-17)

## Automated checks (CI / local — does not replace manual rows above)

Run after significant changes to `web/app/plugins/pb-split-guide` or `tests/`:

- [ ] ✅/❌ **PHPUnit (unit):** `./vendor/bin/phpunit tests/Unit` — includes `PBSGTemplateManagerTest`, `PBSGExportImportTest`, `PBSGH5PFactoryTest`, `PBSGPluginAjaxHandlersTest`, `PBSGAccessibilityEnhancerTest`, and existing PBSG\* suites.
- [ ] ✅/❌ **PHPUnit (smoke):** `./vendor/bin/phpunit tests/Integration/PBSplitGuidePluginSmokeTest.php` — plugin wiring, template load, save_meta, enqueue, certificate init. _(Note: if full suite errors on subprocess deprecation noise, confirm at least in-process tests and `tests/Unit` pass.)_
- [ ] ✅/❌ **Jest:** `npm test` — `tests/js/**/*.test.js` (tracker, admin-step-utils, analytics-badge-utils, compare-url, split-guide-menu).

See also: `docs/TESTING_LOG.md` entry **012** and `tests/black-box-test-cases.md` **Reference — Automated coverage**.

---

## Run Log

- Date:
- Tester:
- Commit/PR version:
- Notes:
