# Implementation Plan: Restore Cindy's Branch Sub-Tutorials & Video Embeds

**Date:** 2026-03-29
**Source:** Cindy's commits `7482705` + `729a718`, preserved on main at merge `634d035`
**Lost at:** Merge `8f14a70` (main→develop conflict resolution favored develop side)
**Strategy:** Extract Cindy's exact code from `634d035` and insert into current develop HEAD

---

## What's Being Restored

| Feature | Lines | Source File |
|---------|-------|-------------|
| Branch sub-tutorial frontend logic | ~290 lines | `split-guide.js` |
| Vimeo / Dailymotion / TED video embeds | ~95 lines | `split-guide.js` |
| Branch modal HTML + PHP data assembly | ~55 lines | `split-guide-template.php` |
| Branch Review admin column header | ~1 line | `pb-split-guide.php` |

**NOT lost (already on develop):**
- `admin-split-guide.js` — branch picker UI, `openBranchPicker()`, `branchSummary()` ✓
- `split-guide.css` — branch banner + modal styles ✓
- `steps-normalizer.php` — branch field validation ✓

---

## File 1: `split-guide.js` — 6 Insertions

All code extracted verbatim from `634d035`. No rewrites.

### 1A. Branch DOM refs (after line 12)

**Insert after:** `const nextBtn = document.getElementById('pbsgNext');`
**Insert before:** `const introScreen = document.getElementById('pbsgIntroScreen');`

```javascript
const branchModal = document.getElementById('pbsgBranchModal');
const branchText = document.getElementById('pbsgBranchText');
const branchOpenBtn = document.getElementById('pbsgBranchOpen');
const branchReturnBtn = document.getElementById('pbsgBranchReturn');
const branchCompleteBtn = document.getElementById('pbsgBranchComplete');
const branchSkipBtn = document.getElementById('pbsgBranchSkip');
const branchCloseBtn = document.getElementById('pbsgBranchClose');
```

### 1B. Branch state variables (after the `passedSteps` declaration, ~line 90)

**Insert after:** `const passedSteps = new Set();`
**Insert before:** `let h5pObs = null;`

```javascript
const triggeredBranchSteps = new Set();
const completedBranchSteps = new Set();
let activeBranchStep = null;
let branchReturnTarget = null;
```

### 1C. Branch functions block (after `isCurrentStepBlockedByMandatoryBranch`, before `attachH5PWatcher`)

**Insert after the existing helper functions, before `attachH5PWatcher`.** This is a single contiguous block of ~155 lines containing these 10 functions:

1. `openBranchModal()`
2. `closeBranchModal()`
3. `hasBranch(step)`
4. `shouldTriggerBranch(stepIndex)`
5. `isMandatoryBranch(step)`
6. `resetBranchUI()`
7. `showBranchPrompt(stepIndex)`
8. `renderBranchTutorial(stepIndex)`
9. `returnToMainTutorial()`
10. `isCurrentStepBlockedByMandatoryBranch()`

**Extract command:** `git show 634d035:web/app/plugins/pb-split-guide/assets/split-guide.js` lines 186-338

### 1D. Replace `updatePassState` inside `attachH5PWatcher` (~line 207)

**Current code (develop):**
```javascript
const updatePassState = () => {
  if (isH5PCorrect(doc)) {
    passedSteps.add(stepIndex);
  } else {
    passedSteps.delete(stepIndex);
  }
  lockNext(!passedSteps.has(stepIndex));
  updateCertificateGate();
};
```

**Replace with Cindy's version:**
```javascript
const updatePassState = () => {
  const correct = isH5PCorrect(doc);
  const step = steps[stepIndex];

  if (correct) {
    passedSteps.add(stepIndex);
    resetBranchUI();
    lockNext(false);
    updateCertificateGate();
    return;
  }

  passedSteps.delete(stepIndex);

  if (shouldTriggerBranch(stepIndex)) {
    triggeredBranchSteps.add(stepIndex);
    showBranchPrompt(stepIndex);

    if (step.branch.mode === 'mandatory' && !completedBranchSteps.has(stepIndex)) {
      lockNext(true);
    } else {
      lockNext(true);
    }
  } else {
    lockNext(true);
  }

  updateCertificateGate();
};
```

