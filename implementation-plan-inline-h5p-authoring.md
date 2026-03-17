# Implementation Plan: Inline H5P Authoring in Tutorial Editor

## The Problem

Creating a single tutorial step currently requires bouncing between **two separate admin areas** and manually tracking IDs:

1. **H5P Content** → Add New → pick quiz type → fill in question/answers → save → note the ID (e.g., `4`)
2. **Tutorials** → Edit tutorial → Add Step → type `4` into the H5P field → open another modal to set the resource URL

This is fragmented, error-prone, and forces librarians to invent naming conventions like `t1-q1`, `t1-q3` just to keep a mental map between H5P items and tutorial steps.

## The Goal

**One screen, one flow.** A librarian opens a tutorial, clicks "Add Step," authors the quiz question inline, pastes the resource URL (or uploads a PDF), and saves. The H5P content record is created automatically behind the scenes — no ID juggling, no context switching.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│  Tutorial Editor (WordPress Page)                       │
│  ┌───────────────────────────────────────────────────┐  │
│  │ Step 1: Introduction                              │  │
│  │ ┌─────────────────────┐ ┌───────────────────────┐ │  │
│  │ │ Quiz Panel          │ │ Resource Panel        │ │  │
│  │ │ Type: [MC ▾]        │ │ ○ URL  ○ File         │ │  │
│  │ │ Q: "What is..."     │ │ [https://youtube...  ]│ │  │
│  │ │ ☑ Correct answer    │ │                       │ │  │
│  │ │ ☐ Wrong answer 1    │ │                       │ │  │
│  │ │ ☐ Wrong answer 2    │ │                       │ │  │
│  │ └─────────────────────┘ └───────────────────────┘ │  │
│  └───────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────┐  │
│  │ Step 2: Advanced Search                           │  │
│  │ ...                                               │  │
│  └───────────────────────────────────────────────────┘  │
│  [+ Add Step]                                           │
└─────────────────────────────────────────────────────────┘
```

### Data Flow

```
Librarian fills inline form
        │
        ▼
admin-split-guide.js collects quiz data per step
        │
        ▼
On page save (POST), PHP receives:
  _pbsg_steps_json = [{
    title: "Introduction",
    quiz: {
      type: "multichoice",         ← NEW: quiz definition
      question: "What is...",
      answers: [
        { text: "A database", correct: true },
        { text: "A website", correct: false }
      ]
    },
    h5p_id: 0,                     ← 0 = "create new" / >0 = "already created"
    tutorial_type: "url",
    tutorial_url: "https://..."
  }, ...]
        │
        ▼
PHP save_meta() detects h5p_id === 0 with quiz data present
        │
        ▼
Calls H5PCore::saveContent() programmatically
  → inserts row into wp_h5p_contents
  → returns new H5P ID
        │
        ▼
Stores final step JSON with real h5p_id
```

---

## Implementation Steps

### Phase 1: Backend — AJAX Endpoint for Programmatic H5P Creation

**File:** `pb-split-guide.php`

**New AJAX action:** `pbsg_create_h5p`

This endpoint receives a simplified quiz definition and translates it into the H5P content JSON format that the H5P plugin expects.

#### 1.1 Add a new class: `includes/class-pbsg-h5p-factory.php`

Purpose: Convert our simplified quiz schema into H5P-native `parameters` JSON.

```php
<?php
final class PBSG_H5P_Factory {

    /**
     * Create an H5P content record from a simplified quiz definition.
     *
     * @param array $quiz  ['type' => 'multichoice|blanks|singlechoice',
     *                      'question' => string,
     *                      'answers' => [...],
     *                      'title' => string (optional, auto-generated)]
     * @return int|WP_Error  New H5P content ID or error
     */
    public static function create(array $quiz) {
        // 1. Resolve library name + ID from $quiz['type']
        // 2. Build the H5P parameters JSON for that library
        // 3. Call H5P_Plugin::get_instance()->get_h5p_instance('core')->saveContent()
        // 4. Return the new content ID
    }
}
```

**Library mappings:**

| Quiz Type | H5P Machine Name | Key Parameters |
|-----------|-----------------|----------------|
| `multichoice` | `H5P.MultiChoice` | `question`, `answers[].text`, `answers[].correct`, `answers[].tipsAndFeedback` |
| `blanks` | `H5P.Blanks` | `questions[].text` (with `*word*` syntax for blanks), `behaviour` |
| `singlechoice` | `H5P.SingleChoiceSet` | `choices[].question`, `choices[].answers[]` (first = correct) |

**Parameters JSON templates for each type:**

<details>
<summary>H5P.MultiChoice parameters</summary>

```json
{
  "question": "<p>What is a library database?</p>",
  "answers": [
    {
      "text": "<p>A structured collection of information</p>",
      "correct": true,
      "tipsAndFeedback": {
        "tip": "",
        "chosenFeedback": "<div>Correct!</div>",
        "notChosenFeedback": ""
      }
    },
    {
      "text": "<p>A type of website</p>",
      "correct": false,
      "tipsAndFeedback": {
        "tip": "",
        "chosenFeedback": "<div>Try again.</div>",
        "notChosenFeedback": ""
      }
    }
  ],
  "overallFeedback": [{ "from": 0, "to": 100, "feedback": "" }],
  "behaviour": {
    "enableRetry": true,
    "enableSolutionsButton": true,
    "enableCheckButton": true,
    "type": "auto",
    "singlePoint": false,
    "randomAnswers": true,
    "showSolutionsRequiresInput": true,
    "confirmCheckDialog": false,
    "confirmRetryDialog": false,
    "autoCheck": false,
    "passPercentage": 100
  },
  "UI": {
    "checkAnswerButton": "Check",
    "submitAnswerButton": "Submit",
    "showSolutionButton": "Show solution",
    "tryAgainButton": "Retry",
    "tipsLabel": "Show tip",
    "scoreBarLabel": "You got :num out of :total points",
    "tipAvailable": "Tip available",
    "feedbackAvailable": "Feedback available",
    "readFeedback": "Read feedback",
    "wrongAnswer": "Wrong answer",
    "correctAnswer": "Correct answer",
    "shouldCheck": "Should have been checked",
    "shouldNotCheck": "Should not have been checked",
    "noInput": "Please answer before viewing the solution",
    "a11yCheck": "Check the answers",
    "a11yShowSolution": "Show the solution",
    "a11yRetry": "Retry the task"
  }
}
```
</details>

<details>
<summary>H5P.Blanks parameters</summary>

```json
{
  "questions": [
    "<p>A *database* stores structured information for retrieval.</p>"
  ],
  "overallFeedback": [{ "from": 0, "to": 100, "feedback": "" }],
  "showSolutions": "Show solution",
  "tryAgain": "Retry",
  "checkAnswer": "Check",
  "submitAnswer": "Submit",
  "notFilledOut": "Please fill in all blanks to get feedback",
  "answerIsCorrect": "':ans' is correct",
  "answerIsWrong": "':ans' is wrong",
  "answeredCorrectly": "Filled in correctly",
  "answeredIncorrectly": "Filled in incorrectly",
  "solutionLabel": "Correct answer:",
  "inputLabel": "Blank input @num of @total",
  "inputHasTipLabel": "Tip available",
  "tipLabel": "Tip",
  "behaviour": {
    "enableRetry": true,
    "enableSolutionsButton": true,
    "enableCheckButton": true,
    "autoCheck": false,
    "caseSensitive": false,
    "showSolutionsRequiresInput": true,
    "separateLines": false,
    "confirmCheckDialog": false,
    "confirmRetryDialog": false,
    "acceptSpellingErrors": false
  },
  "scoreBarLabel": "You got :num out of :total points",
  "a11yCheck": "Check the answers",
  "a11yShowSolution": "Show the solution",
  "a11yRetry": "Retry the task",
  "a11yCheckingModeHeader": "Checking Mode"
}
```
</details>

<details>
<summary>H5P.SingleChoiceSet parameters</summary>

```json
{
  "choices": [
    {
      "question": "<p>What does ISBN stand for?</p>",
      "answers": [
        "<p>International Standard Book Number</p>",
        "<p>Internal System Book Notation</p>",
        "<p>Indexed Standard Bibliography Number</p>"
      ]
    }
  ],
  "overallFeedback": [{ "from": 0, "to": 100, "feedback": "" }],
  "behaviour": {
    "autoContinue": true,
    "timeoutCorrect": 2000,
    "timeoutWrong": 3000,
    "soundEffectsEnabled": true,
    "enableRetry": true,
    "enableSolutionsButton": true,
    "passPercentage": 100
  },
  "l10n": {
    "nextButtonLabel": "Next question",
    "showSolutionButtonLabel": "Show solution",
    "retryButtonLabel": "Retry",
    "solutionViewTitle": "Solution list",
    "correctText": "Correct!",
    "incorrectText": "Incorrect!",
    "shouldSelect": "Should have been selected",
    "shouldNotSelect": "Should not have been selected",
    "muteButtonLabel": "Mute feedback sound",
    "closeButtonLabel": "Close",
    "slideOfTotal": "Slide :num of :total",
    "scoreBarLabel": "You got :num out of :total points",
    "solutionListQuestionNumber": "Question @num",
    "a11yTitleText": "Single Choice Set",
    "a11yModeHeader": "Mode Header"
  }
}
```
</details>

#### 1.2 Register the AJAX endpoint

In `pb-split-guide.php` constructor:

```php
add_action('wp_ajax_pbsg_create_h5p', [$this, 'ajax_create_h5p']);
```

Handler:

```php
public function ajax_create_h5p() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    if (!current_user_can('edit_h5p_contents')) {
        wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $quiz = json_decode(wp_unslash($_POST['quiz']), true);
    if (!$quiz || empty($quiz['type'])) {
        wp_send_json_error(['message' => 'Invalid quiz data']);
    }

    $result = PBSG_H5P_Factory::create($quiz);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success(['h5p_id' => $result]);
}
```

#### 1.3 Alternative: Create on page save (synchronous)

Instead of AJAX-per-step, we can batch-create all H5P content during `save_post_page`. This is simpler and more atomic:

```php
public function save_meta($post_id, $post) {
    // ... existing nonce/permission checks ...

    $steps = json_decode($steps_json, true);

    // For each step that has quiz data but no h5p_id, create the H5P content
    foreach ($steps as &$step) {
        if ((!empty($step['quiz'])) && (empty($step['h5p_id']) || $step['h5p_id'] === 0)) {
            $h5p_id = PBSG_H5P_Factory::create($step['quiz']);
            if (!is_wp_error($h5p_id)) {
                $step['h5p_id'] = $h5p_id;
            }
        }
        // Strip quiz data from stored JSON (it lives in H5P now)
        unset($step['quiz']);
    }

    $clean = PBSG_Steps_Normalizer::normalize($steps);
    update_post_meta($post_id, self::META_STEPS, wp_json_encode($clean));
    // ... rest of save ...
}
```

**Recommendation:** Use the **synchronous save approach** (1.3). It's simpler, avoids race conditions, and the H5P creation is fast (single DB insert). AJAX can be added later for a "preview" feature.

---

### Phase 2: Frontend — Inline Quiz Builder UI

**File:** `assets/admin-split-guide.js` (modify existing)
**File:** `assets/admin/admin-split-guide.css` (modify existing)

#### 2.1 Replace the current step row layout

Current layout (flat table row):
```
| Step title | H5P ID [Add H5P] | Tutorial Source [Set Tutorial] | [Remove] |
```

New layout (expandable card):
```
┌─ Step 1 ──────────────────────────────────────────────────────┐
│ Title: [Introduction                                        ] │
│                                                               │
│ ┌─ Quiz ────────────────────┐ ┌─ Resource ─────────────────┐ │
│ │ Type: [Multiple Choice ▾] │ │ ○ URL  ○ Upload PDF        │ │
│ │                           │ │                             │ │
│ │ Question:                 │ │ [https://youtube.com/xyz  ] │ │
│ │ [What is a database?    ] │ │                             │ │
│ │                           │ │  ─ or ─                     │ │
│ │ Answers:                  │ │                             │ │
│ │ ☑ [A structured collect.] │ │ Linked H5P: #4 (t1-q4)    │ │
│ │ ☐ [A type of website    ] │ │ [Use existing H5P instead] │ │
│ │ ☐ [A web browser        ] │ │                             │ │
│ │ [+ Add Answer]            │ │                             │ │
│ └───────────────────────────┘ └─────────────────────────────┘ │
│                                          [▲ Move Up] [Remove] │
└───────────────────────────────────────────────────────────────┘
```

#### 2.2 Quiz type-specific form renderers

Each quiz type needs a different inline form:

**Multiple Choice:**
```
Question: [________________]
Answers:
  ☑ [correct answer text___] [×]
  ☐ [wrong answer text_____] [×]
  ☐ [wrong answer text_____] [×]
  [+ Add Answer]
```

**Fill in the Blanks:**
```
Sentence (wrap blank words with *asterisks*):
[A *database* stores *structured* information]

Tip: Words between asterisks become fill-in-the-blank fields.
```

**Single Choice Set:**
```
Question: [________________]
Correct answer:  [correct answer here____]
Wrong answers:
  [wrong answer 1__________] [×]
  [wrong answer 2__________] [×]
  [+ Add Wrong Answer]
```

#### 2.3 Step data model (extended)

```javascript
{
  title: 'Introduction',

  // NEW: inline quiz definition (stripped on save, converted to H5P)
  quiz: {
    type: 'multichoice',  // 'multichoice' | 'blanks' | 'singlechoice'
    question: 'What is a library database?',
    answers: [
      { text: 'A structured collection of information', correct: true },
      { text: 'A type of website', correct: false },
      { text: 'A web browser', correct: false }
    ]
  },

  // Existing fields (h5p_id will be 0 until save creates it)
  h5p_id: 0,
  tutorial_type: 'url',
  tutorial_url: 'https://youtube.com/watch?v=xyz',
  tutorial_attachment_id: 0,
  tutorial_file_name: '',
  tutorial_file_url: ''
}
```

#### 2.4 "Use Existing H5P" escape hatch

For backwards compatibility and edge cases, keep a small link:

```
[Use existing H5P instead] → opens current thickbox picker
```

When an existing H5P is selected, the inline quiz form collapses and shows:
```
Quiz: Linked to H5P #4 (t1-q4) [Edit in H5P] [Detach & create new]
```

#### 2.5 Drag-and-drop step reordering

While we're reworking the UI, add `SortableJS` (lightweight, no jQuery UI dependency) for drag-to-reorder steps. This is a long-standing UX gap.

```javascript
import Sortable from 'sortablejs';
// or enqueue from CDN: https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js
```

---

### Phase 3: Steps Normalizer Update

**File:** `includes/steps-normalizer.php`

Add handling for the new `quiz` key. The normalizer should:

1. **Preserve** `quiz` data during normalization (it's consumed by save_meta, not stored long-term)
2. After H5P creation, the `quiz` key is stripped — so stored JSON never contains it
3. Add validation: if `quiz.type` is present, require `quiz.question` to be non-empty

```php
// In normalize(), add after existing processing:
$quiz = isset($s['quiz']) && is_array($s['quiz']) ? $s['quiz'] : null;
if ($quiz) {
    $clean_step['quiz'] = [
        'type'     => self::sanitize_key($quiz['type'] ?? ''),
        'question' => self::sanitize_text($quiz['question'] ?? ''),
        'answers'  => self::sanitize_answers($quiz['answers'] ?? []),
    ];
}
```

---

### Phase 4: Edit Existing H5P Inline (Reverse Population)

When editing a tutorial that already has H5P content linked, the inline form should show the existing question data.

**New AJAX endpoint:** `pbsg_get_h5p_content`

```php
public function ajax_get_h5p_content() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    $h5p_id = (int) $_POST['h5p_id'];
    if ($h5p_id <= 0) wp_send_json_error(['message' => 'Invalid ID']);

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT c.parameters, c.library_id, l.name as library_name
         FROM {$wpdb->prefix}h5p_contents c
         JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id = l.id
         WHERE c.id = %d",
        $h5p_id
    ), ARRAY_A);

    if (!$row) wp_send_json_error(['message' => 'H5P content not found']);

    // Reverse-map H5P parameters to our simplified schema
    $quiz = PBSG_H5P_Factory::reverse($row['library_name'], $row['parameters']);

    wp_send_json_success(['quiz' => $quiz, 'library' => $row['library_name']]);
}
```

On the JS side, when rendering a step with `h5p_id > 0`, fire this AJAX call to populate the inline form. Cache the result so we don't re-fetch on every render.

---

### Phase 5: Auto-Naming Convention

Eliminate the need for manual naming like `t1-q1`. The H5P content title is auto-generated:

```
{Tutorial Title} — Step {N}
```

Example: `"Library Database Basics — Step 3"`

This is set in `PBSG_H5P_Factory::create()` using the tutorial post title + step index. If the step has a custom title, use that:

```
{Tutorial Title} — {Step Title}
```

Example: `"Library Database Basics — Advanced Search"`

The librarian never sees or manages the H5P title — it's internal bookkeeping.

---

### Phase 6: Validation & Error Handling

#### 6.1 Client-side validation (before save)

In JS, before allowing the page to be saved/published:

- Each step with a quiz must have a non-empty question
- Multiple Choice: at least one answer marked correct, at least 2 total answers
- Single Choice: at least 1 correct answer + 1 wrong answer
- Fill in Blanks: at least one `*word*` in the sentence
- Resource: warn (not block) if no tutorial URL/file is set

Display inline validation messages below each field.

#### 6.2 Server-side validation

In `save_meta()`, if H5P creation fails for any step:

- Log the error
- Keep `h5p_id = 0` for that step
- Add an admin notice: "Quiz for Step N could not be created: {reason}"
- The tutorial still saves — the step just won't have a working quiz until fixed

---

## File Change Summary

| File | Change Type | Description |
|------|------------|-------------|
| `pb-split-guide.php` | Modify | Add `ajax_create_h5p`, `ajax_get_h5p_content` endpoints; modify `save_meta()` to auto-create H5P |
| `includes/class-pbsg-h5p-factory.php` | **New** | H5P content creation + reverse-mapping logic |
| `includes/steps-normalizer.php` | Modify | Handle `quiz` key in step data |
| `assets/admin-split-guide.js` | **Major rewrite** | Replace table rows with card-based step editor, inline quiz forms, type-specific renderers |
| `assets/admin/admin-split-guide.css` | Modify | Styles for new card layout, quiz forms, validation states |
| `templates/split-guide-template.php` | No change | Frontend rendering unchanged (it only reads `h5p_id`) |
| `assets/split-guide.js` | No change | Student-facing JS unchanged |
| `class-pbsg-analytics.php` | No change | Analytics unchanged |

---

## Implementation Order & Estimates

| # | Task | Dependencies | Effort |
|---|------|-------------|--------|
| 1 | `PBSG_H5P_Factory` — create method for all 3 quiz types | None | Medium |
| 2 | `PBSG_H5P_Factory` — reverse method (H5P params → simple schema) | #1 | Medium |
| 3 | `save_meta()` modification — auto-create H5P on save | #1 | Small |
| 4 | New AJAX endpoints (`pbsg_create_h5p`, `pbsg_get_h5p_content`) | #1, #2 | Small |
| 5 | `steps-normalizer.php` — handle quiz key | None | Small |
| 6 | JS — step card layout + inline quiz forms (Multiple Choice) | None | Large |
| 7 | JS — Fill in Blanks form | #6 | Small |
| 8 | JS — Single Choice Set form | #6 | Small |
| 9 | JS — reverse population (load existing H5P into form) | #2, #4, #6 | Medium |
| 10 | JS — "Use existing H5P" escape hatch | #6 | Small |
| 11 | JS — drag-and-drop reorder | #6 | Small |
| 12 | CSS — card layout styling | #6 | Small |
| 13 | Client-side validation | #6, #7, #8 | Medium |
| 14 | PHPUnit tests for factory + normalizer | #1, #5 | Medium |

**Suggested build order:** 1 → 5 → 3 → 6 → 12 → 7 → 8 → 13 → 2 → 4 → 9 → 10 → 11 → 14

---

## Migration & Backwards Compatibility

- **Existing tutorials are unaffected.** Steps with `h5p_id > 0` and no `quiz` key work exactly as before.
- **Existing H5P content is untouched.** The factory only creates new records.
- **Old step format is still supported.** The normalizer continues to handle legacy `url` field migration.
- **Gradual adoption.** Librarians can mix inline-created quizzes with existing H5P picks on the same tutorial.

---

## Future Enhancements (Out of Scope for V1)

1. **Live preview pane** — render the H5P quiz inline in the editor so librarians see exactly what students will see
2. **Bulk import from CSV** — upload a spreadsheet of questions/answers/resources to auto-generate an entire tutorial
3. **Quiz templates** — save and reuse common question structures
4. **H5P content deduplication** — detect when the same question is authored twice and reuse the existing record
5. **Inline H5P editing** — when editing a step, update the existing H5P record instead of creating a new one (requires careful handling of shared content)
