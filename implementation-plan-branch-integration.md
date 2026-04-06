# Implementation Plan: Branching + Give-Up Quiz System (v2)

**Date:** 2026-03-24
**Author:** Enzo (with Claude)
**Target branch:** `main` ← `feature/branch-substeps-integration`
**Wireframe:** `integrated-branch-substeps-wireframe.html`
**Context:** Cindy's frontend branching code (commit `729a718`) was removed by commit `b2eda57`. This plan restores + extends the feature with a sub-steps design and adds a new give-up system.

---

## Design Principles

1. **Students never see "branch" or "review" language.** Sub-questions feel like the next quiz.
2. **Branching and Give-Up are mutually exclusive (XOR)** on any single quiz. A quiz can have one, the other, or neither.
3. **Branch sub-quizzes can only have Give-Up** (no recursive branching). Max 2 sub-quizzes per main quiz.
4. **Previous answers are preserved.** Navigating back shows frozen correct/wrong state. Only the current (furthest unanswered) step accepts quiz input.
5. **Main quiz is permanently marked wrong** once branching triggers — even if student aces all sub-questions.
6. **Certificate still awarded** regardless of wrong/given-up quizzes. Summary shows honest results.

---

## Toggle Logic (XOR)

| Quiz Level | Branching | Give-Up | On Max Attempts | Next Button |
|---|---|---|---|---|
| Main — Neither | OFF | OFF | Unlimited retries | Locked until correct |
| Main — Branching | ON (max N) | OFF (greyed) | Sub-Qs injected, main = perm wrong | Auto-advances to first sub-Q |
| Main — Give-Up | OFF (greyed) | ON (max N) | Answer revealed, Next unlocked | Unlocked |
| Branch sub-Q — Neither | N/A | OFF | Unlimited retries | Locked until correct |
| Branch sub-Q — Give-Up | N/A | ON (max M) | Answer revealed, Next unlocked | Unlocked |

---

## Phase 1: Data Model Extension (~1 hr)

### 1a. `steps-normalizer.php` — Add give-up + sub-quiz fields

Cindy's existing `branch_*` fields survive. Add:

```php
// Give-up fields (applies to both main and sub-quizzes)
'give_up_enabled'     => (bool),
'give_up_max_attempts' => (int, min 1, default 3),
'give_up_explanation'  => (string, sanitized text),

// Sub-quiz definitions (stored on the PARENT step, up to 2)
'branch_sub_quizzes' => [
  {
    'quiz'           => { type, question, answers, ... },  // same shape as main quiz
    'tutorial_type'  => 'url' | 'file',
    'tutorial_url'   => '',
    'tutorial_attachment_id' => 0,
    'tutorial_file_name' => '',
    'tutorial_file_url'  => '',
    'give_up_enabled'     => false,
    'give_up_max_attempts' => 3,
    'give_up_explanation'  => '',
  },
  // ... max 2
],

// Branching trigger config (already exists from Cindy, just formalize)
'branch_enabled'           => (bool),
'branch_max_attempts'      => (int, default 3),
```

