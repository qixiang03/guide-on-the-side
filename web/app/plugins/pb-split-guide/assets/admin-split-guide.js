jQuery(function ($) {
  'use strict';

  // ═══════════════════════════════════════════════════════════
  //  Utilities
  // ═══════════════════════════════════════════════════════════
  function esc(str) {
    return String(str || '').replace(/[&<>"']/g, m =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
  }

  /**
   * Return an appropriate emoji icon for a filename based on its extension.
   */
  function filePreviewIcon(filename) {
    const ext = String(filename || '').split('.').pop().toLowerCase();
    const map = {
      pdf: '&#x1F4D1;',                                          // 📑
      doc: '&#x1F4DD;', docx: '&#x1F4DD;',                      // 📝
      xls: '&#x1F4CA;', xlsx: '&#x1F4CA;', csv: '&#x1F4CA;',   // 📊
      ppt: '&#x1F4BD;', pptx: '&#x1F4BD;',                      // 💽
      jpg: '&#x1F5BC;', jpeg: '&#x1F5BC;', png: '&#x1F5BC;',
      gif: '&#x1F5BC;', webp: '&#x1F5BC;', svg: '&#x1F5BC;',   // 🖼
      mp4: '&#x1F3AC;', mov: '&#x1F3AC;', avi: '&#x1F3AC;',
      webm: '&#x1F3AC;', mkv: '&#x1F3AC;',                      // 🎬
      mp3: '&#x1F3B5;', wav: '&#x1F3B5;', ogg: '&#x1F3B5;',    // 🎵
      zip: '&#x1F4E6;', rar: '&#x1F4E6;', gz: '&#x1F4E6;',     // 📦
    };
    return map[ext] || '&#x1F4C4;';  // default 📄
  }

  /**
   * Return a human-readable preview label for a filename based on its extension.
   */
  function filePreviewLabel(filename) {
    const ext = String(filename || '').split('.').pop().toLowerCase();
    const labels = {
      pdf:  'PDF will be embedded in the right pane',
      doc:  'Document will be available for download',
      docx: 'Document will be available for download',
      xls:  'Spreadsheet will be available for download',
      xlsx: 'Spreadsheet will be available for download',
      csv:  'Spreadsheet will be available for download',
      ppt:  'Presentation will be available for download',
      pptx: 'Presentation will be available for download',
      jpg:  'Image will be displayed in the right pane',
      jpeg: 'Image will be displayed in the right pane',
      png:  'Image will be displayed in the right pane',
      gif:  'Image will be displayed in the right pane',
      webp: 'Image will be displayed in the right pane',
      svg:  'Image will be displayed in the right pane',
      mp4:  'Video will be embedded in the right pane',
      mov:  'Video will be embedded in the right pane',
      avi:  'Video will be embedded in the right pane',
      webm: 'Video will be embedded in the right pane',
      mp3:  'Audio will be embedded in the right pane',
      wav:  'Audio will be embedded in the right pane',
      ogg:  'Audio will be embedded in the right pane',
    };
    return labels[ext] || 'File will be available as a resource in the right pane';
  }

  function getSteps() {
    try { const v = JSON.parse($('#pbsg_steps_json').val() || ''); return Array.isArray(v) ? v : []; }
    catch (e) { return []; }
  }

  function setSteps(steps) { $('#pbsg_steps_json').val(JSON.stringify(steps || [])); markDirty(); }

  // ═══════════════════════════════════════════════════════════
  //  Unsaved-changes detection
  // ═══════════════════════════════════════════════════════════
  let _savedSnapshot = '';   // JSON string of last-saved state
  let _isDirty = false;

  /** Take a snapshot of the current form state (steps JSON + intro fields). */
  function currentSnapshot() {
    const parts = [
      $('#pbsg_steps_json').val() || '',
      $('#pbsg_intro_title').val() || '',
      $('#pbsg_intro_subtitle').val() || '',
      $('#pbsg_intro_description').val() || '',
    ];
    return parts.join('|||');
  }

  /** Call after any user edit to flag the page as dirty. */
  function markDirty() {
    if (_savedSnapshot === '') return;  // still initialising
    if (currentSnapshot() !== _savedSnapshot) {
      _isDirty = true;
    }
  }

  /** Call on page-load and after a successful save to reset the dirty flag. */
  function markClean() {
    _savedSnapshot = currentSnapshot();
    _isDirty = false;
  }

  // Browser navigation guard
  $(window).on('beforeunload', function () {
    if (_isDirty) {
      return 'You have unsaved changes. Are you sure you want to leave?';
    }
  });

  function norm(s) {
    const o = Object.assign({}, s || {});
    if (!o.tutorial_type && o.url) { o.tutorial_type = 'url'; o.tutorial_url = o.url; }
    o.tutorial_url = o.tutorial_url || '';
    o.tutorial_attachment_id = o.tutorial_attachment_id || 0;
    o.tutorial_file_name = o.tutorial_file_name || '';
    o.tutorial_file_url = o.tutorial_file_url || '';
    o.quiz = o.quiz || null;
    o.h5p_id = o.h5p_id || 0;
    o.title = o.title || '';
    // Branch / sub-tutorial defaults
    o.branch_mode = o.branch_mode || 'none';
    if (!['none', 'optional', 'mandatory'].includes(o.branch_mode)) o.branch_mode = 'none';
    o.branch_trigger_attempts = parseInt(o.branch_trigger_attempts, 10) || 1;
    if (o.branch_trigger_attempts < 1) o.branch_trigger_attempts = 1;
    o.branch_title = o.branch_title || '';
    o.branch_intro = o.branch_intro || '';
    o.branch_tutorial_type = o.branch_tutorial_type || '';
    o.branch_tutorial_url = o.branch_tutorial_url || '';
    o.branch_tutorial_attachment_id = o.branch_tutorial_attachment_id || 0;
    o.branch_tutorial_file_name = o.branch_tutorial_file_name || '';
    o.branch_tutorial_file_url = o.branch_tutorial_file_url || '';
    return o;
  }

  function branchSummary(s) {
    s = norm(s);
    if (s.branch_mode === 'none') return '';
    const mode = s.branch_mode === 'mandatory' ? 'Mandatory' : 'Optional';
    const count = s.branch_trigger_attempts;
    return mode + ' \u00B7 after ' + count + ' wrong ' + (count === 1 ? 'attempt' : 'attempts');
  }

  // ═══════════════════════════════════════════════════════════
  //  Template Watcher (unchanged logic)
  // ═══════════════════════════════════════════════════════════
  function isSplitGuide() {
    const $t = $('#page_template');
    return $t.length > 0 && $t.val() === PBSG_ADMIN.templateSlug;
  }
  function toggleMetaBox() {
    const $b = $('#' + PBSG_ADMIN.metaBoxId).closest('.postbox');
    if ($b.length) $b[isSplitGuide() ? 'show' : 'hide']();
  }
  function forceTemplate() {
    if (!PBSG_ADMIN.isNewPage) return false;
    const $t = $('#page_template');
    if (!$t.length || !$t.find('option[value="' + PBSG_ADMIN.templateSlug + '"]').length) return false;
    if ($t.val() !== PBSG_ADMIN.templateSlug) $t.val(PBSG_ADMIN.templateSlug).trigger('change');
    toggleMetaBox();
    return true;
  }
  $(document).on('change', '#page_template', toggleMetaBox);
  if (!PBSG_ADMIN.isNewPage) { toggleMetaBox(); }
  else {
    let tries = 0;
    const tmr = setInterval(() => { if (forceTemplate() || ++tries >= 40) { clearInterval(tmr); toggleMetaBox(); } }, 250);
    $(window).on('load', () => { forceTemplate(); toggleMetaBox(); });
    new MutationObserver(() => { forceTemplate(); toggleMetaBox(); }).observe(document.body, { childList: true, subtree: true });
  }

  // ═══════════════════════════════════════════════════════════
  //  Collapsed State Tracking (Issue 1)
  // ═══════════════════════════════════════════════════════════
  const collapsedSteps = new Set();

  // ═══════════════════════════════════════════════════════════
  //  Intro Section Toggle
  // ═══════════════════════════════════════════════════════════
  $(document).on('click', '#pbsg-intro-toggle', function () {
    const $body = $('#pbsg-intro-body'), $chev = $('#pbsg-intro-chevron');
    $body.toggle();
    $chev.html($body.is(':visible') ? '&#x25BC;' : '&#x25B6;');
  });

  // ═══════════════════════════════════════════════════════════
  //  Objectives (Phase 7)
  // ═══════════════════════════════════════════════════════════
  function syncObj() {
    const arr = [];
    $('#pbsg-objectives-list .pbsg-objective-input').each(function () { const v = $(this).val().trim(); if (v) arr.push(v); });
    $('#pbsg_intro_objectives').val(JSON.stringify(arr));
  }
  $(document).on('click', '#pbsg-add-objective', function () {
    $('#pbsg-objectives-list').append(`
      <div class="pbsg-objective-row">
        <span class="pbsg-objective-check">&#x2713;</span>
        <input type="text" class="pbsg-objective-input" placeholder="Learning objective..." />
        <button type="button" class="pbsg-objective-remove" title="Remove">&times;</button>
      </div>`);
    $('#pbsg-objectives-list .pbsg-objective-input').last().focus();
  });
  $(document).on('click', '.pbsg-objective-remove', function () { $(this).closest('.pbsg-objective-row').remove(); syncObj(); });
  $(document).on('input', '.pbsg-objective-input', syncObj);
  syncObj();

  // ═══════════════════════════════════════════════════════════
  //  Step Card Rendering
  // ═══════════════════════════════════════════════════════════
  function quizName(t) { return { multichoice: 'Multiple Choice', blanks: 'Fill in Blanks', singlechoice: 'Single Choice' }[t] || ''; }

  function renderStepCards() {
    const steps = getSteps().map(norm);
    const $c = $('#pbsg-steps-container');
    if (!$c.length) return;

    // Snapshot collapsed state before wiping DOM
    $c.find('.pbsg-step-card--collapsed').each(function () {
      collapsedSteps.add(parseInt($(this).data('idx'), 10));
    });

    $c.empty();

    steps.forEach((s, idx) => {
      const num = idx + 1;
      const qt = s.quiz ? s.quiz.type : '';
      const hasQuiz = qt || s.h5p_id > 0;
      const hasRes = s.tutorial_type === 'url' || s.tutorial_type === 'file';

      const quizBadge = hasQuiz ? `<span class="pbsg-badge pbsg-badge--info">${qt ? esc(quizName(qt)) : 'H5P #' + s.h5p_id}</span>` : '';
      const resBadge = hasRes ? `<span class="pbsg-badge pbsg-badge--ok">Resource &#x2713;</span>` : '';

      $c.append(`
        <div class="pbsg-step-card" data-idx="${idx}" id="pbsg-step-${idx}">
          <div class="pbsg-step-header">
            <span class="pbsg-drag-handle" title="Drag to reorder">&#x2807;</span>
            <span class="pbsg-step-number">${num}</span>
            <input class="pbsg-step-title-input" type="text" value="${esc(s.title)}" placeholder="Page title (optional)" data-idx="${idx}" />
            <div class="pbsg-step-badges">
              ${quizBadge}${resBadge}
            </div>
            <div class="pbsg-step-header-actions">
              <span class="pbsg-step-chevron" data-idx="${idx}" title="Collapse">&#x25BC;</span>
              <button type="button" class="pbsg-btn-ghost pbsg-remove-step" data-idx="${idx}" title="Remove step">&times;</button>
            </div>
          </div>
          <div class="pbsg-step-body" data-idx="${idx}">
            <div class="pbsg-panel pbsg-panel-quiz" data-idx="${idx}">
              <div class="pbsg-panel-label">
                <span class="pbsg-panel-icon">&#x1F9E9;</span> Quiz Question
              </div>
              ${renderQuizPanel(s, idx)}
            </div>
            <div class="pbsg-panel" data-idx="${idx}">
              <div class="pbsg-panel-label">
                <span class="pbsg-panel-icon">&#x1F4D6;</span> Tutorial Resource
              </div>
              ${renderResourcePanel(s, idx)}
            </div>
            <div class="pbsg-panel pbsg-panel-branch" data-idx="${idx}" style="grid-column: 1 / -1; border-top: 1px solid #F1F1F1; padding: 12px 20px;">
              <div style="display:flex; align-items:center; gap:10px;">
                <span class="pbsg-panel-icon">&#x1F500;</span>
                <strong style="font-size:13px;">Branch Review</strong>
                <span class="pbsg-branch-summary" style="flex:1; opacity:.85; font-size:13px;">${esc(branchSummary(s))}</span>
                <button type="button" class="button pbsg-set-branch" data-idx="${idx}">Set Branch</button>
                <button type="button" class="button pbsg-clear-branch" data-idx="${idx}">Clear</button>
              </div>
            </div>
            <div class="pbsg-step-footer">
              <button type="button" class="button pbsg-collapse-step" data-idx="${idx}">Collapse</button>
            </div>
          </div>
        </div>`);

      // Restore collapsed state
      if (collapsedSteps.has(idx)) {
        $(`#pbsg-step-${idx}`).addClass('pbsg-step-card--collapsed');
      }
    });

    // SortableJS (optional)
    if (typeof Sortable !== 'undefined' && $c[0]) {
      if ($c[0]._sortable) $c[0]._sortable.destroy();
      $c[0]._sortable = Sortable.create($c[0], {
        handle: '.pbsg-drag-handle',
        animation: 200,
        ghostClass: 'sortable-ghost',
        onEnd: function () { reorderFromDOM(); renderStepCards(); }
      });
    }

    setSteps(steps);
  }

  // ─── Quiz Panel ────────────────────────────────────────
  function renderQuizPanel(s, idx) {
    if (s.h5p_id > 0 && !s.quiz) {
      return `<div class="pbsg-linked-h5p">
        <div class="pbsg-linked-h5p-info">
          <span class="pbsg-linked-icon">&#x1F517;</span>
          <span>Linked to <strong>H5P #${s.h5p_id}</strong></span>
        </div>
        <div class="pbsg-linked-h5p-actions">
          <a href="#" class="pbsg-detach-h5p" data-idx="${idx}">Detach &amp; create new inline</a>
          <span class="pbsg-sep">|</span>
          <a href="#" class="pbsg-use-existing-h5p" data-idx="${idx}">Change H5P</a>
          <span class="pbsg-sep">|</span>
          <a href="#" class="pbsg-edit-h5p" data-idx="${idx}" data-h5p-id="${s.h5p_id}">Edit H5P</a>
        </div>
      </div>`;
    }

    const qt = s.quiz ? s.quiz.type : 'multichoice';

    return `
      <div class="pbsg-quiz-type-selector" data-idx="${idx}">
        <button type="button" class="pbsg-quiz-type-btn${qt === 'multichoice' ? ' active' : ''}" data-type="multichoice" data-idx="${idx}">
          <span class="pbsg-type-icon">&#x2611;</span>Multiple Choice</button>
        <button type="button" class="pbsg-quiz-type-btn${qt === 'blanks' ? ' active' : ''}" data-type="blanks" data-idx="${idx}">
          <span class="pbsg-type-icon">&#x270F;&#xFE0F;</span>Fill in Blanks</button>
        <button type="button" class="pbsg-quiz-type-btn${qt === 'singlechoice' ? ' active' : ''}" data-type="singlechoice" data-idx="${idx}">
          <span class="pbsg-type-icon">&#x25C9;</span>Single Choice</button>
      </div>
      <div class="pbsg-quiz-form" data-idx="${idx}">${renderQuizForm(qt, s.quiz || {}, idx)}</div>
      <div class="pbsg-existing-h5p-link">
        <span>&#x1F4A1;</span>
        <span>Or <a href="#" class="pbsg-use-existing-h5p" data-idx="${idx}">use an existing H5P quiz</a> instead</span>
      </div>`;
  }

  function renderQuizForm(type, quiz, idx) {
    if (type === 'blanks') return renderBlanksForm(quiz, idx);
    if (type === 'singlechoice') return renderSCForm(quiz, idx);
    return renderMCForm(quiz, idx);
  }

  // ─── Multiple Choice ──────────────────────────────────
  function renderMCForm(quiz, idx) {
    const q = quiz.question || '';
    const ans = quiz.answers || [{ text: '', correct: true }, { text: '', correct: false }];

    let rows = '';
    ans.forEach((a, ai) => {
      const cor = a.correct;
      const rowClass = 'pbsg-answer-row' + (cor ? ' pbsg-answer-row--correct' : ' pbsg-answer-row--incorrect');
      rows += `<div class="${rowClass}">
        <input type="checkbox" class="pbsg-answer-check" data-idx="${idx}" data-aidx="${ai}" ${cor ? 'checked' : ''} />
        <input type="text" class="pbsg-answer-input" data-idx="${idx}" data-aidx="${ai}" value="${esc(a.text)}" placeholder="Answer option..." />
        ${cor ? `<span class="pbsg-answer-correct-label">&#x2713; Correct</span>` : ''}
        <button type="button" class="pbsg-answer-remove" data-idx="${idx}" data-aidx="${ai}" title="Remove">&times;</button>
      </div>`;
    });

    return `
      <div class="pbsg-field">
        <label class="pbsg-field-label">Question</label>
        <input type="text" class="pbsg-quiz-question" data-idx="${idx}" data-quiz-field="question" value="${esc(q)}" placeholder="Enter your question..." />
      </div>
      <div class="pbsg-field">
        <label class="pbsg-field-label">Answers <span class="pbsg-field-optional">&mdash; check the correct one(s)</span></label>
        <div class="pbsg-answers-list" data-idx="${idx}">${rows}</div>
        <button type="button" class="pbsg-btn-outline pbsg-add-answer" data-idx="${idx}">+ Add Answer</button>
      </div>`;
  }

  // ─── Fill in the Blanks ───────────────────────────────
  function renderBlanksForm(quiz, idx) {
    const sentence = quiz.sentence || '';
    const caseSens = quiz.case_sensitive !== undefined ? quiz.case_sensitive : false;
    const typos = quiz.accept_typos !== undefined ? quiz.accept_typos : false;
    const preview = blanksPreview(sentence);
    const cnt = (sentence.match(/\*[^*]+\*/g) || []).length;

    return `
      <div class="pbsg-field">
        <label class="pbsg-field-label">Sentence</label>
        <textarea class="pbsg-blanks-sentence" data-idx="${idx}" rows="3" placeholder="Wrap answer words with *asterisks*...">${esc(sentence)}</textarea>
        <div class="pbsg-field-hint">
          Wrap answer words with <code>*asterisks*</code> &mdash; they become blank fields.<br>
          Use <code>/</code> for alternatives: <code>*colour/color*</code><br>
          Use <code>:</code> to add a hint: <code>*Norway:Scandinavian country*</code>
        </div>
      </div>
      <div class="pbsg-field">
        <label class="pbsg-field-label">Preview</label>
        <div class="pbsg-blanks-preview" data-idx="${idx}">${preview}</div>
        ${cnt > 0
          ? `<div class="pbsg-validation-msg pbsg-validation-msg--ok">&#x2713; ${cnt} blank${cnt !== 1 ? 's' : ''} detected</div>`
          : `<div class="pbsg-validation-msg pbsg-validation-msg--error">&#x26A0; No blanks detected &mdash; wrap words with *asterisks*</div>`}
      </div>
      <div class="pbsg-field">
        <label class="pbsg-field-label">Answer Validation</label>
        <div class="pbsg-blanks-options">
          <label class="pbsg-toggle-option${caseSens ? ' pbsg-toggle-option--active' : ''}">
            <input type="checkbox" class="pbsg-blanks-case" data-idx="${idx}" ${caseSens ? 'checked' : ''} />
            <div>
              <div class="pbsg-toggle-title">Case sensitive</div>
              <div class="pbsg-toggle-desc">&ldquo;Norway&rdquo; &#x2260; &ldquo;norway&rdquo; &mdash; students must match exact capitalization</div>
            </div>
          </label>
          <label class="pbsg-toggle-option${typos ? ' pbsg-toggle-option--active' : ''}">
            <input type="checkbox" class="pbsg-blanks-typos" data-idx="${idx}" ${typos ? 'checked' : ''} />
            <div>
              <div class="pbsg-toggle-title">Accept minor spelling errors</div>
              <div class="pbsg-toggle-desc">Words 3&ndash;9 chars: 1 typo allowed &middot; 10+ chars: 2 typos allowed</div>
            </div>
          </label>
        </div>
      </div>`;
  }

  function blanksPreview(sentence) {
    if (!sentence) return '<span class="pbsg-blanks-empty">Type a sentence above to see the preview</span>';
    return esc(sentence).replace(/\*([^*]+)\*/g, function (_m, inner) {
      let display = inner, hint = '';
      const ci = inner.indexOf(':');
      if (ci > -1) { display = inner.substring(0, ci); hint = inner.substring(ci + 1); }
      const first = display.split('/')[0];
      const hasAlts = display.indexOf('/') > -1;
      const hintHtml = hint ? ` <span class="pbsg-hint-icon" title="Hint: ${esc(hint)}">&#x1F4A1;</span>` : '';
      const altTitle = hasAlts ? ` title="Also accepts: ${esc(display.split('/').slice(1).join(', '))}"` : '';
      return `<span class="pbsg-blank-slot"${altTitle}>${esc(first)}${hintHtml}</span>`;
    });
  }

  // ─── Single Choice ────────────────────────────────────
  function renderSCForm(quiz, idx) {
    const q = quiz.question || '';
    const correct = quiz.correct_answer || '';
    const wrongs = quiz.wrong_answers || [''];

    let wrongRows = '';
    wrongs.forEach((w, wi) => {
      wrongRows += `<div class="pbsg-answer-row pbsg-answer-row--incorrect">
        <input type="text" class="pbsg-sc-wrong-input" data-idx="${idx}" data-widx="${wi}" value="${esc(w)}" placeholder="Wrong answer..." />
        <button type="button" class="pbsg-sc-wrong-remove" data-idx="${idx}" data-widx="${wi}" title="Remove">&times;</button>
      </div>`;
    });

    return `
      <div class="pbsg-field">
        <label class="pbsg-field-label">Question</label>
        <input type="text" class="pbsg-quiz-question" data-idx="${idx}" data-quiz-field="question" value="${esc(q)}" placeholder="Enter your question..." />
      </div>
      <div class="pbsg-field">
        <label class="pbsg-field-label">Correct Answer</label>
        <div class="pbsg-sc-correct-row">
          <span class="pbsg-sc-icon">&#x2713;</span>
          <input type="text" class="pbsg-sc-correct-input" data-idx="${idx}" value="${esc(correct)}" placeholder="The correct answer..." />
        </div>
      </div>
      <div class="pbsg-field">
        <label class="pbsg-field-label">Wrong Answers</label>
        <div class="pbsg-answers-list pbsg-sc-wrong-list" data-idx="${idx}">${wrongRows}</div>
        <button type="button" class="pbsg-btn-outline pbsg-add-sc-wrong" data-idx="${idx}">+ Add Wrong Answer</button>
      </div>`;
  }

  // ─── Resource Panel ───────────────────────────────────
  function renderResourcePanel(s, idx) {
    s = norm(s);
    const isFile = s.tutorial_type === 'file';

    let content = '';
    if (!isFile) {
      const isYT = /youtube\.com|youtu\.be/i.test(s.tutorial_url);
      content = `
        <div class="pbsg-field">
          <label class="pbsg-field-label">URL</label>
          <input type="url" class="pbsg-resource-url" data-idx="${idx}" value="${esc(s.tutorial_url)}" placeholder="https://..." />
          <div class="pbsg-field-hint">YouTube links are auto-embedded. Library database URLs open in an iframe.</div>
        </div>
        ${s.tutorial_url ? `<div class="pbsg-resource-preview">
          <span class="pbsg-preview-icon">${isYT ? '&#x25B6;&#xFE0F;' : '&#x1F310;'}</span>
          ${isYT ? 'YouTube video will be embedded in the right pane' : 'Page will load in the right pane iframe'}
        </div>` : ''}`;
    } else {
      const fn = s.tutorial_file_name || (s.tutorial_attachment_id ? 'Attachment #' + s.tutorial_attachment_id : '');
      content = fn
        ? `<div class="pbsg-file-info">
            <span class="pbsg-file-icon">&#x1F4C4;</span>
            <div>
              <div class="pbsg-file-name">${esc(fn)}</div>
              <div class="pbsg-file-meta">Uploaded</div>
            </div>
            <button type="button" class="pbsg-btn-ghost pbsg-clear-resource" data-idx="${idx}" title="Remove file">&times;</button>
          </div>
          <div class="pbsg-resource-preview">
            <span class="pbsg-preview-icon">${filePreviewIcon(fn)}</span>${filePreviewLabel(fn)}
          </div>`
        : `<div class="pbsg-upload-zone" data-idx="${idx}">
            <span class="pbsg-upload-icon">&#x2B06;&#xFE0F;</span>
            <div>Drag &amp; drop a file here, or click to browse</div>
            ${PBSG_ADMIN.maxUploadLabel ? `<div class="pbsg-upload-size-hint">Max file size: ${esc(PBSG_ADMIN.maxUploadLabel)}</div>` : ''}
          </div>`;
    }

    return `
      <div class="pbsg-resource-type-toggle" data-idx="${idx}">
        <label class="${!isFile ? 'active' : ''}">
          <input type="radio" name="pbsg_res_type_${idx}" value="url" ${!isFile ? 'checked' : ''} />
          <span>&#x1F517; URL</span>
        </label>
        <label class="${isFile ? 'active' : ''}">
          <input type="radio" name="pbsg_res_type_${idx}" value="file" ${isFile ? 'checked' : ''} />
          <span>&#x1F4C4; Upload File</span>
        </label>
      </div>
      ${content}`;
  }

  // ═══════════════════════════════════════════════════════════
  //  Event Handlers — Steps
  // ═══════════════════════════════════════════════════════════
  $(document).on('click', '#pbsg-add-step', function () {
    const steps = getSteps().map(norm);
    steps.push({ title: '', h5p_id: 0, quiz: { type: 'multichoice', question: '', answers: [{ text: '', correct: true }, { text: '', correct: false }] },
      tutorial_type: '', tutorial_url: '', tutorial_attachment_id: 0, tutorial_file_name: '', tutorial_file_url: '', url: '',
      branch_mode: 'none', branch_trigger_attempts: 1, branch_title: '', branch_intro: '',
      branch_tutorial_type: '', branch_tutorial_url: '', branch_tutorial_attachment_id: 0,
      branch_tutorial_file_name: '', branch_tutorial_file_url: '' });
    setSteps(steps);
    renderStepCards();
    const $last = $('.pbsg-step-card').last();
    if ($last.length) $('html, body').animate({ scrollTop: $last.offset().top - 60 }, 300);
  });

  $(document).on('click', '.pbsg-remove-step', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx) || !confirm('Remove this step?')) return;
    const steps = getSteps().map(norm); steps.splice(idx, 1); setSteps(steps); renderStepCards();
  });

  $(document).on('click', '.pbsg-step-chevron, .pbsg-collapse-step', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;
    const $card = $(`#pbsg-step-${idx}`);
    $card.toggleClass('pbsg-step-card--collapsed');
    if ($card.hasClass('pbsg-step-card--collapsed')) collapsedSteps.add(idx);
    else collapsedSteps.delete(idx);
  });

  $(document).on('input', '.pbsg-step-title-input', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;
    const steps = getSteps().map(norm);
    if (steps[idx]) { steps[idx].title = $(this).val() || ''; setSteps(steps); }
  });

  function reorderFromDOM() {
    const steps = getSteps().map(norm), newOrder = [];
    $('#pbsg-steps-container .pbsg-step-card').each(function () {
      const idx = parseInt($(this).data('idx'), 10);
      if (!isNaN(idx) && steps[idx]) newOrder.push(steps[idx]);
    });
    setSteps(newOrder);
  }

  // ═══════════════════════════════════════════════════════════
  //  Quiz Type Switching
  // ═══════════════════════════════════════════════════════════
  $(document).on('click', '.pbsg-quiz-type-btn', function () {
    const idx = parseInt($(this).data('idx'), 10), type = $(this).data('type');
    if (isNaN(idx) || !type) return;
    syncQuiz(idx);
    const steps = getSteps().map(norm), step = steps[idx];
    if (!step) return;
    const newQ = { type };
    switch (type) {
      case 'multichoice': newQ.question = (step.quiz && step.quiz.question) || ''; newQ.answers = [{ text: '', correct: true }, { text: '', correct: false }]; break;
      case 'blanks': newQ.sentence = ''; newQ.case_sensitive = false; newQ.accept_typos = false; break;
      case 'singlechoice': newQ.question = (step.quiz && step.quiz.question) || ''; newQ.correct_answer = ''; newQ.wrong_answers = ['']; break;
    }
    step.quiz = newQ; step.h5p_id = 0; steps[idx] = step; setSteps(steps); renderStepCards();
  });

  // ═══════════════════════════════════════════════════════════
  //  Multiple Choice Events
  // ═══════════════════════════════════════════════════════════
  $(document).on('input', '.pbsg-quiz-question, .pbsg-answer-input', function () { syncQuiz(parseInt($(this).data('idx'), 10)); });
  $(document).on('change', '.pbsg-answer-check', function () {
    const idx = parseInt($(this).data('idx'), 10);
    syncQuiz(idx);
    renderStepCards(); // re-render to update correct/incorrect styling
  });
  $(document).on('click', '.pbsg-add-answer', function () {
    const idx = parseInt($(this).data('idx'), 10), steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz) { steps[idx].quiz.answers = steps[idx].quiz.answers || []; steps[idx].quiz.answers.push({ text: '', correct: false }); setSteps(steps); renderStepCards(); }
  });
  $(document).on('click', '.pbsg-answer-remove', function () {
    const idx = parseInt($(this).data('idx'), 10), ai = parseInt($(this).data('aidx'), 10), steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz && steps[idx].quiz.answers && steps[idx].quiz.answers.length > 2) { steps[idx].quiz.answers.splice(ai, 1); setSteps(steps); renderStepCards(); }
  });

  // ═══════════════════════════════════════════════════════════
  //  Fill in the Blanks Events
  // ═══════════════════════════════════════════════════════════
  $(document).on('input', '.pbsg-blanks-sentence', function () {
    const idx = parseInt($(this).data('idx'), 10), sentence = $(this).val();
    const $card = $(`#pbsg-step-${idx}`);
    $card.find('.pbsg-blanks-preview').html(blanksPreview(sentence));
    const cnt = (sentence.match(/\*[^*]+\*/g) || []).length;
    const $vm = $card.find('.pbsg-validation-msg');
    if (cnt > 0) {
      $vm.removeClass('pbsg-validation-msg--error').addClass('pbsg-validation-msg--ok')
        .html('&#x2713; ' + cnt + ' blank' + (cnt !== 1 ? 's' : '') + ' detected');
    } else {
      $vm.removeClass('pbsg-validation-msg--ok').addClass('pbsg-validation-msg--error')
        .html('&#x26A0; No blanks detected &mdash; wrap words with *asterisks*');
    }
    syncQuiz(idx);
  });
  $(document).on('change', '.pbsg-blanks-case, .pbsg-blanks-typos', function () {
    const $opt = $(this).closest('.pbsg-toggle-option');
    $opt.toggleClass('pbsg-toggle-option--active', $(this).is(':checked'));
    syncQuiz(parseInt($(this).data('idx'), 10));
  });

  // ═══════════════════════════════════════════════════════════
  //  Single Choice Events
  // ═══════════════════════════════════════════════════════════
  $(document).on('input', '.pbsg-sc-correct-input, .pbsg-sc-wrong-input', function () { syncQuiz(parseInt($(this).data('idx'), 10)); });
  $(document).on('click', '.pbsg-add-sc-wrong', function () {
    const idx = parseInt($(this).data('idx'), 10), steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz) { steps[idx].quiz.wrong_answers = steps[idx].quiz.wrong_answers || []; steps[idx].quiz.wrong_answers.push(''); setSteps(steps); renderStepCards(); }
  });
  $(document).on('click', '.pbsg-sc-wrong-remove', function () {
    const idx = parseInt($(this).data('idx'), 10), wi = parseInt($(this).data('widx'), 10), steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz && steps[idx].quiz.wrong_answers && steps[idx].quiz.wrong_answers.length > 1) { steps[idx].quiz.wrong_answers.splice(wi, 1); setSteps(steps); renderStepCards(); }
  });

  // ═══════════════════════════════════════════════════════════
  //  Sync Quiz Data from Form
  // ═══════════════════════════════════════════════════════════
  function syncQuiz(idx) {
    if (isNaN(idx)) return;
    const steps = getSteps().map(norm), step = steps[idx];
    if (!step || !step.quiz) return;
    const $card = $(`#pbsg-step-${idx}`);
    if (!$card.length) return;
    switch (step.quiz.type) {
      case 'multichoice': {
        step.quiz.question = $card.find('.pbsg-quiz-question').val() || '';
        const a = []; $card.find('.pbsg-answers-list > div').each(function () { a.push({ text: $(this).find('.pbsg-answer-input').val() || '', correct: $(this).find('.pbsg-answer-check').is(':checked') }); });
        step.quiz.answers = a; break;
      }
      case 'blanks': {
        step.quiz.sentence = $card.find('.pbsg-blanks-sentence').val() || '';
        step.quiz.case_sensitive = $card.find('.pbsg-blanks-case').is(':checked');
        step.quiz.accept_typos = $card.find('.pbsg-blanks-typos').is(':checked'); break;
      }
      case 'singlechoice': {
        step.quiz.question = $card.find('.pbsg-quiz-question').val() || '';
        step.quiz.correct_answer = $card.find('.pbsg-sc-correct-input').val() || '';
        const w = []; $card.find('.pbsg-sc-wrong-input').each(function () { w.push($(this).val() || ''); });
        step.quiz.wrong_answers = w; break;
      }
    }
    steps[idx] = step; setSteps(steps);
  }

  // ═══════════════════════════════════════════════════════════
  //  Resource Panel Events
  // ═══════════════════════════════════════════════════════════
  // Issue 6: Fix resource panel — don't clear URL on toggle, only on successful file select
  $(document).on('change', '.pbsg-resource-type-toggle input[type="radio"]', function () {
    const idx = parseInt($(this).closest('.pbsg-resource-type-toggle').data('idx'), 10), type = $(this).val();
    if (isNaN(idx)) return;
    syncResource(idx);
    const steps = getSteps().map(norm), step = steps[idx]; if (!step) return;

    if (type === 'file') {
      // Switching to file mode — always set type to 'file' so upload zone renders
      step.tutorial_type = 'file';
      steps[idx] = step; setSteps(steps); renderStepCards();
    } else {
      // Switching to URL mode — clear file data, keep URL
      step.tutorial_type = step.tutorial_url ? 'url' : '';
      step.tutorial_attachment_id = 0;
      step.tutorial_file_name = '';
      step.tutorial_file_url = '';
      steps[idx] = step; setSteps(steps); renderStepCards();
    }
  });

  $(document).on('input', '.pbsg-resource-url', function () { syncResource(parseInt($(this).data('idx'), 10)); });

  // Upload zone — click to open media picker, with wp.media guard
  $(document).on('click', '.pbsg-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation(); // prevent event from bubbling to card collapse
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;

    if (typeof wp === 'undefined' || typeof wp.media !== 'function') {
      alert('Media uploader not available. Please reload the page and try again.');
      return;
    }

    openMediaPicker(idx);
  });

  // Drag-and-drop on upload zone
  $(document).on('dragover dragenter', '.pbsg-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).addClass('pbsg-upload-zone--dragover');
  });

  $(document).on('dragleave', '.pbsg-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('pbsg-upload-zone--dragover');
  });

  $(document).on('drop', '.pbsg-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('pbsg-upload-zone--dragover');

    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;

    const files = e.originalEvent.dataTransfer.files;
    if (!files || files.length === 0) return;

    const file = files[0];

    // Client-side file size validation
    const maxSize = PBSG_ADMIN.maxUploadSize || 0;
    if (maxSize > 0 && file.size > maxSize) {
      const $zone = $(this);
      $zone.find('.pbsg-upload-error').remove();
      $zone.append(`<div class="pbsg-upload-error">File too large. Maximum size: ${PBSG_ADMIN.maxUploadLabel || 'unknown'}</div>`);
      return;
    }

    uploadFileViaDrop(file, idx, $(this));
  });

  /**
   * Upload a dropped file via our custom pbsg_upload_file AJAX handler.
   * Uses media_handle_upload() on the server so the file enters the
   * WP media library exactly like the media picker would.
   */
  function uploadFileViaDrop(file, idx, $zone) {
    // Show progress UI
    $zone.find('.pbsg-upload-error, .pbsg-upload-progress').remove();
    $zone.append(`<div class="pbsg-upload-progress"><div class="pbsg-upload-progress-bar" style="width:0%"></div></div>`);
    const $bar = $zone.find('.pbsg-upload-progress-bar');

    const formData = new FormData();
    formData.append('pbsg_file', file);                    // matches $_FILES['pbsg_file'] in PHP
    formData.append('action', 'pbsg_upload_file');         // routes to our ajax_upload_file handler
    formData.append('nonce', PBSG_ADMIN.uploadNonce);      // verified by check_ajax_referer()

    $.ajax({
      url: PBSG_ADMIN.ajaxUrl,
      type: 'POST',
      data: formData,
      processData: false,
      contentType: false,
      xhr: function () {
        const xhr = new XMLHttpRequest();
        xhr.upload.addEventListener('progress', function (evt) {
          if (evt.lengthComputable) {
            const pct = Math.round((evt.loaded / evt.total) * 100);
            $bar.css('width', pct + '%');
          }
        }, false);
        return xhr;
      },
      success: function (res) {
        $zone.find('.pbsg-upload-progress').remove();
        if (res && res.success && res.data) {
          const att = res.data;
          const steps = getSteps().map(norm);
          if (steps[idx]) {
            steps[idx].tutorial_type = 'file';
            steps[idx].tutorial_attachment_id = att.id || 0;
            steps[idx].tutorial_file_name = att.filename || '';
            steps[idx].tutorial_file_url = att.url || '';
            steps[idx].tutorial_url = '';
            steps[idx].url = '';
            setSteps(steps);
            renderStepCards();
          }
        } else {
          const msg = (res && res.data && res.data.message) ? res.data.message : 'Upload failed. Please try again.';
          $zone.append(`<div class="pbsg-upload-error">${esc(msg)}</div>`);
        }
      },
      error: function (xhr) {
        $zone.find('.pbsg-upload-progress').remove();
        let msg = 'Upload failed. Please try again.';
        try {
          const r = JSON.parse(xhr.responseText);
          if (r && r.data && r.data.message) msg = r.data.message;
        } catch (_) { /* ignore parse error */ }
        $zone.append(`<div class="pbsg-upload-error">${esc(msg)}</div>`);
      }
    });
  }

  /**
   * Open the WordPress media picker for a given step index.
   */
  function openMediaPicker(idx) {
    const frame = wp.media({ title: 'Select or Upload Tutorial File', button: { text: 'Use this file' }, multiple: false });
    let fileSelected = false;

    frame.on('select', function () {
      fileSelected = true;
      const att = frame.state().get('selection').first().toJSON();
      const steps = getSteps().map(norm);
      if (steps[idx]) {
        steps[idx].tutorial_type = 'file';
        steps[idx].tutorial_attachment_id = att.id || 0;
        steps[idx].tutorial_file_name = att.filename || att.title || '';
        steps[idx].tutorial_file_url = att.url || '';
        // Clear URL since file was successfully chosen
        steps[idx].tutorial_url = '';
        steps[idx].url = '';
        setSteps(steps);
        renderStepCards();
      }
    });

    // If user closes picker without selecting, revert to URL mode if no file
    frame.on('close', function () {
      if (!fileSelected) {
        const steps = getSteps().map(norm);
        if (steps[idx] && !steps[idx].tutorial_attachment_id) {
          steps[idx].tutorial_type = steps[idx].tutorial_url ? 'url' : '';
          setSteps(steps);
          renderStepCards();
        }
      }
    });

    frame.open();
  }

  $(document).on('click', '.pbsg-clear-resource', function () {
    const idx = parseInt($(this).data('idx'), 10); if (isNaN(idx)) return;
    const steps = getSteps().map(norm);
    if (steps[idx]) { steps[idx].tutorial_type = ''; steps[idx].tutorial_url = ''; steps[idx].tutorial_attachment_id = 0; steps[idx].tutorial_file_name = ''; steps[idx].tutorial_file_url = ''; steps[idx].url = ''; setSteps(steps); renderStepCards(); }
  });

  function syncResource(idx) {
    if (isNaN(idx)) return;
    const steps = getSteps().map(norm), step = steps[idx]; if (!step) return;
    const $card = $(`#pbsg-step-${idx}`), url = $card.find('.pbsg-resource-url').val() || '';
    if (step.tutorial_type !== 'file') { step.tutorial_url = url; step.tutorial_type = url ? 'url' : ''; step.url = url; }
    steps[idx] = step; setSteps(steps);
  }

  // ═══════════════════════════════════════════════════════════
  //  Use Existing H5P / Detach
  // ═══════════════════════════════════════════════════════════
  let pickIdx = null;
  $(document).on('click', '.pbsg-use-existing-h5p', function (e) {
    e.preventDefault();
    pickIdx = parseInt($(this).data('idx'), 10);
    if (isNaN(pickIdx)) return;
    $.post(PBSG_ADMIN.ajaxUrl, { action: 'pbsg_list_h5p', nonce: PBSG_ADMIN.nonce })
      .done(function (res) { if (res && res.success) openPicker(res.data.items || []); else alert(res && res.data ? res.data.message : 'Could not load H5P items.'); })
      .fail(function () { alert('Request failed while loading H5P items.'); });
  });

  function openPicker(items) {
    const opts = items.map(i => `<option value="${i.id}">${esc(i.title)} (ID: ${i.id})</option>`).join('');
    if (!$('#pbsg-h5p-inline').length) $('body').append('<div id="pbsg-h5p-inline" style="display:none;"></div>');
    $('#pbsg-h5p-inline').html(`<div style="padding:14px;"><h2 style="margin-top:0;">Select an H5P quiz</h2><p>Pick a quiz to link to this step.</p>
      <select id="pbsg-h5p-select" style="width:100%;max-width:520px;"><option value="">— Select H5P —</option>${opts}</select>
      <div style="margin-top:12px;display:flex;gap:8px;"><button type="button" class="button button-primary" id="pbsg-h5p-insert">Insert</button><button type="button" class="button" id="pbsg-h5p-cancel">Cancel</button></div></div>`);
    tb_show('Select H5P', '#TB_inline?inlineId=pbsg-h5p-inline&width=640&height=280');
    $('#pbsg-h5p-cancel').on('click', tb_remove);
    $('#pbsg-h5p-insert').on('click', function () {
      const id = parseInt($('#pbsg-h5p-select').val(), 10);
      if (!id || pickIdx === null) return;
      const steps = getSteps().map(norm);
      if (steps[pickIdx]) { steps[pickIdx].h5p_id = id; steps[pickIdx].quiz = null; setSteps(steps); renderStepCards(); }
      tb_remove();
    });
  }

  $(document).on('click', '.pbsg-detach-h5p', function (e) {
    e.preventDefault();
    const idx = parseInt($(this).data('idx'), 10); if (isNaN(idx)) return;
    const steps = getSteps().map(norm);
    if (steps[idx]) { steps[idx].h5p_id = 0; steps[idx].quiz = { type: 'multichoice', question: '', answers: [{ text: '', correct: true }, { text: '', correct: false }] }; setSteps(steps); renderStepCards(); }
  });

  // Issue 5: Edit H5P — fetch existing content and populate inline form
  $(document).on('click', '.pbsg-edit-h5p', function (e) {
    e.preventDefault();
    const idx = parseInt($(this).data('idx'), 10);
    const h5pId = parseInt($(this).data('h5p-id'), 10);
    if (isNaN(idx) || isNaN(h5pId)) return;

    const $card = $(`#pbsg-step-${idx}`);
    $card.find('.pbsg-linked-h5p').html('<p style="text-align:center; color:#646970;">Loading quiz data...</p>');

    $.post(PBSG_ADMIN.ajaxUrl, {
      action: 'pbsg_get_h5p_content',
      nonce: PBSG_ADMIN.nonce,
      h5p_id: h5pId
    }).done(function (res) {
      if (!res || !res.success) {
        alert(res && res.data ? res.data.message : 'Could not load H5P content.');
        renderStepCards();
        return;
      }
      const quiz = res.data.quiz;
      if (!quiz) { alert('Unsupported H5P content type for inline editing.'); renderStepCards(); return; }

      const steps = getSteps().map(norm);
      if (!steps[idx]) return;

      // Populate the inline form with existing content
      steps[idx].quiz = quiz;
      // Keep h5p_id so save_meta knows to UPDATE, not CREATE
      steps[idx]._editing_h5p = true;
      setSteps(steps);
      // Expand the card if collapsed
      collapsedSteps.delete(idx);
      renderStepCards();
    }).fail(function () {
      alert('Failed to fetch H5P content.');
      renderStepCards();
    });
  });

  // ═══════════════════════════════════════════════════════════
  //  Cover Image Picker
  // ═══════════════════════════════════════════════════════════
  $(document).on('click', '#pbsg_pick_cover_image', function (e) {
    e.preventDefault();
    const frame = wp.media({ title: 'Select Tutorial Cover Image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
    frame.on('select', function () { const a = frame.state().get('selection').first().toJSON(); $('#pbsg_cover_image_id').val(a.id || 0); $('#pbsg_cover_image_url').val(a.url || ''); $('#pbsg_cover_preview').attr('src', a.url || '').removeClass('pbsg-hidden'); });
    frame.open();
  });
  $(document).on('click', '#pbsg_clear_cover_image', function (e) { e.preventDefault(); $('#pbsg_cover_image_id').val(''); $('#pbsg_cover_image_url').val(''); $('#pbsg_cover_preview').attr('src', '').addClass('pbsg-hidden'); });

  // ═══════════════════════════════════════════════════════════
  //  Sync all forms before WP save
  // ═══════════════════════════════════════════════════════════
  // Issue 7d: Validate Fill in Blanks has at least one *blank* before save
  $(document).on('click', '#publish, #save-post', function (e) {
    const steps = getSteps().map(norm);
    let valid = true;

    steps.forEach((s, idx) => {
      syncQuiz(idx);
      syncResource(idx);

      // Validate Fill in Blanks has at least one *blank*
      if (s.quiz && s.quiz.type === 'blanks') {
        const sentence = s.quiz.sentence || '';
        const blanks = (sentence.match(/\*[^*]+\*/g) || []).length;
        if (sentence.trim().length > 0 && blanks === 0) {
          valid = false;
          // Expand the step card so user can see the error
          $(`#pbsg-step-${idx}`).removeClass('pbsg-step-card--collapsed');
          collapsedSteps.delete(idx);
          // Highlight the error
          $(`#pbsg-step-${idx} .pbsg-blanks-sentence`).css('border-color', '#8C2004');
          alert('Step ' + (idx + 1) + ': Fill in the Blanks requires at least one *blank* word wrapped in asterisks.');
        }
      }
    });

    if (!valid) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return false;
    }

    // Save is proceeding — clear the dirty flag so beforeunload doesn't fire
    markClean();
  });

  // ═══════════════════════════════════════════════════════════
  //  Track dirty state on intro fields and other non-step inputs
  // ═══════════════════════════════════════════════════════════
  $(document).on('input change', '#pbsg_intro_title, #pbsg_intro_subtitle, #pbsg_intro_description, .pbsg-objective-input, #pbsg_cover_image_id, #pbsg_left_ratio_slider, #pbsg_use_default_ratio, #pbsg_user_resizable', markDirty);

  // ═══════════════════════════════════════════════════════════
  //  Layout Settings Section (Stretch Goal 5)
  // ═══════════════════════════════════════════════════════════

  // Collapsible toggle (delegated — matches intro section pattern)
  $(document).on('click', '#pbsg-layout-toggle', function () {
    var $body = $('#pbsg-layout-body');
    var $chevron = $('#pbsg-layout-chevron');
    $body.toggle();
    $chevron.html($body.is(':visible') ? '&#x25BC;' : '&#x25B6;');
  });

  // Slider ↔ preview ↔ hidden field
  var $ratioSlider = $('#pbsg_left_ratio_slider');
  var $ratioValue  = $('#pbsg_ratio_value');
  var $ratioHidden = $('#pbsg_left_ratio');
  var $previewLeft = $('#pbsg_preview_left');
  var $previewLeftLabel  = $('#pbsg_preview_left_label');
  var $previewRightLabel = $('#pbsg_preview_right_label');
  var $useDefault  = $('#pbsg_use_default_ratio');
  var $controls    = $('#pbsg_ratio_controls');

  function updateRatioPreview() {
    var v = parseInt($ratioSlider.val(), 10);
    $ratioValue.text(v + '%');
    $previewLeft.css('width', v + '%');
    $previewLeftLabel.text(v);
    $previewRightLabel.text(100 - v);

    // Update hidden field: empty if using default, numeric otherwise
    if ($useDefault.is(':checked')) {
      $ratioHidden.val('');
    } else {
      $ratioHidden.val(v);
    }
    markDirty();
  }

  $ratioSlider.on('input', updateRatioPreview);

  $useDefault.on('change', function () {
    if ($(this).is(':checked')) {
      $controls.css({ opacity: 0.4, 'pointer-events': 'none' });
      $ratioHidden.val('');
    } else {
      $controls.css({ opacity: 1, 'pointer-events': '' });
      $ratioHidden.val(parseInt($ratioSlider.val(), 10));
    }
    markDirty();
  });

  // User-resizable checkbox also marks dirty
  $('#pbsg_user_resizable').on('change', markDirty);

  // ═══════════════════════════════════════════════════════════
  //  Benchmark Settings Section (Stretch Goal 5)
  // ═══════════════════════════════════════════════════════════

  // Collapsible toggle (delegated)
  $(document).on('click', '#pbsg-benchmark-toggle', function () {
    var $body = $('#pbsg-benchmark-body');
    var $chevron = $('#pbsg-benchmark-chevron');
    $body.toggle();
    $chevron.html($body.is(':visible') ? '&#x25BC;' : '&#x25B6;');
  });

  // Use-site-defaults checkbox toggles controls
  var $useSiteBench = $('#pbsg_use_site_benchmarks');
  var $benchControls = $('#pbsg_benchmark_controls');
  var $benchHidden   = $('#pbsg_benchmarks_json');

  $useSiteBench.on('change', function () {
    if ($(this).is(':checked')) {
      $benchControls.css({ opacity: 0.4, 'pointer-events': 'none' });
      $benchHidden.val('');  // empty = use site defaults
    } else {
      $benchControls.css({ opacity: 1, 'pointer-events': '' });
      syncBenchOverrides();
    }
    markDirty();
  });

  // Sync all benchmark override inputs → hidden JSON field
  function syncBenchOverrides() {
    var obj = {};
    var hasValue = false;
    $('.pbsg-bench-override').each(function () {
      var key = $(this).attr('data-key');
      var val = $(this).val();
      if (val !== '' && val !== undefined) {
        obj[key] = parseInt(val, 10);
        hasValue = true;
      }
    });
    $benchHidden.val(hasValue ? JSON.stringify(obj) : '');
  }

  $(document).on('input change', '.pbsg-bench-override', function () {
    if (!$useSiteBench.is(':checked')) {
      syncBenchOverrides();
    }
    markDirty();
  });

  // Track dirty state on benchmark inputs
  $(document).on('input change', '#pbsg_use_site_benchmarks, .pbsg-bench-override', markDirty);

  // ═══════════════════════════════════════════════════════════
  //  Branch Review Picker (Sprint 7 — sub-tutorials)
  // ═══════════════════════════════════════════════════════════
  let currentBranchRowIdx = null;

  function openBranchPicker(step) {
    step = norm(step);
    const html = `
      <div id="pbsg-branch-modal" style="padding:14px;">
        <h2 style="margin-top:0;">Set Wrong-Answer Branch Review</h2>
        <p style="margin:0 0 12px;">This sub-tutorial will appear only if the student answers this question incorrectly.</p>
        <div style="margin:12px 0;">
          <p><strong>Branch mode</strong></p>
          <label><input type="radio" name="pbsg_branch_mode" value="none" ${step.branch_mode === 'none' ? 'checked' : ''} /> None</label>
          <label><input type="radio" name="pbsg_branch_mode" value="optional" ${step.branch_mode === 'optional' ? 'checked' : ''} /> Optional</label>
          <label><input type="radio" name="pbsg_branch_mode" value="mandatory" ${step.branch_mode === 'mandatory' ? 'checked' : ''} /> Mandatory</label>
        </div>
        <div style="margin:10px 0;">
          <p style="margin:0 0 6px;"><strong>Branch title</strong></p>
          <input type="text" id="pbsg-branch-title" style="width:100%;" value="${esc(step.branch_title)}" placeholder="Need a quick review?" />
        </div>
        <div style="margin:10px 0;">
          <p style="margin:0 0 6px;"><strong>Instruction text</strong></p>
          <textarea id="pbsg-branch-intro" style="width:100%; min-height:90px;" placeholder="You answered this question incorrectly. Review this help content before continuing.">${esc(step.branch_intro)}</textarea>
          <div style="margin:10px 0;">
            <p><strong>Show branch after this many incorrect answers</strong></p>
            <input type="number" id="pbsg-branch-trigger-attempts" min="1" value="${step.branch_trigger_attempts || 1}" style="width:100px;" />
          </div>
        </div>
        <hr style="margin:16px 0;" />
        
        <div style="margin:10px 0; display:flex; gap:16px;">
          <label style="display:flex; gap:6px; align-items:center;">
            <input type="radio" name="pbsg_branch_tut_type" value="url" ${step.branch_tutorial_type !== 'tutorial' ? 'checked' : ''} />
            URL
          </label>
          <label style="display:flex; gap:6px; align-items:center;">
            <input type="radio" name="pbsg_branch_tut_type" value="tutorial" ${step.branch_tutorial_type === 'tutorial' ? 'checked' : ''} />
            Select Existing Tutorial
          </label>
        </div>

        <div id="pbsg-branch-url-block" style="margin-top:10px;">
          <p style="margin:0 0 6px;"><strong>Branch URL</strong></p>
          <input type="url" id="pbsg-branch-url" style="width:100%;" placeholder="https://example.com/review" value="${esc(step.branch_tutorial_url)}" />
        </div>

        <div id="pbsg-branch-tutorial-block" style="margin-top:10px; display:none;">
          <p style="margin:0 0 6px;"><strong>Branch tutorial</strong></p>
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          </div>
          <div id="pbsg-branch-tutorial-picker-wrap" style="margin-top:10px;">
          <select id="pbsg-branch-tutorial-select" style="width:100%;">
            <option value="">Loading tutorials...</option>
          </select>
        </div>

          <input type="hidden" id="pbsg-branch-tutorial-url" value="${esc(step.branch_tutorial_url || '')}" />
        </div>

        <div style="margin-top:14px; display:flex; gap:8px;">
          <button type="button" class="button button-primary" id="pbsg-branch-save">Save</button>
          <button type="button" class="button" id="pbsg-branch-cancel">Cancel</button>
        </div>
      </div>
    `;
    if (!$('#pbsg-branch-inline').length) {
      $('body').append('<div id="pbsg-branch-inline" style="display:none;"></div>');
    }
    $('#pbsg-branch-inline').html(html);
    tb_show('Set Branch Review', '#TB_inline?inlineId=pbsg-branch-inline&width=760&height=560');

    function refreshBranchBlocks() {
      const t = $('input[name="pbsg_branch_tut_type"]:checked').val();
      if (t === 'tutorial') {
        $('#pbsg-branch-url-block').hide();
        $('#pbsg-branch-tutorial-block').show();
      } else {
        $('#pbsg-branch-tutorial-block').hide();
        $('#pbsg-branch-url-block').show();
      }
    }

    refreshBranchBlocks();
    loadTutorialOptions(step.branch_tutorial_url || '');

    if (step.branch_tutorial_type === 'tutorial' && step.branch_tutorial_url) {
      $('#pbsg-branch-tutorial-url').val(step.branch_tutorial_url);
    }

    $(document).off('change.pbsgBranchType').on('change.pbsgBranchType', 'input[name="pbsg_branch_tut_type"]', refreshBranchBlocks);

    $('#pbsg-branch-cancel').on('click', function () { tb_remove(); });

   function loadTutorialOptions(selectedUrl = '') {
      const $select = $('#pbsg-branch-tutorial-select');
      $select.html('<option value="">Loading tutorials...</option>');

      $.post(PBSG_ADMIN.ajaxUrl, {
        action: 'pbsg_list_tutorials',
        nonce: PBSG_ADMIN.tutorialNonce
      }).done(function (resp) {
        if (!resp || !resp.success || !Array.isArray(resp.data)) {
          $select.html('<option value="">No tutorials found</option>');
          return;
        }

        let options = '<option value="">Select a tutorial...</option>';
        resp.data.forEach(function (item) {
          const selected = selectedUrl && selectedUrl === item.url ? 'selected' : '';
          options += `<option value="${esc(item.url)}" ${selected}>${esc(item.title)} (${esc(item.status)})</option>`;
        });
        $select.html(options);

      }).fail(function () {
        $select.html('<option value="">Failed to load tutorials</option>');
      });
    }

    $('#pbsg-branch-tutorial-select').on('change', function () {
      const $opt = $('#pbsg-branch-tutorial-select option:selected');
      const url = $opt.val() || '';
      const label = $opt.text() || 'No tutorial selected';

      $('#pbsg-branch-tutorial-url').val(url);
    });

    $('#pbsg-branch-save').on('click', function () {
      if (currentBranchRowIdx === null) return;
      const steps = getSteps().map(norm);
      const step = steps[currentBranchRowIdx];
      if (!step) return;

      step.branch_mode = $('input[name="pbsg_branch_mode"]:checked').val() || 'none';
      step.branch_trigger_attempts = parseInt($('#pbsg-branch-trigger-attempts').val(), 10) || 1;
      if (step.branch_trigger_attempts < 1) step.branch_trigger_attempts = 1;
      step.branch_title = $('#pbsg-branch-title').val() || '';
      step.branch_intro = $('#pbsg-branch-intro').val() || '';

      const t = $('input[name="pbsg_branch_tut_type"]:checked').val();

      if (t === 'tutorial') {
        const url = $('#pbsg-branch-tutorial-url').val() || '';
        step.branch_tutorial_type = url ? 'tutorial' : '';
        step.branch_tutorial_url = url;
        step.branch_tutorial_attachment_id = 0;
        step.branch_tutorial_file_name = '';
        step.branch_tutorial_file_url = '';
      } else {
        const url = $('#pbsg-branch-url').val() || '';
        step.branch_tutorial_type = url ? 'url' : '';
        step.branch_tutorial_url = url;
        step.branch_tutorial_attachment_id = 0;
        step.branch_tutorial_file_name = '';
        step.branch_tutorial_file_url = '';
      }

      steps[currentBranchRowIdx] = step;
      setSteps(steps);
      renderStepCards();
      tb_remove();
    });
  }

  $(document).on('click', '.pbsg-set-branch', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;
    currentBranchRowIdx = idx;
    const steps = getSteps().map(norm);
    openBranchPicker(steps[idx] || {});
  });

  $(document).on('click', '.pbsg-clear-branch', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;
    const steps = getSteps().map(norm);
    if (!steps[idx]) return;
    steps[idx].branch_mode = 'none';
    steps[idx].branch_trigger_attempts = 1;
    steps[idx].branch_title = '';
    steps[idx].branch_intro = '';
    steps[idx].branch_tutorial_type = '';
    steps[idx].branch_tutorial_url = '';
    steps[idx].branch_tutorial_attachment_id = 0;
    steps[idx].branch_tutorial_file_name = '';
    steps[idx].branch_tutorial_file_url = '';
    setSteps(steps);
    renderStepCards();
  });

  // ═══════════════════════════════════════════════════════════
  //  Save as Template (Sprint 7 SG3)
  // ═══════════════════════════════════════════════════════════
  $(document).on('click', '#pbsg-save-as-template', function () {
    const postId = $('#post_ID').val();
    if (!postId || postId === '0') {
      alert('Please save or publish the tutorial first before saving it as a template.');
      return;
    }
    const html = `
      <div id="pbsg-save-tpl-modal" style="padding:18px;">
        <h2 style="margin-top:0;">Save as Template</h2>
        <p style="color:#50575e; margin-bottom:14px;">The current steps will be saved as a reusable template for new tutorials.</p>
        <p style="margin:0 0 6px;"><strong>Template name <span style="color:#d63638;">*</span></strong></p>
        <input type="text" id="pbsg-tpl-name" style="width:100%;" placeholder="e.g. Library Catalogue Search" />
        <p style="margin:12px 0 6px;"><strong>Description</strong></p>
        <textarea id="pbsg-tpl-desc" style="width:100%; min-height:70px;" placeholder="Optional \u2014 describe when to use this template"></textarea>
        <p style="margin:12px 0 6px;"><strong>Category</strong></p>
        <input type="text" id="pbsg-tpl-cat" style="width:100%;" placeholder="e.g. General, Research, Databases" />
        <p id="pbsg-tpl-save-error" style="color:#d63638; margin:8px 0 0; display:none;"></p>
        <div style="margin-top:16px; display:flex; gap:8px;">
          <button type="button" class="button button-primary" id="pbsg-tpl-save-btn">Save Template</button>
          <button type="button" class="button" id="pbsg-tpl-cancel-btn">Cancel</button>
        </div>
      </div>
    `;
    if (!$('#pbsg-save-tpl-inline').length) {
      $('body').append('<div id="pbsg-save-tpl-inline" style="display:none;"></div>');
    }
    $('#pbsg-save-tpl-inline').html(html);
    tb_show('Save as Template', '#TB_inline?inlineId=pbsg-save-tpl-inline&width=560&height=380');

    $('#pbsg-tpl-cancel-btn').on('click', () => tb_remove());
    $('#pbsg-tpl-save-btn').on('click', function () {
      const name = $.trim($('#pbsg-tpl-name').val());
      const $err = $('#pbsg-tpl-save-error');
      $err.hide();
      if (!name) { $err.text('Please enter a template name.').show(); $('#pbsg-tpl-name').trigger('focus'); return; }
      const $btn = $(this).prop('disabled', true).text('Saving\u2026');
      $.post(PBSG_ADMIN.ajaxUrl, {
        action:      'pbsg_save_as_template',
        nonce:       PBSG_ADMIN.templateNonce,
        post_id:     postId,
        name:        name,
        description: $('#pbsg-tpl-desc').val(),
        category:    $('#pbsg-tpl-cat').val(),
        steps_json:  $('#pbsg_steps_json').val(),
        header_note: $('#pbsg_header_note').val() || '',
      })
      .done(function (res) {
        if (res && res.success) {
          tb_remove();
          const $btn2 = $('#pbsg-save-as-template');
          const orig  = $btn2.text();
          $btn2.text('Saved!').prop('disabled', true);
          setTimeout(() => $btn2.text(orig).prop('disabled', false), 2000);
        } else {
          const msg = res?.data?.message || 'Could not save template.';
          $err.text(msg).show();
          $btn.prop('disabled', false).text('Save Template');
        }
      })
      .fail(function () {
        $err.text('Request failed. Please try again.').show();
        $btn.prop('disabled', false).text('Save Template');
      });
    });
  });

  // ═══════════════════════════════════════════════════════════
  //  Init
  // ═══════════════════════════════════════════════════════════
  renderStepCards();

  // Snapshot initial state AFTER rendering so we have a clean baseline
  // (use setTimeout to let WP finish any post-load mutations)
  setTimeout(markClean, 300);
});