### 1E. Modify `render()` and `resetTutorialToStart()` — 2 small edits

**In `render()` (~line 539):**
- Add `resetBranchUI();` as the first line inside the function
- Replace the `lockNext` block:

**Current:**
```javascript
if (step.h5p_id) {
  lockNext(!passedSteps.has(i));
} else {
  lockNext(false);
}
```

**Replace with:**
```javascript
if (step.h5p_id) {
  if (isCurrentStepBlockedByMandatoryBranch()) {
    lockNext(true);
  } else {
    lockNext(!passedSteps.has(i));
  }
} else {
  lockNext(false);
}
```

**In `resetTutorialToStart()` (~line 304):**
- Add 3 lines after `certMarked = false;`:

```javascript
triggeredBranchSteps.clear();
completedBranchSteps.clear();
resetBranchUI();
```

### 1F. Branch button event handlers (after `nextBtn.onclick`)

**Insert after the `nextBtn.onclick` block, before `certBtn` handlers.**

~57 lines of event handlers for: `branchOpenBtn`, `branchReturnBtn`, `branchSkipBtn`, `branchCompleteBtn`, `branchCloseBtn`.

### 1G. Video embed parsers (inside `toEmbeddableUrl`, before final `return rawUrl`)

**Insert before:** `return rawUrl;` (last line of `toEmbeddableUrl`)

~92 lines: Vimeo parser, Dailymotion parser, TED Talk parser. Verbatim from `634d035`.

---

## File 2: `split-guide-template.php` — 2 Insertions

### 2A. Branch data assembly (in the step enrichment `foreach` loop)

**Insert after:** `$s['tutorial'] = $tutorial;`
**Insert before:** `$steps_enriched[] = $s;`

```php
$branch_tutorial_type = isset($s['branch_tutorial_type']) ? $s['branch_tutorial_type'] : '';
$branch_tutorial_url  = isset($s['branch_tutorial_url']) ? $s['branch_tutorial_url'] : '';
$branch_tutorial_attachment_id = isset($s['branch_tutorial_attachment_id']) ? absint($s['branch_tutorial_attachment_id']) : 0;

$branch = [
  'mode' => !empty($s['branch_mode']) ? $s['branch_mode'] : 'none',
  'trigger_attempts' => !empty($s['branch_trigger_attempts']) ? (int)$s['branch_trigger_attempts'] : 1,
  'title' => !empty($s['branch_title']) ? $s['branch_title'] : '',
  'intro' => !empty($s['branch_intro']) ? $s['branch_intro'] : '',
  'tutorial' => [
    'type' => $branch_tutorial_type,
    'url' => $branch_tutorial_url,
    'file_url' => '',
    'mime' => ''
  ]
];

if ($branch_tutorial_type === 'file' && $branch_tutorial_attachment_id > 0) {
  $branch['tutorial']['file_url'] = wp_get_attachment_url($branch_tutorial_attachment_id);
  $branch['tutorial']['mime'] = get_post_mime_type($branch_tutorial_attachment_id);
}

$s['branch'] = $branch;
```

### 2B. Branch modal HTML (after the `pbsg-banner` div, before the tutorial iframe wrap)

**Insert after:** closing `</div>` of `.pbsg-banner`
**Insert before:** `<div class="pbsg-iframe-wrap" id="pbsgTutorialStage">`