**Remove** the old `branch_mode` ('none'|'optional'|'mandatory') field — we no longer have optional branches. Replace with boolean `branch_enabled`. The `branch_trigger_attempts` becomes `branch_max_attempts`. The `branch_title` / `branch_intro` fields are no longer needed (students don't see branch labels).

**Migration:** Write a normalizer migration that converts old `branch_mode !== 'none'` → `branch_enabled: true`, `branch_trigger_attempts` → `branch_max_attempts`.

### 1b. `pb-split-guide.php` — Save/load pipeline

Extend the `save_meta` handler to:
- Validate XOR constraint: if `branch_enabled` is true, force `give_up_enabled` to false, and vice versa.
- Create H5P content for sub-quizzes via the existing H5P factory (same as main quiz inline creation).
- Store sub-quiz H5P IDs in the step data.

### 1c. `split-guide-template.php` — Restore branch enrichment

Re-add the branch data enrichment block removed from Cindy's code. Extend it to include:
- Sub-quiz H5P IDs (so the frontend can load them)
- Sub-quiz tutorial URLs / file URLs
- Give-up configuration per step and per sub-quiz

The enriched step object passed to JS should look like:

```javascript
{
  h5p_id: 42,
  title: 'Search Filters',
  tutorial: { type: 'url', url: '...', file_url: '', mime: '' },

  // Branching config
  branch_enabled: true,
  branch_max_attempts: 3,
  branch_sub_quizzes: [
    {
      h5p_id: 55,
      tutorial: { type: 'url', url: '...', file_url: '', mime: '' },
      give_up_enabled: true,
      give_up_max_attempts: 3,
      give_up_explanation: 'A catalog lists what the library owns...',
    },
    {
      h5p_id: 56,
      tutorial: { type: 'url', url: '...', file_url: '', mime: '' },
      give_up_enabled: false,
    }
  ],

  // Give-up config (only if branch_enabled is false)
  give_up_enabled: false,
  give_up_max_attempts: 0,
  give_up_explanation: '',
}
```

---

## Phase 2: Admin Authoring UI (~2 hrs)

### 2a. `admin-split-guide.js` — Redesign step card quiz settings

Replace Cindy's "Set Branch" button + modal with an inline toggle panel per step card.

**Per step card, below the quiz type/question fields:**

```
┌─────────────────────────────────────────────────────┐
│ Quiz Behavior                                       │
│                                                     │
│ [toggle] Enable Branching                           │
│   └─ Max attempts: [3]                              │
│   └─ Sub-questions: [1 ▼] (max 2)                  │
│      ┌ Sub-Q 3a ──────────────────────────┐         │
│      │ Type: [Multiple Choice ▼]          │         │
│      │ Question: [......................]  │         │
│      │ Tutorial URL: [.................]   │         │
│      │ [toggle] Give-Up  Max: [3]         │         │
│      │ Explanation: [..................]   │         │
│      └────────────────────────────────────┘         │
│                                                     │
│ [toggle] Enable Give-Up         ← greyed if above   │
│   └─ Max attempts: [5]                              │
│   └─ Explanation: [.............................]   │
└─────────────────────────────────────────────────────┘
```

**XOR enforcement:** When "Enable Branching" is toggled ON, the "Enable Give-Up" toggle on the same step is visually disabled (greyed, `pointer-events: none`, tooltip: "Disabled when Branching is enabled"). And vice versa.

### 2b. Sub-quiz inline authoring

Each sub-question gets the same inline quiz authoring fields as a main quiz (type selector, question, answers). Reuse the existing `buildQuizEditor()` function.

Sub-questions also get their own tutorial URL/file picker (right-pane content) — reuse existing tutorial picker component.

Sub-questions can have a Give-Up toggle (but NOT a Branching toggle — the branching option is not rendered for sub-quizzes).

### 2c. Remove Cindy's branch modal

Remove `openBranchPicker()` function and ThickBox-based modal (lines 1094–1232). Replace with the inline toggle panel above. This is a net simplification — no more modal for branch config.

---

## Phase 3: Frontend — Sub-Step Injection Engine (~2.5 hrs)

### 3a. `split-guide.js` — State model

```javascript
// Track per-step attempt counts (keyed by ORIGINAL step index, stable)
const attemptCounts = {};  // { originalIdx: count }

// Track which steps have had branches injected
const injectedBranches = {};  // { originalIdx: { startPos, count, subSteps[] } }

// Track give-up state
const givenUpSteps = new Set();  // step indices where give-up was triggered

// Track the furthest step index the student has reached
let furthestReached = 0;

// The original steps array (before any injection)
let originalSteps = [...steps];
```

### 3b. `injectBranchSubSteps(parentIdx)`

When branching triggers for step at `parentIdx`:

1. Build sub-step objects from the parent step's `branch_sub_quizzes` array:
   ```javascript
   branch_sub_quizzes.map((sq, j) => ({
     h5p_id: sq.h5p_id,
     title: sq.title || `${steps[parentIdx].title}`,  // no "branch" label
     tutorial: sq.tutorial,
     branch_step: true,       // internal flag, never shown to student
     parent_index: parentIdx,
     sub_index: j,            // 0 = 'a', 1 = 'b'
     give_up_enabled: sq.give_up_enabled,
     give_up_max_attempts: sq.give_up_max_attempts,
     give_up_explanation: sq.give_up_explanation,
   }));
   ```
2. Splice into `steps` at `parentIdx + 1`
3. Update `injectedBranches[parentIdx] = { startPos: parentIdx + 1, count: N }`
4. Recalculate indices for any subsequent injectedBranches
5. Set `i = parentIdx + 1` (auto-advance to first sub-Q)
6. Call `render()`

### 3c. Modify `updatePassState()` in `attachH5PWatcher()`

```javascript
const updatePassState = () => {
  const correct = isH5PCorrect(doc);
  const step = steps[stepIndex];

  if (correct) {
    passedSteps.add(stepIndex);
    lockNext(false);
    updateCertificateGate();
    return;
  }

  // Increment attempt count
  attemptCounts[stepIndex] = (attemptCounts[stepIndex] || 0) + 1;
  passedSteps.delete(stepIndex);

  // Check GIVE-UP trigger
  if (step.give_up_enabled) {
    const max = step.give_up_max_attempts || 3;
    if (attemptCounts[stepIndex] >= max) {
      triggerGiveUp(stepIndex);
      return;
    }
  }

  // Check BRANCHING trigger (only for non-sub-quiz steps)
  if (step.branch_enabled && !step.branch_step) {
    const max = step.branch_max_attempts || 3;
    if (attemptCounts[stepIndex] >= max && !injectedBranches[stepIndex]) {
      // Mark main quiz as permanently wrong
      step._permanently_wrong = true;
      passedSteps.delete(stepIndex);
      injectBranchSubSteps(stepIndex);
      return;
    }
  }

  // Default: just keep Next locked
  lockNext(true);
  updateCertificateGate();
};
```

### 3d. `triggerGiveUp(stepIndex)`

```javascript
function triggerGiveUp(stepIndex) {
  givenUpSteps.add(stepIndex);
  const step = steps[stepIndex];

  // Show give-up box below quiz area
  showGiveUpBox(stepIndex, {
    h5pAnswer: extractH5PCorrectAnswer(h5pFrame),  // auto-extract
    explanation: step.give_up_explanation || '',
  });

  // Unlock Next
  lockNext(false);
  updateCertificateGate();
}
```

### 3e. `extractH5PCorrectAnswer(iframe)`

Heuristic extraction from H5P DOM:
- Multiple choice: find the `.h5p-answer.h5p-correct` element text
- Fill-in-blanks: parse the `*answer*` syntax from the content
- Single choice: find the correct option text

Falls back to "See the correct answer above" if extraction fails.

### 3f. `showGiveUpBox(stepIndex, data)`

Renders a box below the H5P iframe in the left pane:

```html
<div class="pbsg-giveup-box">
  <div class="pbsg-giveup-header">Correct Answer</div>
  <div class="pbsg-giveup-answer">{h5pAnswer}</div>
  <div class="pbsg-giveup-explanation">{explanation}</div>
</div>
```

---

## Phase 4: Frontend — Navigation State Preservation (~1.5 hrs)

### 4a. Freeze answered steps

When a step is passed (correct) or given up, mark it as frozen:

```javascript
const frozenSteps = new Set();  // indices where quiz state is locked

// After correct answer or give-up:
frozenSteps.add(stepIndex);
```

When rendering a frozen step, the H5P iframe should show the last attempt state but NOT accept new input. Two approaches:

**Approach A (preferred):** Don't reload the H5P iframe for frozen steps. Instead, show a static summary:
```html
<div class="pbsg-frozen-quiz">
  <div class="pbsg-frozen-status">✅ Correctly answered (2 attempts)</div>
  <!-- or: ❌ Wrong (3 attempts, branched) -->
  <!-- or: ⚠ Given up (5 attempts) -->
</div>
```

**Approach B:** Reload H5P but overlay a blocking div preventing interaction.

Approach A is simpler and more performant (no unnecessary H5P reloads).

### 4b. Back navigation

Modify `prevBtn.onclick`:

```javascript
prevBtn.onclick = () => {
  if (i > 0) {
    i--;
    render();
  }
};
```

This already works. The key change is that `render()` now checks `frozenSteps.has(i)` and renders the frozen state instead of a live quiz.

### 4c. Forward navigation gating

Modify `nextBtn.onclick`:

```javascript
nextBtn.onclick = () => {
  if (i < furthestReached) {
    // Moving forward within already-visited territory
    i++;
    render();
  } else if (i === furthestReached && (passedSteps.has(i) || givenUpSteps.has(i) || steps[i]._permanently_wrong)) {
    // Advancing to NEW territory
    i++;
    furthestReached = i;
    render();
  }
  // else: locked (can't go beyond furthest reached if current step isn't resolved)
};
```

### 4d. Menu jump gating

```javascript
window.pbsgGoToStep = function(index) {
  if (index < 0 || index >= steps.length) return;
  if (index > furthestReached) return;  // can't jump ahead of furthest reached
  i = index;
  render();
};
```

---

## Phase 5: CSS (~30 min)

### `split-guide.css` additions:

```css
/* Give-up box */
.pbsg-giveup-box {
  margin-top: 12px;
  padding: 14px;
  border: 1px solid #ef9a9a;
  background: #fff5f5;
  border-radius: 8px;
}
.pbsg-giveup-header {
  font-size: 13px; font-weight: bold; color: #8C2004; margin-bottom: 8px;
}
.pbsg-giveup-answer {
  font-size: 15px; font-weight: bold; color: #333; margin-bottom: 6px;
}
.pbsg-giveup-explanation {
  font-size: 14px; color: #555; line-height: 1.5;
}

/* Frozen step display */
.pbsg-frozen-quiz {
  border: 2px solid #E0E0E0;
  border-radius: 8px;
  padding: 20px;
  text-align: center;
  background: #fafafa;
}
.pbsg-frozen-status {
  font-size: 15px; font-weight: bold;
}
.pbsg-frozen-status.correct { color: #2e7d32; }
.pbsg-frozen-status.wrong   { color: #8C2004; }
.pbsg-frozen-status.giveup  { color: #E6A817; }
```

### Remove Cindy's modal CSS:

Remove `.pbsg-branch-modal`, `.pbsg-branch-modal-backdrop`, `.pbsg-branch-modal-dialog`, etc. (no longer needed).

---

## Phase 6: Summary Screen Updates (~45 min)

### `split-guide.js` — `showSummaryScreen()` modifications:

Build the summary from the full `steps` array, showing:
- Main quizzes with their attempt count and status (✅/❌/⚠)
- Branch sub-quizzes indented under their parent
- Give-up quizzes with ⚠ icon

```javascript
function buildSummaryData() {
  return steps.filter(s => s.h5p_id > 0).map((step, idx) => ({
    title: step.title,
    isBranchStep: !!step.branch_step,
    parentIndex: step.parent_index ?? null,
    attempts: attemptCounts[idx] || 0,
    status: passedSteps.has(idx) ? 'correct'
          : givenUpSteps.has(idx) ? 'giveup'
          : step._permanently_wrong ? 'wrong'
          : 'unknown',
  }));
}
```

Certificate is always available on completion (regardless of wrong/given-up status).

---

## Phase 7: Edge Cases & Hardening (~1 hr)

1. **Retake button:** `resetTutorialToStart()` must clear all state: `injectedBranches`, `givenUpSteps`, `frozenSteps`, `furthestReached = 0`, restore `steps` from `originalSteps`.

2. **attemptCounts stability:** When sub-steps are spliced in, indices shift. Key `attemptCounts` by a stable identifier (e.g., `step.h5p_id` or `step._stableId` assigned at init) rather than array index.

3. **Analytics/xAPI:** The tracker should record branch sub-quiz results separately. Add `is_branch_sub: true` flag to xAPI events for sub-quizzes. Give-up events should be tracked as a distinct event type.

4. **Menu rebuild:** When sub-steps are injected, dynamically rebuild the step menu dropdown. Sub-quiz items don't need any visual distinction (students don't know they're branches).

5. **H5P auto-answer extraction:** The `extractH5PCorrectAnswer()` function is heuristic. Add a fallback for when extraction fails (show only the librarian explanation, or a generic "The correct answer is shown above").

6. **XOR enforcement in frontend:** If somehow both `branch_enabled` and `give_up_enabled` are true (malformed data), prioritize branching.

---

## Phase 8: Testing Checklist

| # | Test Case | Expected |
|---|---|---|
| 1 | Quiz with neither branching nor give-up | Unlimited retries, Next locked until correct |
| 2 | Quiz with branching (N=3), answer wrong 2× | No branch yet, retry continues |
| 3 | Quiz with branching (N=3), answer wrong 3× | Sub-Qs injected, main = wrong, progress count increases |
| 4 | Navigate through all branch sub-Qs correctly | Return to next main quiz after last sub-Q |
| 5 | Branch sub-Q with give-up (M=3), fail M× | Answer revealed, Next unlocked on sub-Q |
| 6 | Main quiz with give-up (N=5), fail N× | Answer + explanation shown, Next unlocked |
| 7 | Navigate back after answering Q1-Q3 correctly | Q1-Q3 show frozen correct state, no re-attempt |
| 8 | Navigate back to wrong main quiz (Q3) | Shows frozen wrong state with attempt count |
| 9 | From Q3a, go back to Q1, then forward | Can reach Q1, Q2, Q3, Q3a. Cannot reach Q3b yet |
| 10 | Summary screen with mixed results | Correct/wrong/giveup all display properly with sub-Qs indented |
| 11 | Certificate after tutorial with failed quizzes | Certificate still available and downloadable |
| 12 | Retake tutorial after branches were triggered | Full reset, clean slate, original step count |
| 13 | Admin: toggle Branching ON → Give-Up disabled | XOR enforced in UI |
| 14 | Admin: configure 2 sub-questions with own quizzes | Both saved, both get H5P IDs, both appear in tutorial |
| 15 | Admin: sub-question give-up explanation field | Text saved and displayed on student give-up |

---

## Estimated Effort

| Phase | Time | Owner |
|---|---|---|
| Phase 1: Data model extension | 1 hr | Enzo |
| Phase 2: Admin authoring UI | 2 hrs | Enzo + Cindy review |
| Phase 3: Frontend sub-step engine | 2.5 hrs | Enzo |
| Phase 4: Navigation state preservation | 1.5 hrs | Enzo |
| Phase 5: CSS | 30 min | Enzo |
| Phase 6: Summary screen | 45 min | Enzo |
| Phase 7: Edge cases | 1 hr | Enzo |
| Phase 8: Testing | 1.5 hrs | Both |
| **Total** | **~11 hrs** | |

---

## PR Strategy

1. Branch from `main` → `feature/branch-substeps-integration`
2. Commit 1: `fix(normalizer): extend data model with give-up fields and branch sub-quizzes`
3. Commit 2: `feat(admin): redesign branch config as inline toggle panel with XOR enforcement`
4. Commit 3: `feat(frontend): implement branching as dynamic sub-steps with state preservation`
5. Commit 4: `feat(frontend): add give-up flow with H5P answer extraction`
6. Commit 5: `feat(summary): display branch sub-quiz and give-up results`
7. Commit 6: `fix(edge-cases): handle retake, stable IDs, analytics guards`
8. PR into `main`, Cindy as required reviewer

---

## Dependency on Cindy's Surviving Code

This plan **preserves and builds upon** Cindy's admin-side branch data (admin-split-guide.js, steps-normalizer.php). Phase 2 replaces her ThickBox modal with an inline panel, but the underlying data shape is evolved (not discarded). Cindy should review to confirm her original intent is honored.

---

## Open Questions

1. **H5P answer extraction reliability:** How reliably can we extract correct answers from H5P iframes across content types? If unreliable, we may need to require the librarian to manually enter the correct answer text for give-up scenarios.
2. **Max sub-questions:** Currently capped at 2. Should this be configurable, or is 2 the hard limit per client spec ("minimum 2 levels deep")?
3. **Re-attempt after give-up:** Once a student gives up, is that step permanently given-up, or can they re-attempt if they navigate back? (Current design: permanently given-up, frozen.)
