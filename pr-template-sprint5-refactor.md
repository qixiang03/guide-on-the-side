## Description
Refactors README for clarity, removes the deprecated `format_time` utility (moved to client-side), and improves the analytics step tracking logic to reliably detect step transitions using the actual DOM elements rendered by `split-guide.js`.

## Related Issue
Closes #

## Type of Change
- [ ] Bug fix (non-breaking change that fixes an issue)
- [x] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to change)
- [x] Documentation update
- [ ] Style/UI change (formatting, CSS, no code change)
- [x] Refactor (no functional changes)
- [x] Test update (adding or updating tests)
- [ ] Configuration change
- [ ] Security fix

## Changes Made

### README Refactor
- Rewrote `README.md` for clearer project overview, updated installation steps, and accurate project structure reflecting the current `web/app/plugins/pb-split-guide/` layout
- Added Past Contributors section (Tanguy Merrien)
- Updated documentation links and testing commands

### Analytics Step Tracking Improvements (`split-guide-tracker.js`)
- **Primary detection now uses MutationObserver on `#pbsgProgress`** instead of relying on `hashchange` events, which aligns with how `split-guide.js` actually renders step state ("Page: X of Y")
- Regex updated to match both `X / Y` and `X of Y` progress formats
- Button click interception updated to target actual DOM IDs (`#pbsgPrev`, `#pbsgNext`, `.pbsg-menu-item`) instead of generic class selectors (`.split-guide-prev`, `.split-guide-next`)
- `hashchange` listener moved to fallback (Strategy 3) since the template doesn't use URL hashes for step navigation

### Analytics Backend (`class-pbsg-analytics.php`)
- `record_slide_view()` no longer hardcodes `device_type = 'desktop'` when querying daily stats — now fetches the row with the highest `view_count` for the current day/tutorial, making it device-agnostic
- Added fallback INSERT when no daily row exists yet, preventing step view data loss on the first visit of the day
- `get_tutorial_detail()` now returns `step_names` array by reading step titles from `_pbsg_steps_json` post meta, allowing the dashboard to display human-readable step labels instead of raw indices

### Deprecated Code Removal
- Removed `format_time()` static method from `PBSG_Analytics` — time formatting is now handled client-side in `analytics-dashboard.js`
- Removed all `format_time` unit tests from `PBSGAnalyticsAggregationTest` (data provider + test method) and `PBSGAnalyticsEdgeCaseTest` (5 edge case tests)
- Removed ~68 lines of commented-out legacy step-saving code from `pb-split-guide.php`
- Cleaned up stale CSS rules from `admin-split-guide.css` and `split-guide.css`
- Deleted orphan `web/app/plugins/guide-on-the-side/README` file (leftover from old plugin name)

### Dashboard JS (`analytics-dashboard.js`)
- Minor updates to consume the new `step_names` data from the backend for step labeling in the tutorial detail view

## Screenshots
N/A — No visual UI changes. Backend logic and tracking accuracy improvements only.

## Testing Checklist
- [x] I have tested this locally
- [x] I have added/updated unit tests
- [ ] I have added/updated integration tests
- [x] All existing tests pass (`lando phpunit --configuration phpunit.xml --testdox`)
- [ ] I have tested on multiple browsers (Chrome, Firefox, Safari)
- [ ] I have tested on tablet viewport

## Accessibility Checklist
N/A — No UI changes in this PR.

## Documentation Checklist
- [x] README updated (if needed)
- [x] Code comments added for complex logic
- [ ] API documentation updated (if applicable)
- [ ] User guide updated (if applicable)

## Pre-Merge Checklist
- [ ] Branch is up to date with `develop`
- [ ] No merge conflicts
- [x] Code follows project coding standards
- [x] No console errors or warnings
- [x] No commented-out code (unless explained)

## Additional Notes
- The `format_time()` removal is a **breaking change for any code that calls `PBSG_Analytics::format_time()` directly** — but since it was only used internally by the dashboard JS (which now formats time client-side), there should be no downstream impact.
- The step tracking fix addresses a real issue: the previous `hashchange`-based approach never fired because `split-guide.js` doesn't use URL hashes for navigation. Steps were likely being under-counted in analytics.
- The daily stats fix (device-agnostic query + fallback INSERT) prevents data loss on first-of-day tutorial visits where no daily_stats row existed yet.

## Reviewer Notes
- Verify that `lando phpunit` passes all 181 tests (the removed `format_time` tests should bring the count down slightly)
- Check that `analytics-dashboard.js` correctly renders step names in the Tutorial Detail view
- Confirm the MutationObserver in `split-guide-tracker.js` fires when navigating steps in a live tutorial