```html
<div id="pbsgBranchModal" class="pbsg-branch-modal" style="display:none;" aria-hidden="true">
  <div class="pbsg-branch-modal-backdrop"></div>
  <div class="pbsg-branch-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pbsgBranchModalTitle">
    <button type="button" class="pbsg-branch-modal-close" id="pbsgBranchClose" aria-label="Close" style="display:none;">&times;</button>
    <h3 id="pbsgBranchModalTitle" class="pbsg-branch-modal-title">Branch Review</h3>
    <div id="pbsgBranchText" class="pbsg-branch-text"></div>
    <div class="pbsg-branch-actions">
      <button type="button" class="button button-primary" id="pbsgBranchOpen">Start</button>
      <button type="button" class="button" id="pbsgBranchSkip" style="display:none;">Skip</button>
      <button type="button" class="button button-primary" id="pbsgBranchComplete" style="display:none;">I Finished This Sub-Tutorial</button>
      <button type="button" class="button" id="pbsgBranchReturn" style="display:none;">Back to Main Tutorial</button>
    </div>
  </div>
</div>
```

---

## File 3: `pb-split-guide.php` — 1 Edit

### 3A. Re-add Branch Review column to admin steps table

Find the steps table `<thead>` and add a "Branch Review" column. Adjust widths to accommodate 5 columns instead of 4, and update the empty-state colspan.

---

## Execution Order

| Step | File | Change | Risk |
|------|------|--------|------|
| 1 | `split-guide.js` | Insert branch DOM refs | NONE — additive |
| 2 | `split-guide.js` | Insert branch state vars | NONE — additive |
| 3 | `split-guide.js` | Insert 10 branch functions | NONE — additive |
| 4 | `split-guide.js` | Replace `updatePassState` | LOW — swaps 8 lines for 28 |
| 5 | `split-guide.js` | Edit `render()` + `resetTutorialToStart()` | LOW — small modifications |
| 6 | `split-guide.js` | Insert branch event handlers | NONE — additive |
| 7 | `split-guide.js` | Insert video embed parsers | NONE — additive |
| 8 | `split-guide-template.php` | Insert branch PHP data assembly | NONE — additive |
| 9 | `split-guide-template.php` | Insert branch modal HTML | NONE — additive |
| 10 | `pb-split-guide.php` | Add Branch Review column | LOW — table header change |

Every insertion uses Cindy's original code verbatim. The only "modifications" are steps 4-5 where her `updatePassState` replaces the current simpler version, and `render()`/`resetTutorialToStart()` get small additions.

---

## Compatibility with Your Frontend

Your additions that Cindy's code must coexist with:

| Your Feature | Location | Conflict? |
|---|---|---|
| Resize handle (`initResizer`) | Bottom of split-guide.js | NO — independent function |
| Structured intro screen | `render()`, template | NO — branch logic only fires on quiz steps |
| Layout ratio CSS vars | Template PHP | NO — branch doesn't touch layout |
| Accessibility shortcuts | split-guide.js (restored earlier) | NO — different keydown handler |
| `tutorial-active` body class | Line 3 of split-guide.js | NO — already restored |

Zero conflicts. The branch system hooks into `updatePassState` (wrong answer detection) and `render()` (lock state). Your resize handle, structured intro, and layout features are all orthogonal.

---

## Cover Images

Cindy's 3 tutorial cover images (`tutorial cover 1/2/3.png`) were replaced by your `tutorial.png` and `admin-operate.png`. These appear to serve different purposes — hers were sample covers, yours are actual UI assets. **Skip restoring** unless Cindy confirms she needs them.

---

## Verification

After implementation:

- [ ] Branch modal DOM elements resolve (not null) when branch modal HTML is present
- [ ] `hasBranch(step)` returns true for steps with branch_mode != 'none'
- [ ] Wrong answer X times → branch banner appears
- [ ] Mandatory: no skip, no close, must click "I Finished"
- [ ] Optional: skip button works, close button works
- [ ] After completing branch + answering correctly → Next unlocks
- [ ] Vimeo URLs convert to `player.vimeo.com/video/{id}`
- [ ] Dailymotion URLs convert to `dailymotion.com/embed/video/{id}`
- [ ] TED URLs convert to `embed.ted.com/talks/{slug}`
- [ ] Resize handle still works
- [ ] Structured intro still works
- [ ] Keyboard shortcuts still work
