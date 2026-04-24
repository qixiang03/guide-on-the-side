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
   * Shortcut: render a Marginalia SVG icon by name.
   * Falls back to empty string if PBSG_ICONS is not loaded.
   */
  const icon = (name, extra) =>
    (typeof PBSG_ICONS !== 'undefined') ? PBSG_ICONS.render(name, extra) : '';

  /**
   * Return an appropriate SVG icon for a filename based on its extension.
   */
  function filePreviewIcon(filename) {
    const ext = String(filename || '').split('.').pop().toLowerCase();
    const map = {
      pdf:  'document-multi',
      doc:  'document',   docx: 'document',
      xls:  'chart-bar',  xlsx: 'chart-bar',  csv:  'chart-bar',
      ppt:  'disk',       pptx: 'disk',
      jpg:  'image',      jpeg: 'image',      png:  'image',
      gif:  'image',      webp: 'image',      svg:  'image',
      mp4:  'video',      mov:  'video',      avi:  'video',
      webm: 'video',      mkv:  'video',
      mp3:  'audio',      wav:  'audio',      ogg:  'audio',
      zip:  'package',    rar:  'package',    gz:   'package',
    };
    return icon(map[ext] || 'document-page');
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

  // ═══════════════════════════════════════════════════════════
  //  TinyMCE Helpers (WYSIWYG editors for instructions + quiz questions)
  // ═══════════════════════════════════════════════════════════
  const PBSG_TINYMCE_TOOLBAR = 'bold italic underline | bullist numlist | link | fontselect fontsizeselect forecolor';

  function pbsgInitTinyMCEDirect(editorId) {
    // Remove existing instance if any (re-init after reorder)
    var existing = tinymce.get(editorId);
    if (existing) existing.remove();

    var defaults = window.pbsgStyleDefaults || {};
    tinymce.init({
      selector: '#' + editorId,
      menubar: false,
      statusbar: false,
      toolbar: PBSG_TINYMCE_TOOLBAR,
      plugins: 'lists link textcolor',
      content_style: 'body { font-family: ' + (defaults.font_family || 'Roboto, sans-serif') + '; font-size: ' + (defaults.font_size || '16px') + '; color: ' + (defaults.text_color || '#333333') + '; }',
      font_formats: 'Roboto=Roboto, sans-serif;Lusitana=Lusitana, serif;Georgia=Georgia, serif;Arial=Arial, sans-serif;System=system-ui, sans-serif',
      fontsize_formats: '14px 16px 18px 20px',
      branding: false,
      height: 120,
      setup: function(editor) {
        editor.on('change keyup', function() {
          editor.save(); // sync content to the textarea
        });
      }
    });
  }

  // TinyMCE may load after jQuery ready (footer scripts). Retry until available.
  function pbsgInitTinyMCE(editorId) {
    if (typeof tinymce !== 'undefined') {
      pbsgInitTinyMCEDirect(editorId);
      return;
    }
    var tries = 0;
    var timer = setInterval(function() {
      tries++;
      if (typeof tinymce !== 'undefined') {
        clearInterval(timer);
        pbsgInitTinyMCEDirect(editorId);
      } else if (tries > 40) {
        clearInterval(timer);
      }
    }, 200);
  }

  function pbsgRemoveTinyMCE(editorId) {
    if (typeof tinymce === 'undefined') return;
    var editor = tinymce.get(editorId);
    if (editor) editor.remove();
  }

  function pbsgGetTinyMCEContent(editorId) {
    if (typeof tinymce !== 'undefined') {
      const editor = tinymce.get(editorId);
      if (editor) return editor.getContent();
    }
    const el = document.getElementById(editorId);
    return el ? el.value : '';
  }

  function getSteps() {
    try { const v = JSON.parse($('#pbsg_steps_json').val() || ''); return Array.isArray(v) ? v : []; }
    catch (e) { return []; }
  }

  function setSteps(steps) {
    try {
      $('#pbsg_steps_json').val(JSON.stringify(steps || []));
    } catch (e) {
      console.error('[PBSG] Failed to serialize steps:', e);
      return;
    }
    markDirty();
  }

  // ═══════════════════════════════════════════════════════════
  //  Unsaved-changes detection
  // ═══════════════════════════════════════════════════════════
  let _savedSnapshot = '';   // JSON string of last-saved state
  let _isDirty = false;

  /** @type {Object<number, {title: string, user_id: number, author_name: string, is_owner: boolean, usage_count: number}>} */
  let h5pMetaCache = {};
  let titleSaveTimer = null;

  /** Take a snapshot of the current form state (steps JSON + intro fields). */
  function currentSnapshot() {
    const parts = [
      $('#pbsg_steps_json').val() || '',
      $('#pbsg_intro_title').val() || '',
      $('#pbsg_intro_subtitle').val() || '',
      pbsgGetTinyMCEContent('pbsg_intro_description') || $('#pbsg_intro_description').val() || '',
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

  function pbsgAdminI18n() {
    return (typeof PBSG_ADMIN !== 'undefined' && PBSG_ADMIN.strings) ? PBSG_ADMIN.strings : {};
  }

  /** Shorten user-entered text for native confirm() dialogs. */
  function truncateForConfirm(str, maxLen) {
    const s = String(str || '');
    const n = maxLen || 80;
    if (s.length <= n) return s;
    return s.slice(0, n - 1) + '\u2026';
  }

  // Browser navigation guard
  $(window).on('beforeunload', function () {
    if (!_isDirty) return;
    const str = pbsgAdminI18n();
    const title = (typeof PBSG_ADMIN !== 'undefined' && PBSG_ADMIN.postTitle)
      ? String(PBSG_ADMIN.postTitle).trim()
      : '';
    if (title && str.leaveWithTitle) {
      return str.leaveWithTitle.replace('%s', title);
    }
    return str.leaveGeneric || 'You have unsaved changes to this tutorial. Leave without saving?';
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
    o.h5p_cleared = !!o.h5p_cleared;
    o.title = o.title || '';
    o.instructions_html = o.instructions_html || '';
    // Branch / sub-tutorial defaults
       o.branch = (o.branch && typeof o.branch === 'object') ? o.branch : null;

    if (o.branch) {
      o.branch.mode = (o.branch.mode === 'mandatory') ? 'mandatory' : 'optional';

      o.branch.resource_mode = o.branch.resource_mode || 'main';

      o.branch.trigger_attempts = 1;

      o.branch.questions = Array.isArray(o.branch.questions) ? o.branch.questions : [];

      o.branch.questions = o.branch.questions.map(function (q) {
      q = q || {};
      const type = q.type || 'multichoice';

      const baseResource = {
        tutorial_type: q.tutorial_type || '',
        tutorial_url: q.tutorial_url || '',
        tutorial_attachment_id: q.tutorial_attachment_id || 0,
        tutorial_file_name: q.tutorial_file_name || '',
        tutorial_file_url: q.tutorial_file_url || ''
      };

      if (type === 'blanks') {
        return Object.assign({
          type: 'blanks',
          sentence: q.sentence || '',
          case_sensitive: !!q.case_sensitive,
          accept_typos: !!q.accept_typos
        }, baseResource);
      }

      if (type === 'singlechoice') {
        return Object.assign({
          type: 'singlechoice',
          question: q.question || '',
          correct_answer: q.correct_answer || '',
          wrong_answers: Array.isArray(q.wrong_answers) ? q.wrong_answers : ['']
        }, baseResource);
      }

      return Object.assign({
        type: 'multichoice',
        question: q.question || '',
        answers: Array.isArray(q.answers) ? q.answers : [
          { text: '', correct: true },
          { text: '', correct: false }
        ]
      }, baseResource);
    });

      o.branch.tutorial_type = o.branch.tutorial_type || '';
      o.branch.tutorial_url = o.branch.tutorial_url || '';
      o.branch.tutorial_attachment_id = o.branch.tutorial_attachment_id || 0;
      o.branch.tutorial_file_name = o.branch.tutorial_file_name || '';
      o.branch.tutorial_file_url = o.branch.tutorial_file_url || '';
    }
    return o;
  }

function branchSummary(s) {
  s = norm(s);
  if (!s.branch) return '';
  const mode = s.branch.mode === 'mandatory' ? 'Mandatory' : 'Optional';
  const qCount = Array.isArray(s.branch.questions) ? s.branch.questions.length : 0;
  return mode + ' \u00B7 ' + qCount + ' sub-question' + (qCount === 1 ? '' : 's');
}

    function makeEmptyBranch() {
    return {
      mode: 'optional',
      trigger_attempts: 1,
      resource_mode: 'main', // main | shared | per_question
      questions: [],
      tutorial_type: '',
      tutorial_url: '',
      tutorial_attachment_id: 0,
      tutorial_file_name: '',
      tutorial_file_url: ''
    };
  }

  function makeDefaultBranchQuestion(type) {
    if (type === 'blanks') {
      return {
        type: 'blanks',
        sentence: '',
        case_sensitive: false,
        accept_typos: false,
        tutorial_type: '',
        tutorial_url: '',
        tutorial_attachment_id: 0,
        tutorial_file_name: '',
        tutorial_file_url: ''
      };
    }

    if (type === 'singlechoice') {
      return {
        type: 'singlechoice',
        question: '',
        correct_answer: '',
        wrong_answers: [''],
        tutorial_type: '',
        tutorial_url: '',
        tutorial_attachment_id: 0,
        tutorial_file_name: '',
        tutorial_file_url: ''
      };
    }

    return {
      type: 'multichoice',
      question: '',
      answers: [
        { text: '', correct: true },
        { text: '', correct: false }
      ],
      tutorial_type: '',
      tutorial_url: '',
      tutorial_attachment_id: 0,
      tutorial_file_name: '',
      tutorial_file_url: ''
    };
  }

  function renderBranchQuestionEditor(q, stepIdx, qIdx) {
    q = q || {};
    const type = q.type || 'multichoice';

    const step = getSteps().map(norm)[stepIdx] || {};
    const branch = step.branch || {};
    const showPerQuestionResource = branch.resource_mode === 'per_question';

    let body = '';

    if (type === 'blanks') {
      const sentence = q.sentence || '';
      const caseSens = q.case_sensitive !== undefined ? q.case_sensitive : false;
      const typos = q.accept_typos !== undefined ? q.accept_typos : false;
      const preview = blanksPreview(sentence);
      const cnt = (sentence.match(/\*[^*]+\*/g) || []).length;
      const caseRiskVisible = caseSens && cnt > 0 && blanksCaseSensitiveRiskyAnswers(sentence);

      body = `
        <div class="pbsg-field">
          <label class="pbsg-field-label">Sentence</label>
          <textarea class="pbsg-branch-blanks-sentence" data-step-idx="${stepIdx}" data-qidx="${qIdx}" rows="3" placeholder="Wrap answer words with *asterisks*...">${esc(sentence)}</textarea>
          <div class="pbsg-field-hint">
            Wrap answer words with <code>*asterisks*</code> &mdash; they become blank fields.<br>
            Use <code>/</code> for alternatives: <code>*colour/color*</code><br>
            Use <code>:</code> to add a hint: <code>*Norway:Scandinavian country*</code>
          </div>
        </div>
        <div class="pbsg-field">
          <label class="pbsg-field-label">Preview</label>
          <div class="pbsg-blanks-preview pbsg-branch-blanks-preview" data-step-idx="${stepIdx}" data-qidx="${qIdx}">${preview}</div>
          <div class="pbsg-branch-blanks-validation pbsg-validation-msg ${cnt > 0 ? 'pbsg-validation-msg--ok' : 'pbsg-validation-msg--error'}">
            ${cnt > 0
              ? '&#x2713; ' + cnt + ' blank' + (cnt !== 1 ? 's' : '') + ' detected'
              : '&#x26A0; No blanks detected &mdash; wrap words with *asterisks*'}
          </div>
        </div>
        <div class="pbsg-field">
          <label class="pbsg-field-label">Answer Validation</label>
          <div class="pbsg-blanks-options">
            <label class="pbsg-toggle-option${caseSens ? ' pbsg-toggle-option--active' : ''}">
              <input type="checkbox" class="pbsg-branch-blanks-case" data-step-idx="${stepIdx}" data-qidx="${qIdx}" ${caseSens ? 'checked' : ''} />
              <div>
                <div class="pbsg-toggle-title">Case sensitive</div>
                <div class="pbsg-toggle-desc">&ldquo;Norway&rdquo; &#x2260; &ldquo;norway&rdquo; &mdash; students must match exact capitalization</div>
              </div>
            </label>
            <label class="pbsg-toggle-option${typos ? ' pbsg-toggle-option--active' : ''}">
              <input type="checkbox" class="pbsg-branch-blanks-typos" data-step-idx="${stepIdx}" data-qidx="${qIdx}" ${typos ? 'checked' : ''} />
              <div>
                <div class="pbsg-toggle-title">Accept minor spelling errors</div>
                <div class="pbsg-toggle-desc">Words 3&ndash;9 chars: 1 typo allowed &middot; 10+ chars: 2 typos allowed</div>
              </div>
            </label>
          </div>
          <div class="pbsg-branch-blanks-case-risk-msg" data-step-idx="${stepIdx}" data-qidx="${qIdx}" style="display:${caseRiskVisible ? 'block' : 'none'}; margin-top:10px; padding:8px; background:#fcf3f3; border:1px solid #e8b4b4; border-radius:4px; font-size:12px; color:#5c1a1a;">
            <strong>Case sensitivity:</strong> At least one wrapped answer mixes uppercase and lowercase. With Case sensitive enabled, grading requires an exact character match &mdash; students may be marked wrong if capitalization differs.
          </div>
        </div>
      `;
    } else if (type === 'singlechoice') {
      const wrongs = Array.isArray(q.wrong_answers) ? q.wrong_answers : [''];

      let wrongRows = '';
      wrongs.forEach(function (w, wi) {
        wrongRows += `
          <div class="pbsg-answer-row pbsg-answer-row--incorrect">
            <input type="text" class="pbsg-branch-sc-wrong-input" data-step-idx="${stepIdx}" data-qidx="${qIdx}" data-widx="${wi}" value="${esc(w)}" placeholder="Wrong answer..." />
            <button type="button" class="pbsg-branch-sc-wrong-remove" data-step-idx="${stepIdx}" data-qidx="${qIdx}" data-widx="${wi}" title="Remove">&times;</button>
          </div>
        `;
      });

      body = `
        <div class="pbsg-field">
          <label class="pbsg-field-label">Question</label>
          <input type="text" class="pbsg-branch-sc-question" data-step-idx="${stepIdx}" data-qidx="${qIdx}" value="${esc(q.question || '')}" placeholder="Enter your question..." />
        </div>
        <div class="pbsg-field">
          <label class="pbsg-field-label">Correct Answer</label>
          <input type="text" class="pbsg-branch-sc-correct" data-step-idx="${stepIdx}" data-qidx="${qIdx}" value="${esc(q.correct_answer || '')}" placeholder="Correct answer..." />
        </div>
        <div class="pbsg-field">
          <label class="pbsg-field-label">Wrong Answers</label>
          <div class="pbsg-branch-sc-wrong-list">${wrongRows}</div>
          <button type="button" class="button pbsg-branch-add-sc-wrong" data-step-idx="${stepIdx}" data-qidx="${qIdx}">+ Add Wrong Answer</button>
        </div>
      `;
    } else {
      const answers = Array.isArray(q.answers) ? q.answers : [
        { text: '', correct: true },
        { text: '', correct: false }
      ];

      let answerRows = '';
      answers.forEach(function (a, ai) {
        answerRows += `
          <div class="pbsg-answer-row">
            <input type="checkbox" class="pbsg-branch-mc-correct" data-step-idx="${stepIdx}" data-qidx="${qIdx}" data-aidx="${ai}" ${a.correct ? 'checked' : ''} />
            <input type="text" class="pbsg-branch-mc-answer" data-step-idx="${stepIdx}" data-qidx="${qIdx}" data-aidx="${ai}" value="${esc(a.text || '')}" placeholder="Answer option..." />
            <button type="button" class="pbsg-branch-mc-remove-answer" data-step-idx="${stepIdx}" data-qidx="${qIdx}" data-aidx="${ai}" title="Remove">&times;</button>
          </div>
        `;
      });

      body = `
        <div class="pbsg-field">
          <label class="pbsg-field-label">Question</label>
          <input type="text" class="pbsg-branch-mc-question" data-step-idx="${stepIdx}" data-qidx="${qIdx}" value="${esc(q.question || '')}" placeholder="Enter your question..." />
        </div>
        <div class="pbsg-field">
          <label class="pbsg-field-label">Answers</label>
          <div class="pbsg-branch-mc-answers">${answerRows}</div>
          <button type="button" class="button pbsg-branch-add-mc-answer" data-step-idx="${stepIdx}" data-qidx="${qIdx}">+ Add Answer</button>
        </div>
      `;
    }

    return `
      <div class="pbsg-branch-question-card" data-step-idx="${stepIdx}" data-qidx="${qIdx}">
        <div class="pbsg-branch-question-head">
          <strong class="pbsg-branch-question-title">Sub Question ${qIdx + 1}</strong>
          <span class="pbsg-badge pbsg-badge--info">${esc(quizName(type))}</span>
          <button type="button" class="button pbsg-remove-branch-question" data-step-idx="${stepIdx}" data-qidx="${qIdx}">Remove</button>
        </div>
        ${body}

        ${
          showPerQuestionResource
            ? `
              <div class="pbsg-branch-question-resource">
                <div class="pbsg-field-label" style="margin-top:12px;">Tutorial Resource for This Question</div>
                ${renderBranchQuestionResourcePanel(q, stepIdx, qIdx)}
              </div>
            `
            : ''
        }
      </div>
    `;
  }

  function renderBranchQuestions(questions, stepIdx) {
    questions = Array.isArray(questions) ? questions : [];
    if (!questions.length) {
      return `<div class="pbsg-empty-state" style="padding:10px 0; color:#666;">No sub-quiz questions yet.</div>`;
    }

    let html = '';
    questions.forEach(function (q, qIdx) {
      html += renderBranchQuestionEditor(q, stepIdx, qIdx);
    });
    return html;
  }

  function renderBranchResourcePanel(branch, stepIdx) {
    branch = branch || makeEmptyBranch();
    const isFile = branch.tutorial_type === 'file';

    let content = '';
    if (!isFile) {
      const isYT = /youtube\.com|youtu\.be/i.test(branch.tutorial_url || '');
      content = `
        <div class="pbsg-field">
          <label class="pbsg-field-label">URL</label>
          <input type="url" class="pbsg-branch-resource-url" data-step-idx="${stepIdx}" value="${esc(branch.tutorial_url || '')}" placeholder="https://..." />
          <div class="pbsg-field-hint">This shared tutorial resource applies to all sub-quiz questions.</div>
        </div>
        ${(branch.tutorial_url || '') ? `<div class="pbsg-resource-preview">
          <span class="pbsg-preview-icon">${isYT ? icon('play') : icon('globe')}</span>
          ${isYT ? 'YouTube video will be embedded in the right pane' : 'Page will load in the right pane iframe'}
        </div>` : ''}
      `;
    } else {
      const fn = branch.tutorial_file_name || (branch.tutorial_attachment_id ? 'Attachment #' + branch.tutorial_attachment_id : '');
      content = fn
        ? `<div class="pbsg-file-info">
            <span class="pbsg-file-icon">${icon('document-page')}</span>
            <div>
              <div class="pbsg-file-name">${esc(fn)}</div>
              <div class="pbsg-file-meta">Uploaded</div>
            </div>
            <button type="button" class="pbsg-btn-ghost pbsg-clear-branch-resource" data-step-idx="${stepIdx}" title="Remove file">&times;</button>
          </div>
          <div class="pbsg-resource-preview">
            <span class="pbsg-preview-icon">${filePreviewIcon(fn)}</span>${filePreviewLabel(fn)}
          </div>`
        : `<div class="pbsg-branch-upload-zone" data-step-idx="${stepIdx}">
            <span class="pbsg-upload-icon">${icon('arrow-up')}</span>
            <div>Drag &amp; drop a file here, or click to browse</div>
            ${PBSG_ADMIN.maxUploadLabel ? `<div class="pbsg-upload-size-hint">Max file size: ${esc(PBSG_ADMIN.maxUploadLabel)}</div>` : ''}
          </div>`;
    }

    return `
      <div class="pbsg-branch-resource-type-toggle" data-step-idx="${stepIdx}">
        <label class="${!isFile ? 'active' : ''}">
          <input type="radio" name="pbsg_branch_res_type_${stepIdx}" value="url" ${!isFile ? 'checked' : ''} />
          <span>${icon('link')} URL</span>
        </label>
        <label class="${isFile ? 'active' : ''}">
          <input type="radio" name="pbsg_branch_res_type_${stepIdx}" value="file" ${isFile ? 'checked' : ''} />
          <span>${icon('document-page')} Upload File</span>
        </label>
      </div>
      ${content}
    `;
  }


  function renderBranchQuestionResourcePanel(q, stepIdx, qIdx) {
  q = q || {};
  const isFile = q.tutorial_type === 'file';

  let content = '';
  if (!isFile) {
    const isYT = /youtube\.com|youtu\.be/i.test(q.tutorial_url || '');
    content = `
      <div class="pbsg-field">
        <label class="pbsg-field-label">URL</label>
        <input type="url" class="pbsg-branch-q-resource-url" data-step-idx="${stepIdx}" data-qidx="${qIdx}" value="${esc(q.tutorial_url || '')}" placeholder="https://..." />
        <div class="pbsg-field-hint">This resource applies only to this sub-question.</div>
      </div>
      ${(q.tutorial_url || '') ? `<div class="pbsg-resource-preview">
        <span class="pbsg-preview-icon">${isYT ? icon('play') : icon('globe')}</span>
        ${isYT ? 'YouTube video will be embedded in the right pane' : 'Page will load in the right pane iframe'}
      </div>` : ''}
    `;
  } else {
    const fn = q.tutorial_file_name || (q.tutorial_attachment_id ? 'Attachment #' + q.tutorial_attachment_id : '');
    content = fn
      ? `<div class="pbsg-file-info">
          <span class="pbsg-file-icon">${icon('document-page')}</span>
          <div>
            <div class="pbsg-file-name">${esc(fn)}</div>
            <div class="pbsg-file-meta">Uploaded</div>
          </div>
          <button type="button" class="pbsg-btn-ghost pbsg-clear-branch-q-resource" data-step-idx="${stepIdx}" data-qidx="${qIdx}" title="Remove file">&times;</button>
        </div>
        <div class="pbsg-resource-preview">
          <span class="pbsg-preview-icon">${filePreviewIcon(fn)}</span>${filePreviewLabel(fn)}
        </div>`
      : `<div class="pbsg-branch-q-upload-zone" data-step-idx="${stepIdx}" data-qidx="${qIdx}">
          <span class="pbsg-upload-icon">${icon('arrow-up')}</span>
          <div>Drag &amp; drop a file here, or click to browse</div>
          ${PBSG_ADMIN.maxUploadLabel ? `<div class="pbsg-upload-size-hint">Max file size: ${esc(PBSG_ADMIN.maxUploadLabel)}</div>` : ''}
        </div>`;
  }

  return `
    <div class="pbsg-branch-q-resource-type-toggle" data-step-idx="${stepIdx}" data-qidx="${qIdx}">
      <label class="${!isFile ? 'active' : ''}">
        <input type="radio" name="pbsg_branch_q_res_type_${stepIdx}_${qIdx}" value="url" ${!isFile ? 'checked' : ''} />
        <span>${icon('link')} URL</span>
      </label>
      <label class="${isFile ? 'active' : ''}">
        <input type="radio" name="pbsg_branch_q_res_type_${stepIdx}_${qIdx}" value="file" ${isFile ? 'checked' : ''} />
        <span>${icon('document-page')} Upload File</span>
      </label>
    </div>
    ${content}
  `;
}

  function renderBranchEditor(step, idx) {
    step = norm(step);
    const branch = step.branch || makeEmptyBranch();

   return `
    <div class="pbsg-branch-editor">
      <div class="pbsg-branch-toolbar">

        <div class="pbsg-branch-inline-row">
          <div class="pbsg-branch-inline-label">Branch Mode</div>

          <label class="pbsg-branch-inline-option">
            <input type="radio" class="pbsg-branch-mode" name="pbsg_branch_mode_${idx}" data-step-idx="${idx}" value="optional" ${branch.mode !== 'mandatory' ? 'checked' : ''} />
            <span>Optional</span>
          </label>

          <label class="pbsg-branch-inline-option">
            <input type="radio" class="pbsg-branch-mode" name="pbsg_branch_mode_${idx}" data-step-idx="${idx}" value="mandatory" ${branch.mode === 'mandatory' ? 'checked' : ''} />
            <span>Mandatory</span>
          </label>
        </div>

        <div class="pbsg-branch-inline-row">
          <div class="pbsg-branch-inline-label">Tutorial Resource Mode</div>

          <label class="pbsg-branch-inline-option">
            <input type="radio" class="pbsg-branch-resource-mode" name="pbsg_branch_resource_mode_${idx}" data-step-idx="${idx}" value="main" ${branch.resource_mode === 'main' ? 'checked' : ''} />
            <span>Use Main Tutorial Resource</span>
          </label>

          <label class="pbsg-branch-inline-option">
            <input type="radio" class="pbsg-branch-resource-mode" name="pbsg_branch_resource_mode_${idx}" data-step-idx="${idx}" value="shared" ${branch.resource_mode === 'shared' ? 'checked' : ''} />
            <span>Use One Shared Resource</span>
          </label>

          <label class="pbsg-branch-inline-option">
            <input type="radio" class="pbsg-branch-resource-mode" name="pbsg_branch_resource_mode_${idx}" data-step-idx="${idx}" value="per_question" ${branch.resource_mode === 'per_question' ? 'checked' : ''} />
            <span>Use a Separate Resource</span>
          </label>
        </div>

      </div>

      ${
          branch.resource_mode === 'main'
            ? `<div class="pbsg-field-hint">All branch sub-questions will use the main tutorial resource from this step.</div>`
            : ''
        }
        <div class="pbsg-branch-section">
          <div class="pbsg-branch-section-title">Sub Quiz Questions</div>
          <div class="pbsg-branch-questions-wrap" data-step-idx="${idx}">
            ${renderBranchQuestions(branch.questions, idx)}
          </div>
          <div class="pbsg-branch-actions">
            <button type="button" class="button pbsg-btn-outline pbsg-add-branch-question" data-step-idx="${idx}" data-type="multichoice">Add Multiple Selection</button>
            <button type="button" class="button pbsg-btn-outline pbsg-add-branch-question" data-step-idx="${idx}" data-type="blanks">Add Fill in Blank</button>
            <button type="button" class="button pbsg-btn-outline pbsg-add-branch-question" data-step-idx="${idx}" data-type="singlechoice">Add Single Selection</button>
          </div>
        </div>

        ${
          branch.resource_mode === 'shared'
            ? `
              <div class="pbsg-branch-section">
                <div class="pbsg-branch-section-title">Shared Tutorial Resource</div>
                ${renderBranchResourcePanel(branch, idx)}
              </div>
            `
            : ''
        }
    `;
  }

  // ═══════════════════════════════════════════════════════════
  //  Template Watcher (unchanged logic)
  // ═══════════════════════════════════════════════════════════
  function isSplitGuide() {
    const $t = $('#page_template');
    // If the template dropdown exists, use it; otherwise fall back to server-side value
    // (dropdown may be absent when Tutorial Attributes metabox is hidden)
    if ($t.length > 0) return $t.val() === PBSG_ADMIN.templateSlug;
    return PBSG_ADMIN.currentTemplate === PBSG_ADMIN.templateSlug;
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
    $chev.html(icon($body.is(':visible') ? 'chevron-down' : 'chevron-right'));
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
        <span class="pbsg-objective-check">${icon('check', 'pbsg-icon--ok')}</span>
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
  function quizName(t) { return { multichoice: 'Multiple Selection', blanks: 'Fill in Blanks', singlechoice: 'Single Selection' }[t] || ''; }

  function renderStepCards(skipSync) {
    const $c = $('#pbsg-steps-container');
    if (!$c.length) return;

    // Flush TinyMCE content into steps data before destroying editors
    // skipSync: caller already synced and mutated the JSON — trust it as-is
    if (!skipSync) {
      $c.find('.pbsg-step-card').each(function () {
        const oldIdx = parseInt($(this).data('idx'), 10);
        if (!isNaN(oldIdx)) {
          syncInstructions(oldIdx);
          syncQuiz(oldIdx);
        }
      });
    }

    const steps = getSteps().map(norm);

    // Destroy existing TinyMCE instances before wiping DOM
    $c.find('.pbsg-step-card').each(function () {
      const oldIdx = parseInt($(this).data('idx'), 10);
      if (!isNaN(oldIdx)) {
        pbsgRemoveTinyMCE('pbsg_instructions_' + oldIdx);
        pbsgRemoveTinyMCE('pbsg_quiz_question_' + oldIdx);
      }
    });

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
      const resBadge = hasRes ? `<span class="pbsg-badge pbsg-badge--ok">Resource ${icon('check')}</span>` : '';

      $c.append(`
        <div class="pbsg-step-card" data-idx="${idx}" id="pbsg-step-${idx}">
          <div class="pbsg-step-header">
            <span class="pbsg-drag-handle" title="Drag to reorder">${icon('drag-handle')}</span>
            <span class="pbsg-step-number">${num}</span>
            <input class="pbsg-step-title-input" type="text" value="${esc(s.title)}" placeholder="Page title (optional)" data-idx="${idx}" />
            <div class="pbsg-step-badges">
              ${quizBadge}${resBadge}
            </div>
            <div class="pbsg-step-header-actions">
              <button type="button" class="pbsg-step-chevron pbsg-btn-ghost" data-idx="${idx}" aria-label="Toggle step visibility" title="Collapse">
                ${icon('chevron-down')}
              </button>
              <button type="button" class="pbsg-btn-ghost pbsg-remove-step" data-idx="${idx}" title="Remove step">&times;</button>
            </div>
          </div>
          <div class="pbsg-step-body" data-idx="${idx}">
            <div class="pbsg-panel pbsg-panel-quiz" data-idx="${idx}">
              <div class="pbsg-field pbsg-instructions-wrap">
                <label class="pbsg-field-label">
                  <span class="pbsg-panel-icon">${icon('document')}</span> Instructions (left pane)
                </label>
                <textarea id="pbsg_instructions_${idx}" class="pbsg-instructions-editor" data-idx="${idx}">${esc(s.instructions_html || '')}</textarea>
              </div>
              <div class="pbsg-panel-label">
                <span class="pbsg-panel-icon">${icon('puzzle')}</span> Quiz Question
              </div>
              ${renderQuizPanel(s, idx)}
            </div>
            <div class="pbsg-panel" data-idx="${idx}">
              <div class="pbsg-panel-label">
                <span class="pbsg-panel-icon">${icon('book-open')}</span> Tutorial Resource
              </div>
              ${renderResourcePanel(s, idx)}
            </div>
             <div class="pbsg-panel pbsg-panel-branch" data-idx="${idx}">
              <div class="pbsg-branch-panel-head">
                <span class="pbsg-panel-icon">${icon('shuffle')}</span>
                <strong class="pbsg-branch-panel-title">Branch</strong>
                <span class="pbsg-branch-summary">${esc(branchSummary(s))}</span>
                <div class="pbsg-branch-head-actions">
                  <button type="button" class="button pbsg-set-branch" data-idx="${idx}">${s.branch ? 'Edit Branch' : 'Set Branch'}</button>
                  <button type="button" class="button pbsg-clear-branch" data-idx="${idx}">Clear</button>
                </div>
              </div>
            </div>
            <div class="pbsg-branch-editor-wrap" data-idx="${idx}" style="display:${s.branch ? 'block' : 'none'};">
              ${s.branch ? renderBranchEditor(s, idx) : ''}
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
    steps.forEach(function (_s, idx) {
      if (_s.quiz && _s.quiz.type === 'blanks') updateBlanksCaseRiskUI(idx);
    });

    // Initialize TinyMCE editors for instructions + quiz questions (after DOM is ready)
    steps.forEach(function (_s, idx) {
      pbsgInitTinyMCE('pbsg_instructions_' + idx);
      if (_s.quiz && _s.quiz.type !== 'blanks') {
        pbsgInitTinyMCE('pbsg_quiz_question_' + idx);
      }
    });
  }

  // ─── Quiz Panel ────────────────────────────────────────
  function renderQuizPanel(s, idx) {
    if (s.h5p_id > 0 && !s.quiz) {
      const meta = h5pMetaCache[s.h5p_id] || {};
      const isOwner = meta.is_owner !== false;
      const title = meta.title || ('H5P #' + s.h5p_id);
      const authorName = meta.author_name || 'Unknown user';
      const usageCount = meta.usage_count || 0;

      // Title row: owners get lock icon, non-owners see read-only text
      let titleRow = '';
      if (isOwner) {
        titleRow = `<div class="pbsg-h5p-title-row pbsg-h5p-title-row--locked" data-idx="${idx}" data-h5p-id="${s.h5p_id}">
          <span class="pbsg-h5p-title-label">H5P Name:</span>
          <span class="pbsg-h5p-title-text">${esc(title)}</span>
          <span class="pbsg-h5p-lock-icon pbsg-h5p-lock-toggle" data-idx="${idx}" data-h5p-id="${s.h5p_id}" data-original-title="${esc(title)}" title="Click to unlock and edit name">${icon('lock-closed')}</span>
        </div>
        <div class="pbsg-h5p-title-status" data-idx="${idx}"></div>`;
      } else {
        titleRow = `<div class="pbsg-h5p-title-row pbsg-h5p-title-row--locked">
          <span class="pbsg-h5p-title-label" style="color:#bbb;">H5P Name:</span>
          <span class="pbsg-h5p-title-text" style="color:#aaa;">${esc(title)}</span>
        </div>`;
      }

      return `<div class="pbsg-linked-h5p" data-h5p-owner="${isOwner}" data-h5p-author="${esc(authorName)}" data-h5p-usage="${usageCount}">
        <div class="pbsg-linked-h5p-info">
          <span class="pbsg-linked-icon">${icon('link')}</span>
          <span>Linked to <strong>H5P #${s.h5p_id}</strong></span>
        </div>
        ${titleRow}
        <div class="pbsg-linked-h5p-actions">
          <a href="#" class="pbsg-create-inline-from-linked" data-idx="${idx}">Create new</a>
          <span class="pbsg-sep">|</span>
          <a href="#" class="pbsg-use-existing-h5p" data-idx="${idx}">Select exists</a>
          <span class="pbsg-sep">|</span>
          <a href="#" class="pbsg-edit-h5p" data-idx="${idx}" data-h5p-id="${s.h5p_id}">Edit H5P</a>
          <span class="pbsg-sep">|</span>
          <a href="#" class="pbsg-remove-h5p-link" data-idx="${idx}">Remove link</a>
        </div>
      </div>`;
    }

    if (s.h5p_cleared && !s.quiz && !s.h5p_id) {
      return `<div class="pbsg-linked-h5p">
        <div class="pbsg-linked-h5p-info">
          <span class="pbsg-linked-icon">${icon('link')}</span>
          <span><strong>No H5P quiz linked</strong></span>
        </div>
        <div class="pbsg-linked-h5p-actions">
          <a href="#" class="pbsg-use-existing-h5p" data-idx="${idx}">Select exists</a>
          <span class="pbsg-sep">|</span>
          <a href="#" class="pbsg-create-inline-from-linked" data-idx="${idx}">Create new</a>
        </div>
      </div>`;
    }

    const qt = s.quiz ? s.quiz.type : 'multichoice';

    return `
      <div class="pbsg-quiz-type-selector" data-idx="${idx}">
        <button type="button" class="pbsg-quiz-type-btn${qt === 'multichoice' ? ' active' : ''}" data-type="multichoice" data-idx="${idx}">
          <span class="pbsg-type-icon">${icon('checkbox-multi')}</span>Multiple Selection</button>
        <button type="button" class="pbsg-quiz-type-btn${qt === 'blanks' ? ' active' : ''}" data-type="blanks" data-idx="${idx}">
          <span class="pbsg-type-icon">${icon('pencil')}</span>Fill in Blanks</button>
        <button type="button" class="pbsg-quiz-type-btn${qt === 'singlechoice' ? ' active' : ''}" data-type="singlechoice" data-idx="${idx}">
          <span class="pbsg-type-icon">${icon('radio')}</span>Single Selection</button>
      </div>
      <div class="pbsg-quiz-form" data-idx="${idx}">${renderQuizForm(qt, s.quiz || {}, idx)}</div>
      <div class="pbsg-existing-h5p-link">
        <span>${icon('lightbulb')}</span>
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
        <input type="checkbox" aria-label="Answer Option Correct Check" class="pbsg-answer-check" data-idx="${idx}" data-aidx="${ai}" ${cor ? 'checked' : ''} />
        <input type="text" class="pbsg-answer-input" data-idx="${idx}" data-aidx="${ai}" value="${esc(a.text)}" placeholder="Answer option..." />
        ${cor ? `<span class="pbsg-answer-correct-label">${icon('check', 'pbsg-icon--ok')} Correct</span>` : ''}
        <button type="button" class="pbsg-answer-remove" data-idx="${idx}" data-aidx="${ai}" title="Remove">&times;</button>
      </div>`;
    });

    return `
      <div class="pbsg-field pbsg-quiz-question-wrap">
        <label class="pbsg-field-label">Question</label>
        <textarea id="pbsg_quiz_question_${idx}" class="pbsg-quiz-question-editor" data-idx="${idx}">${esc(q)}</textarea>
      </div>
      <div class="pbsg-field">
        <label class="pbsg-field-label">Answers <span class="pbsg-field-optional">&mdash; check the correct one(s)</span></label>
        <div class="pbsg-answers-list" data-idx="${idx}">${rows}</div>
        <button type="button" class="pbsg-btn-outline pbsg-add-answer" data-idx="${idx}">+ Add Answer</button>
      </div>`;
  }

  // ─── Fill in the Blanks ───────────────────────────────
  /** Tokens inside *asterisks* (each / alternative, hints stripped) for case-risk checks */
  function blanksAnswerTokensFromSentence(sentence) {
    const tokens = [];
    const re = /\*([^*]+)\*/g;
    let m;
    while ((m = re.exec(sentence || ''))) {
      let inner = m[1];
      const ci = inner.indexOf(':');
      if (ci > -1) inner = inner.substring(0, ci);
      inner.split('/').forEach(function (part) {
        const t = part.trim();
        if (t) tokens.push(t);
      });
    }
    return tokens;
  }

  /** True if any expected answer mixes upper and lower case (risky with case-sensitive grading). */
  function blanksCaseSensitiveRiskyAnswers(sentence) {
    return blanksAnswerTokensFromSentence(sentence).some(function (t) {
      return /[a-z]/.test(t) && /[A-Z]/.test(t);
    });
  }

  function updateBlanksCaseRiskUI(idx) {
    if (isNaN(idx)) return;
    const $card = $(`#pbsg-step-${idx}`);
    if (!$card.length) return;
    const sentence = $card.find('.pbsg-blanks-sentence').val() || '';
    const caseOn = $card.find('.pbsg-blanks-case').is(':checked');
    const cnt = (sentence.match(/\*[^*]+\*/g) || []).length;
    const risky = caseOn && cnt > 0 && blanksCaseSensitiveRiskyAnswers(sentence);
    $card.find('.pbsg-blanks-case-risk-msg').toggle(risky);
  }

  function renderBlanksForm(quiz, idx) {
    const sentence = quiz.sentence || '';
    const caseSens = quiz.case_sensitive !== undefined ? quiz.case_sensitive : false;
    const typos = quiz.accept_typos !== undefined ? quiz.accept_typos : false;
    const preview = blanksPreview(sentence);
    const cnt = (sentence.match(/\*[^*]+\*/g) || []).length;
    const caseRiskVisible = caseSens && cnt > 0 && blanksCaseSensitiveRiskyAnswers(sentence);

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
          ? `<div class="pbsg-validation-msg pbsg-validation-msg--ok">${icon('check', 'pbsg-icon--ok')} ${cnt} blank${cnt !== 1 ? 's' : ''} detected</div>`
          : `<div class="pbsg-validation-msg pbsg-validation-msg--error">${icon('warning', 'pbsg-icon--warn')} No blanks detected &mdash; wrap words with *asterisks*</div>`}
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
        <div class="pbsg-blanks-case-risk-msg" data-idx="${idx}" style="display:${caseRiskVisible ? 'block' : 'none'}; margin-top:10px; padding:8px; background:#fcf3f3; border:1px solid #e8b4b4; border-radius:4px; font-size:12px; color:#5c1a1a;">
          <strong>Case sensitivity:</strong> At least one wrapped answer mixes uppercase and lowercase. With Case sensitive enabled, grading requires an exact character match &mdash; students may be marked wrong if capitalization differs.
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
      const hintHtml = hint ? ` <span class="pbsg-hint-icon" title="Hint: ${esc(hint)}">${icon('lightbulb')}</span>` : '';
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
      <div class="pbsg-field pbsg-quiz-question-wrap">
        <label class="pbsg-field-label">Question</label>
        <textarea id="pbsg_quiz_question_${idx}" class="pbsg-quiz-question-editor" data-idx="${idx}">${esc(q)}</textarea>
      </div>
      <div class="pbsg-field">
        <label class="pbsg-field-label">Correct Answer</label>
        <div class="pbsg-sc-correct-row">
          <span class="pbsg-sc-icon">${icon('check', 'pbsg-icon--ok')}</span>
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
          <span class="pbsg-preview-icon">${isYT ? icon('play') : icon('globe')}</span>
          ${isYT ? 'YouTube video will be embedded in the right pane' : 'Page will load in the right pane iframe'}
        </div>` : ''}`;
    } else {
      const fn = s.tutorial_file_name || (s.tutorial_attachment_id ? 'Attachment #' + s.tutorial_attachment_id : '');
      content = fn
        ? `<div class="pbsg-file-info">
            <span class="pbsg-file-icon">${icon('document-page')}</span>
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
            <span class="pbsg-upload-icon">${icon('arrow-up')}</span>
            <div>Drag &amp; drop a file here, or click to browse</div>
            ${PBSG_ADMIN.maxUploadLabel ? `<div class="pbsg-upload-size-hint">Max file size: ${esc(PBSG_ADMIN.maxUploadLabel)}</div>` : ''}
          </div>`;
    }

    return `
      <div class="pbsg-resource-type-toggle" data-idx="${idx}">
        <label class="${!isFile ? 'active' : ''}">
          <input type="radio" name="pbsg_res_type_${idx}" value="url" ${!isFile ? 'checked' : ''} />
          <span class="span">${icon('link')} URL</span>
        </label>
        <label class="${isFile ? 'active' : ''}">
          <input type="radio" name="pbsg_res_type_${idx}" value="file" ${isFile ? 'checked' : ''} />
          <span>${icon('document-page')} Upload File</span>
        </label>
      </div>
      ${content}`;
  }

  // ═══════════════════════════════════════════════════════════
  //  Event Handlers — Steps
  // ═══════════════════════════════════════════════════════════
  $(document).on('click', '#pbsg-add-step', function () {
    const steps = getSteps().map(norm);
      steps.push({
        title: '',
        h5p_id: 0,
        quiz: {
          type: 'multichoice',
          question: '',
          answers: [
            { text: '', correct: true },
            { text: '', correct: false }
          ]
        },
        tutorial_type: '',
        tutorial_url: '',
        tutorial_attachment_id: 0,
        tutorial_file_name: '',
        tutorial_file_url: '',
        url: '',
        branch: null
      });
    setSteps(steps);
    renderStepCards();
    const $last = $('.pbsg-step-card').last();
    if ($last.length) $('html, body').animate({ scrollTop: $last.offset().top - 60 }, 300);
  });

  $(document).on('click', '.pbsg-remove-step', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;
    const steps = getSteps().map(norm);
    const step = steps[idx];
    const str = pbsgAdminI18n();
    const untitled = str.untitledStep || '(Untitled step)';
    const rawLabel = (step && String(step.title || '').trim()) ? String(step.title).trim() : untitled;
    const label = truncateForConfirm(rawLabel, 80);
    const stepNum = idx + 1;
    const template = str.confirmRemoveStep
      || 'Are you sure you want to remove Step %1$d: %2$s? Quiz and resource settings for this step will be lost.';
    const msg = template.replace('%1$d', String(stepNum)).replace('%2$s', label);
    if (!window.confirm(msg)) return;
    steps.splice(idx, 1);
    setSteps(steps);
    renderStepCards();
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
  $(document).on('input', '.pbsg-answer-input', function () { syncQuiz(parseInt($(this).data('idx'), 10)); });
  $(document).on('change', '.pbsg-answer-check', function () {
    const idx = parseInt($(this).data('idx'), 10);
    syncQuiz(idx);
    renderStepCards(); // re-render to update correct/incorrect styling
  });
  $(document).on('click', '.pbsg-add-answer', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;
    syncInstructions(idx);
    syncQuiz(idx);
    const steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz) { steps[idx].quiz.answers = steps[idx].quiz.answers || []; steps[idx].quiz.answers.push({ text: '', correct: false }); setSteps(steps); renderStepCards(true); }
  });
  $(document).on('click', '.pbsg-answer-remove', function () {
    const idx = parseInt($(this).data('idx'), 10), ai = parseInt($(this).data('aidx'), 10);
    if (isNaN(idx)) return;
    syncInstructions(idx);
    syncQuiz(idx);
    const steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz && steps[idx].quiz.answers && steps[idx].quiz.answers.length > 2) { steps[idx].quiz.answers.splice(ai, 1); setSteps(steps); renderStepCards(true); }
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
        .html(icon('check', 'pbsg-icon--ok') + ' ' + cnt + ' blank' + (cnt !== 1 ? 's' : '') + ' detected');
    } else {
      $vm.removeClass('pbsg-validation-msg--ok').addClass('pbsg-validation-msg--error')
        .html(icon('warning', 'pbsg-icon--warn') + ' No blanks detected &mdash; wrap words with *asterisks*');
    }
    syncQuiz(idx);
    updateBlanksCaseRiskUI(idx);
  });
  $(document).on('change', '.pbsg-blanks-case, .pbsg-blanks-typos', function () {
    const $opt = $(this).closest('.pbsg-toggle-option');
    $opt.toggleClass('pbsg-toggle-option--active', $(this).is(':checked'));
    const idx = parseInt($(this).data('idx'), 10);
    syncQuiz(idx);
    updateBlanksCaseRiskUI(idx);
  });

  // ═══════════════════════════════════════════════════════════
  //  Single Choice Events
  // ═══════════════════════════════════════════════════════════
  $(document).on('input', '.pbsg-sc-correct-input, .pbsg-sc-wrong-input', function () { syncQuiz(parseInt($(this).data('idx'), 10)); });
  $(document).on('click', '.pbsg-add-sc-wrong', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;
    syncInstructions(idx);
    syncQuiz(idx);
    const steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz) { steps[idx].quiz.wrong_answers = steps[idx].quiz.wrong_answers || []; steps[idx].quiz.wrong_answers.push(''); setSteps(steps); renderStepCards(true); }
  });
  $(document).on('click', '.pbsg-sc-wrong-remove', function () {
    const idx = parseInt($(this).data('idx'), 10), wi = parseInt($(this).data('widx'), 10);
    if (isNaN(idx)) return;
    syncInstructions(idx);
    syncQuiz(idx);
    const steps = getSteps().map(norm);
    if (steps[idx] && steps[idx].quiz && steps[idx].quiz.wrong_answers && steps[idx].quiz.wrong_answers.length > 1) { steps[idx].quiz.wrong_answers.splice(wi, 1); setSteps(steps); renderStepCards(true); }
  });

  // ═══════════════════════════════════════════════════════════
  //  Sync Instructions from TinyMCE
  // ═══════════════════════════════════════════════════════════
  function syncInstructions(idx) {
    if (isNaN(idx)) return;
    const steps = getSteps().map(norm), step = steps[idx];
    if (!step) return;
    step.instructions_html = pbsgGetTinyMCEContent('pbsg_instructions_' + idx) || '';
    steps[idx] = step; setSteps(steps);
  }

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
        step.quiz.question = pbsgGetTinyMCEContent('pbsg_quiz_question_' + idx) || '';
        const a = []; $card.find('.pbsg-answers-list > div').each(function () { a.push({ text: $(this).find('.pbsg-answer-input').val() || '', correct: $(this).find('.pbsg-answer-check').is(':checked') }); });
        step.quiz.answers = a; break;
      }
      case 'blanks': {
        step.quiz.sentence = $card.find('.pbsg-blanks-sentence').val() || '';
        step.quiz.case_sensitive = $card.find('.pbsg-blanks-case').is(':checked');
        step.quiz.accept_typos = $card.find('.pbsg-blanks-typos').is(':checked'); break;
      }
      case 'singlechoice': {
        step.quiz.question = pbsgGetTinyMCEContent('pbsg_quiz_question_' + idx) || '';
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


  function openBranchMediaPicker(stepIdx) {
    const frame = wp.media({ title: 'Select or Upload Branch Tutorial File', button: { text: 'Use this file' }, multiple: false });
    let fileSelected = false;

    frame.on('select', function () {
      fileSelected = true;
      const att = frame.state().get('selection').first().toJSON();
      const steps = getSteps().map(norm);
      const step = steps[stepIdx];
      if (!step) return;

      const branch = ensureBranch(step);
      branch.tutorial_type = 'file';
      branch.tutorial_attachment_id = att.id || 0;
      branch.tutorial_file_name = att.filename || att.title || '';
      branch.tutorial_file_url = att.url || '';
      branch.tutorial_url = '';

      setSteps(steps);
      renderStepCards();
    });

    frame.on('close', function () {
      if (!fileSelected) {
        const steps = getSteps().map(norm);
        const step = steps[stepIdx];
        if (!step || !step.branch) return;

        if (!step.branch.tutorial_attachment_id) {
          step.branch.tutorial_type = step.branch.tutorial_url ? 'url' : '';
          setSteps(steps);
          renderStepCards();
        }
      }
    });

    frame.open();
  }

function openBranchQuestionMediaPicker(stepIdx, qIdx) {
  const frame = wp.media({ title: 'Select or Upload Tutorial File', button: { text: 'Use this file' }, multiple: false });
  let fileSelected = false;

  frame.on('select', function () {
    fileSelected = true;
    const att = frame.state().get('selection').first().toJSON();

    syncBranchQuestionResource(stepIdx, qIdx, function (q) {
      q.tutorial_type = 'file';
      q.tutorial_attachment_id = att.id || 0;
      q.tutorial_file_name = att.filename || att.title || '';
      q.tutorial_file_url = att.url || '';
      q.tutorial_url = '';
    });

    renderStepCards();
  });

  frame.on('close', function () {
    if (!fileSelected) {
      const steps = getSteps().map(norm);
      const step = steps[stepIdx];
      if (!step || !step.branch || !Array.isArray(step.branch.questions) || !step.branch.questions[qIdx]) return;

      const q = step.branch.questions[qIdx];
      if (!q.tutorial_attachment_id) {
        q.tutorial_type = q.tutorial_url ? 'url' : '';
        setSteps(steps);
        renderStepCards();
      }
    }
  });

  frame.open();
}

  function uploadBranchFileViaDrop(file, stepIdx, $zone) {
    $zone.find('.pbsg-upload-error, .pbsg-upload-progress').remove();
    $zone.append(`<div class="pbsg-upload-progress"><div class="pbsg-upload-progress-bar" style="width:0%"></div></div>`);
    const $bar = $zone.find('.pbsg-upload-progress-bar');

    const formData = new FormData();
    formData.append('pbsg_file', file);
    formData.append('action', 'pbsg_upload_file');
    formData.append('nonce', PBSG_ADMIN.uploadNonce);

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
          const step = steps[stepIdx];
          if (!step) return;

          const branch = ensureBranch(step);
          branch.tutorial_type = 'file';
          branch.tutorial_attachment_id = att.id || 0;
          branch.tutorial_file_name = att.filename || '';
          branch.tutorial_file_url = att.url || '';
          branch.tutorial_url = '';

          setSteps(steps);
          renderStepCards();
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
        } catch (_) {}
        $zone.append(`<div class="pbsg-upload-error">${esc(msg)}</div>`);
      }
    });
  }


function uploadBranchQuestionFileViaDrop(file, stepIdx, qIdx, $zone) {
  $zone.find('.pbsg-upload-error, .pbsg-upload-progress').remove();
  $zone.append(`<div class="pbsg-upload-progress"><div class="pbsg-upload-progress-bar" style="width:0%"></div></div>`);
  const $bar = $zone.find('.pbsg-upload-progress-bar');

  const formData = new FormData();
  formData.append('pbsg_file', file);
  formData.append('action', 'pbsg_upload_file');
  formData.append('nonce', PBSG_ADMIN.uploadNonce);

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

        syncBranchQuestionResource(stepIdx, qIdx, function (q) {
          q.tutorial_type = 'file';
          q.tutorial_attachment_id = att.id || 0;
          q.tutorial_file_name = att.filename || '';
          q.tutorial_file_url = att.url || '';
          q.tutorial_url = '';
        });

        renderStepCards();
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
      } catch (_) {}
      $zone.append(`<div class="pbsg-upload-error">${esc(msg)}</div>`);
    }
  });
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

  function ensureBranch(step) {
    if (!step.branch) step.branch = makeEmptyBranch();
    return step.branch;
  }

  function syncBranchMode(stepIdx, value) {
    const steps = getSteps().map(norm);
    const step = steps[stepIdx];
    if (!step) return;
    const branch = ensureBranch(step);
    branch.mode = (value === 'mandatory') ? 'mandatory' : 'optional';
    setSteps(steps);
  }

  function syncBranchQuestion(stepIdx, qIdx, updater) {
    const steps = getSteps().map(norm);
    const step = steps[stepIdx];
    if (!step) return;
    const branch = ensureBranch(step);

    if (!Array.isArray(branch.questions)) branch.questions = [];
    if (!branch.questions[qIdx]) return;

    updater(branch.questions[qIdx]);

    // Mark for H5P update if this branch question already has linked H5P content.
    // save_meta will call PBSG_H5P_Factory::update() instead of create() when this flag is set.
    if (branch.questions[qIdx].h5p_id > 0) {
      branch.questions[qIdx]._editing_h5p = true;
    }

    setSteps(steps);
  }


function syncBranchQuestionResource(stepIdx, qIdx, updater) {
  const steps = getSteps().map(norm);
  const step = steps[stepIdx];
  if (!step) return;

  const branch = ensureBranch(step);
  if (!Array.isArray(branch.questions) || !branch.questions[qIdx]) return;

  updater(branch.questions[qIdx]);
  setSteps(steps);
}

  function syncBranchResource(stepIdx) {
    const steps = getSteps().map(norm);
    const step = steps[stepIdx];
    if (!step) return;
    const branch = ensureBranch(step);
    const $card = $(`#pbsg-step-${stepIdx}`);

    if (branch.tutorial_type !== 'file') {
      const url = $card.find('.pbsg-branch-resource-url').val() || '';
      branch.tutorial_url = url;
      branch.tutorial_type = url ? 'url' : '';
      if (!url) {
        branch.tutorial_attachment_id = 0;
        branch.tutorial_file_name = '';
        branch.tutorial_file_url = '';
      }
    }

    setSteps(steps);
  }


function syncBranchResourceMode(stepIdx, value) {
  const steps = getSteps().map(norm);
  const step = steps[stepIdx];
  if (!step) return;

  const branch = ensureBranch(step);
  branch.resource_mode = ['main', 'shared', 'per_question'].includes(value) ? value : 'main';

  setSteps(steps);
}


    $(document).on('click', '.pbsg-set-branch', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;

    const steps = getSteps().map(norm);
    if (!steps[idx]) return;

    if (!steps[idx].branch) {
      steps[idx].branch = makeEmptyBranch();
      setSteps(steps);
      renderStepCards();
      return;
    }

    const $wrap = $(`.pbsg-branch-editor-wrap[data-idx="${idx}"]`);
    if ($wrap.length) {
      $wrap.stop(true, true).slideToggle(180);
    }
  });

  $(document).on('click', '.pbsg-clear-branch', function () {
    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;

    const steps = getSteps().map(norm);
    if (!steps[idx]) return;

    steps[idx].branch = null;
    setSteps(steps);
    renderStepCards();
  });

  $(document).on('change', '.pbsg-branch-mode', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    if (isNaN(stepIdx)) return;
    syncBranchMode(stepIdx, $(this).val());
    renderStepCards();
  });

  $(document).on('change', '.pbsg-branch-resource-mode', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    if (isNaN(stepIdx)) return;

    syncBranchResourceMode(stepIdx, $(this).val());
    renderStepCards();
  });

  $(document).on('click', '.pbsg-add-branch-question', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const type = $(this).data('type');
    if (isNaN(stepIdx)) return;

    const steps = getSteps().map(norm);
    const step = steps[stepIdx];
    if (!step) return;

    const branch = ensureBranch(step);
    if (!Array.isArray(branch.questions)) branch.questions = [];
    branch.questions.push(makeDefaultBranchQuestion(type));

    setSteps(steps);
    renderStepCards();
  });

  $(document).on('click', '.pbsg-remove-branch-question', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    if (isNaN(stepIdx) || isNaN(qIdx)) return;

    const steps = getSteps().map(norm);
    const step = steps[stepIdx];
    if (!step || !step.branch || !Array.isArray(step.branch.questions)) return;

    step.branch.questions.splice(qIdx, 1);
    setSteps(steps);
    renderStepCards();
  });

  $(document).on('input', '.pbsg-branch-mc-question', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    syncBranchQuestion(stepIdx, qIdx, function (q) {
      q.question = $(this).val() || '';
    }.bind(this));
  });

  $(document).on('input', '.pbsg-branch-mc-answer', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    const aIdx = parseInt($(this).data('aidx'), 10);

    syncBranchQuestion(stepIdx, qIdx, function (q) {
      if (!Array.isArray(q.answers)) q.answers = [];
      if (!q.answers[aIdx]) q.answers[aIdx] = { text: '', correct: false };
      q.answers[aIdx].text = $(this).val() || '';
    }.bind(this));
  });

  $(document).on('change', '.pbsg-branch-mc-correct', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    const aIdx = parseInt($(this).data('aidx'), 10);

    syncBranchQuestion(stepIdx, qIdx, function (q) {
      if (!Array.isArray(q.answers)) q.answers = [];
      if (!q.answers[aIdx]) q.answers[aIdx] = { text: '', correct: false };
      q.answers[aIdx].correct = $(this).is(':checked');
    }.bind(this));
  });

  $(document).on('click', '.pbsg-branch-add-mc-answer', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);

    syncBranchQuestion(stepIdx, qIdx, function (q) {
      if (!Array.isArray(q.answers)) q.answers = [];
      q.answers.push({ text: '', correct: false });
    });

    renderStepCards(true);
  });

  $(document).on('click', '.pbsg-branch-mc-remove-answer', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    const aIdx = parseInt($(this).data('aidx'), 10);

    syncBranchQuestion(stepIdx, qIdx, function (q) {
      if (!Array.isArray(q.answers)) q.answers = [];
      q.answers.splice(aIdx, 1);
      if (!q.answers.length) {
        q.answers.push({ text: '', correct: true });
        q.answers.push({ text: '', correct: false });
      }
    });

    renderStepCards(true);
  });

  $(document).on('input', '.pbsg-branch-blanks-sentence', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    syncBranchQuestion(stepIdx, qIdx, function (q) {
      q.sentence = $(this).val() || '';
    }.bind(this));
  });

  $(document).on('change', '.pbsg-branch-blanks-case', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    syncBranchQuestion(stepIdx, qIdx, function (q) {
      q.case_sensitive = $(this).is(':checked');
    }.bind(this));
  });

  $(document).on('change', '.pbsg-branch-blanks-typos', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    syncBranchQuestion(stepIdx, qIdx, function (q) {
      q.accept_typos = $(this).is(':checked');
    }.bind(this));
  });

  // ── Sub-quiz blanks: live preview + validation ──
  $(document).on('input', '.pbsg-branch-blanks-sentence', function () {
    const $textarea = $(this);
    const $card = $textarea.closest('.pbsg-branch-question-card');
    const sentence = $textarea.val();
    const cnt = (sentence.match(/\*[^*]+\*/g) || []).length;

    $card.find('.pbsg-branch-blanks-preview').html(blanksPreview(sentence));

    const $msg = $card.find('.pbsg-branch-blanks-validation');
    if (cnt > 0) {
      $msg.attr('class', 'pbsg-branch-blanks-validation pbsg-validation-msg pbsg-validation-msg--ok')
          .html('&#x2713; ' + cnt + ' blank' + (cnt !== 1 ? 's' : '') + ' detected');
    } else {
      $msg.attr('class', 'pbsg-branch-blanks-validation pbsg-validation-msg pbsg-validation-msg--error')
          .html('&#x26A0; No blanks detected &mdash; wrap words with *asterisks*');
    }

    const caseOn = $card.find('.pbsg-branch-blanks-case').is(':checked');
    const risky = caseOn && cnt > 0 && blanksCaseSensitiveRiskyAnswers(sentence);
    $card.find('.pbsg-branch-blanks-case-risk-msg').toggle(risky);
  });

  // ── Sub-quiz blanks: case-sensitivity toggle live update ──
  $(document).on('change', '.pbsg-branch-blanks-case', function () {
    const $checkbox = $(this);
    const $card = $checkbox.closest('.pbsg-branch-question-card');
    const sentence = $card.find('.pbsg-branch-blanks-sentence').val() || '';
    const cnt = (sentence.match(/\*[^*]+\*/g) || []).length;
    const risky = $checkbox.is(':checked') && cnt > 0 && blanksCaseSensitiveRiskyAnswers(sentence);
    $card.find('.pbsg-branch-blanks-case-risk-msg').toggle(risky);
    $checkbox.closest('.pbsg-toggle-option').toggleClass('pbsg-toggle-option--active', $checkbox.is(':checked'));
  });

  // ── Sub-quiz blanks: typos toggle active-class update ──
  $(document).on('change', '.pbsg-branch-blanks-typos', function () {
    const $checkbox = $(this);
    $checkbox.closest('.pbsg-toggle-option').toggleClass('pbsg-toggle-option--active', $checkbox.is(':checked'));
  });

  $(document).on('input', '.pbsg-branch-sc-question', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    syncBranchQuestion(stepIdx, qIdx, function (q) {
      q.question = $(this).val() || '';
    }.bind(this));
  });

  $(document).on('input', '.pbsg-branch-sc-correct', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    syncBranchQuestion(stepIdx, qIdx, function (q) {
      q.correct_answer = $(this).val() || '';
    }.bind(this));
  });

  $(document).on('input', '.pbsg-branch-sc-wrong-input', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    const wIdx = parseInt($(this).data('widx'), 10);

    syncBranchQuestion(stepIdx, qIdx, function (q) {
      if (!Array.isArray(q.wrong_answers)) q.wrong_answers = [''];
      q.wrong_answers[wIdx] = $(this).val() || '';
    }.bind(this));
  });

  $(document).on('click', '.pbsg-branch-add-sc-wrong', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);

    syncBranchQuestion(stepIdx, qIdx, function (q) {
      if (!Array.isArray(q.wrong_answers)) q.wrong_answers = [];
      q.wrong_answers.push('');
    });

    renderStepCards(true);
  });

  $(document).on('click', '.pbsg-branch-sc-wrong-remove', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    const wIdx = parseInt($(this).data('widx'), 10);

    syncBranchQuestion(stepIdx, qIdx, function (q) {
      if (!Array.isArray(q.wrong_answers)) q.wrong_answers = [''];
      q.wrong_answers.splice(wIdx, 1);
      if (!q.wrong_answers.length) q.wrong_answers = [''];
    });

    renderStepCards(true);
  });

  $(document).on('change', '.pbsg-branch-resource-type-toggle input[type="radio"]', function () {
    const stepIdx = parseInt($(this).closest('.pbsg-branch-resource-type-toggle').data('step-idx'), 10);
    const type = $(this).val();
    if (isNaN(stepIdx)) return;

    const steps = getSteps().map(norm);
    const step = steps[stepIdx];
    if (!step) return;

    const branch = ensureBranch(step);

    if (type === 'file') {
      branch.tutorial_type = 'file';
      setSteps(steps);
      renderStepCards();
    } else {
      branch.tutorial_type = branch.tutorial_url ? 'url' : '';
      branch.tutorial_attachment_id = 0;
      branch.tutorial_file_name = '';
      branch.tutorial_file_url = '';
      setSteps(steps);
      renderStepCards();
    }
  });

$(document).on('change', '.pbsg-branch-q-resource-type-toggle input[type="radio"]', function () {
  const $wrap = $(this).closest('.pbsg-branch-q-resource-type-toggle');
  const stepIdx = parseInt($wrap.data('step-idx'), 10);
  const qIdx = parseInt($wrap.data('qidx'), 10);
  const type = $(this).val();

  if (isNaN(stepIdx) || isNaN(qIdx)) return;

  syncBranchQuestionResource(stepIdx, qIdx, function (q) {
    if (type === 'file') {
      q.tutorial_type = 'file';
    } else {
      q.tutorial_type = q.tutorial_url ? 'url' : '';
      q.tutorial_attachment_id = 0;
      q.tutorial_file_name = '';
      q.tutorial_file_url = '';
    }
  });

  renderStepCards();
});

  $(document).on('input', '.pbsg-branch-resource-url', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    if (isNaN(stepIdx)) return;
    syncBranchResource(stepIdx);
  });

  $(document).on('input', '.pbsg-branch-q-resource-url', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    if (isNaN(stepIdx) || isNaN(qIdx)) return;

    syncBranchQuestionResource(stepIdx, qIdx, function (q) {
      const url = $(this).val() || '';
      q.tutorial_url = url;
      q.tutorial_type = url ? 'url' : '';
      if (!url) {
        q.tutorial_attachment_id = 0;
        q.tutorial_file_name = '';
        q.tutorial_file_url = '';
      }
    }.bind(this));
  });


  $(document).on('click', '.pbsg-branch-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const stepIdx = parseInt($(this).data('step-idx'), 10);
    if (isNaN(stepIdx)) return;

    if (typeof wp === 'undefined' || typeof wp.media !== 'function') {
      alert('Media uploader not available. Please reload the page and try again.');
      return;
    }

    openBranchMediaPicker(stepIdx);
  });


$(document).on('click', '.pbsg-branch-q-upload-zone', function (e) {
  e.preventDefault();
  e.stopPropagation();

  const stepIdx = parseInt($(this).data('step-idx'), 10);
  const qIdx = parseInt($(this).data('qidx'), 10);
  if (isNaN(stepIdx) || isNaN(qIdx)) return;

  if (typeof wp === 'undefined' || typeof wp.media !== 'function') {
    alert('Media uploader not available. Please reload the page and try again.');
    return;
  }

  openBranchQuestionMediaPicker(stepIdx, qIdx);
});

  $(document).on('dragover dragenter', '.pbsg-branch-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).addClass('pbsg-upload-zone--dragover');
  });

  $(document).on('dragleave', '.pbsg-branch-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('pbsg-upload-zone--dragover');
  });

  $(document).on('drop', '.pbsg-branch-upload-zone', function (e) {
    e.preventDefault();
    e.stopPropagation();
    $(this).removeClass('pbsg-upload-zone--dragover');

    const stepIdx = parseInt($(this).data('step-idx'), 10);
    if (isNaN(stepIdx)) return;

    const files = e.originalEvent.dataTransfer.files;
    if (!files || !files.length) return;

    const file = files[0];
    const maxSize = PBSG_ADMIN.maxUploadSize || 0;

    if (maxSize > 0 && file.size > maxSize) {
      const $zone = $(this);
      $zone.find('.pbsg-upload-error').remove();
      $zone.append(`<div class="pbsg-upload-error">File too large. Maximum size: ${PBSG_ADMIN.maxUploadLabel || 'unknown'}</div>`);
      return;
    }

    uploadBranchFileViaDrop(file, stepIdx, $(this));
  });



$(document).on('dragover dragenter', '.pbsg-branch-q-upload-zone', function (e) {
  e.preventDefault();
  e.stopPropagation();
  $(this).addClass('pbsg-upload-zone--dragover');
});

$(document).on('dragleave', '.pbsg-branch-q-upload-zone', function (e) {
  e.preventDefault();
  e.stopPropagation();
  $(this).removeClass('pbsg-upload-zone--dragover');
});

$(document).on('drop', '.pbsg-branch-q-upload-zone', function (e) {
  e.preventDefault();
  e.stopPropagation();
  $(this).removeClass('pbsg-upload-zone--dragover');

  const stepIdx = parseInt($(this).data('step-idx'), 10);
  const qIdx = parseInt($(this).data('qidx'), 10);
  if (isNaN(stepIdx) || isNaN(qIdx)) return;

  const files = e.originalEvent.dataTransfer.files;
  if (!files || !files.length) return;

  const file = files[0];
  const maxSize = PBSG_ADMIN.maxUploadSize || 0;

  if (maxSize > 0 && file.size > maxSize) {
    const $zone = $(this);
    $zone.find('.pbsg-upload-error').remove();
    $zone.append(`<div class="pbsg-upload-error">File too large. Maximum size: ${PBSG_ADMIN.maxUploadLabel || 'unknown'}</div>`);
    return;
  }

  uploadBranchQuestionFileViaDrop(file, stepIdx, qIdx, $(this));
});




  $(document).on('click', '.pbsg-clear-branch-resource', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    if (isNaN(stepIdx)) return;

    const steps = getSteps().map(norm);
    const step = steps[stepIdx];
    if (!step || !step.branch) return;

    step.branch.tutorial_type = '';
    step.branch.tutorial_url = '';
    step.branch.tutorial_attachment_id = 0;
    step.branch.tutorial_file_name = '';
    step.branch.tutorial_file_url = '';

    setSteps(steps);
    renderStepCards();
  });

  $(document).on('click', '.pbsg-clear-branch-q-resource', function () {
    const stepIdx = parseInt($(this).data('step-idx'), 10);
    const qIdx = parseInt($(this).data('qidx'), 10);
    if (isNaN(stepIdx) || isNaN(qIdx)) return;

    syncBranchQuestionResource(stepIdx, qIdx, function (q) {
      q.tutorial_type = '';
      q.tutorial_url = '';
      q.tutorial_attachment_id = 0;
      q.tutorial_file_name = '';
      q.tutorial_file_url = '';
    });

    renderStepCards();
  });

  // ═══════════════════════════════════════════════════════════
  //  Use Existing H5P / Detach
  // ═══════════════════════════════════════════════════════════
  let pickIdx = null;
  $(document).on('click', '.pbsg-use-existing-h5p', function (e) {
    e.preventDefault();
    pickIdx = parseInt($(this).data('idx'), 10);
    if (isNaN(pickIdx)) return;
    $.post(PBSG_ADMIN.ajaxUrl, { action: 'pbsg_list_h5p', nonce: PBSG_ADMIN.nonce })
      .done(function (res) {
        if (res && res.success) {
          // Cache metadata for card rendering
          (res.data.items || []).forEach(function (item) {
            h5pMetaCache[item.id] = item;
          });
          openPicker(res.data.items || []);
        } else {
          alert(res && res.data ? res.data.message : 'Could not load H5P items.');
        }
      })
      .fail(function () { alert('Request failed while loading H5P items.'); });
  });

  function openPicker(items) {
    const opts = items.map(function (i) {
      return `<option value="${i.id}" data-author="${esc(i.author_name)}" data-owner="${i.is_owner}" data-usage="${i.usage_count}">${esc(i.title)} (#${i.id})</option>`;
    }).join('');

    if (!$('#pbsg-h5p-inline').length) {
      $('body').append('<div id="pbsg-h5p-inline" style="display:none;"></div>');
    }

    $('#pbsg-h5p-inline').html(`<div style="padding:14px;">
      <h2 style="margin-top:0;">Select an H5P quiz</h2>
      <p>Pick a quiz to link to this step, or use one as a template.</p>
      <select id="pbsg-h5p-select" style="width:100%;max-width:520px;">
        <option value="">&mdash; Select H5P &mdash;</option>
        ${opts}
      </select>
      <div id="pbsg-h5p-picker-info" class="pbsg-h5p-picker-info" style="display:none;margin-top:12px;"></div>
      <div style="margin-top:12px;display:flex;gap:8px;">
        <button type="button" class="button button-primary" id="pbsg-h5p-insert">Insert</button>
        <button type="button" class="button" id="pbsg-h5p-template" style="border-color:var(--pbsg-green,#517E1B);color:var(--pbsg-green,#517E1B);font-weight:600;">Use as Template</button>
        <button type="button" class="button" id="pbsg-h5p-cancel">Cancel</button>
      </div>
      <div class="pbsg-h5p-picker-help">
        <strong>Insert</strong> links to the original H5P (shared &mdash; edits affect all tutorials using it).<br>
        <strong>Use as Template</strong> creates your own copy with a new ID &mdash; safe to edit freely.
      </div>
    </div>`);

    tb_show('Select H5P', '#TB_inline?inlineId=pbsg-h5p-inline&width=640&height=400');

    // Show info panel on selection change
    $('#pbsg-h5p-select').on('change', function () {
      const $opt = $(this).find(':selected');
      const id = parseInt($opt.val(), 10);
      const $info = $('#pbsg-h5p-picker-info');

      if (!id) {
        $info.hide();
        return;
      }

      const meta = h5pMetaCache[id] || {};
      const usageBadge = (meta.usage_count || 0) >= 2
        ? '<span class="pbsg-h5p-badge pbsg-h5p-badge--usage-high">' + (meta.usage_count || 0) + ' tutorials</span>'
        : '<span class="pbsg-h5p-badge pbsg-h5p-badge--usage">' + (meta.usage_count || 0) + ' tutorial' + ((meta.usage_count || 0) !== 1 ? 's' : '') + '</span>';

      $info.html(`<div class="pbsg-h5p-picker-info__row">
        <span>Owner: <strong>${esc(meta.author_name || 'Unknown')}</strong></span>
        ${usageBadge}
      </div>`).show();
    });

    $('#pbsg-h5p-cancel').on('click', tb_remove);

    // Insert: link to original (existing behavior)
    $('#pbsg-h5p-insert').on('click', function () {
      const id = parseInt($('#pbsg-h5p-select').val(), 10);
      if (!id || pickIdx === null) return;
      const steps = getSteps().map(norm);
      if (steps[pickIdx]) {
        steps[pickIdx].h5p_id = id;
        steps[pickIdx].quiz = null;
        steps[pickIdx].h5p_cleared = false;
        setSteps(steps);
        renderStepCards();
      }
      tb_remove();
    });

    // Use as Template: duplicate then link
    $('#pbsg-h5p-template').on('click', function () {
      const id = parseInt($('#pbsg-h5p-select').val(), 10);
      if (!id || pickIdx === null) return;

      const $btn = $(this);
      $btn.prop('disabled', true).text('Duplicating...');

      $.post(PBSG_ADMIN.ajaxUrl, {
        action: 'pbsg_duplicate_h5p',
        nonce: PBSG_ADMIN.nonce,
        h5p_id: id,
        post_title: PBSG_ADMIN.postTitle,
        step_index: pickIdx + 1
      }).done(function (res) {
        if (res && res.success) {
          const newId = res.data.h5p_id;
          const newTitle = res.data.title;

          // Update cache with new entry
          h5pMetaCache[newId] = {
            id: newId,
            title: newTitle,
            user_id: PBSG_ADMIN.currentUserId,
            author_name: 'You',
            is_owner: true,
            usage_count: 0
          };

          const steps = getSteps().map(norm);
          if (steps[pickIdx]) {
            steps[pickIdx].h5p_id = newId;
            steps[pickIdx].quiz = null;
            steps[pickIdx].h5p_cleared = false;
            steps[pickIdx]._editing_h5p = false;
            setSteps(steps);
            renderStepCards();
          }
          tb_remove();
        } else {
          alert(res && res.data ? res.data.message : 'Failed to duplicate H5P content.');
          $btn.prop('disabled', false).text('Use as Template');
        }
      }).fail(function () {
        alert('Request failed while duplicating H5P content.');
        $btn.prop('disabled', false).text('Use as Template');
      });
    });
  }

  $(document).on('click', '.pbsg-create-inline-from-linked', function (e) {
    e.preventDefault();

    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;

    const steps = getSteps().map(norm);

    if (steps[idx]) {
      steps[idx].h5p_id = 0;
      steps[idx].h5p_cleared = false;
      steps[idx].quiz = {
        type: 'multichoice',
        question: '',
        answers: [
          { text: '', correct: true },
          { text: '', correct: false }
        ]
      };

      setSteps(steps);
      renderStepCards();
    }
  });

  $(document).on('click', '.pbsg-remove-h5p-link', function (e) {
    e.preventDefault();

    const idx = parseInt($(this).data('idx'), 10);
    if (isNaN(idx)) return;

    const steps = getSteps().map(norm);

    if (steps[idx]) {
      steps[idx].h5p_id = 0;
      steps[idx].quiz = null;
      steps[idx].h5p_cleared = true;
      delete steps[idx]._editing_h5p;

      setSteps(steps);
      renderStepCards();
    }
  });
  

  $(document).on('click', '.pbsg-edit-h5p', function (e) {
    e.preventDefault();
    const idx = parseInt($(this).data('idx'), 10);
    const h5pId = parseInt($(this).data('h5p-id'), 10);
    if (isNaN(idx) || isNaN(h5pId)) return;

    // If already disabled by ownership warning, do nothing
    if ($(this).hasClass('pbsg-edit-h5p--disabled')) return;

    const $card = $(`#pbsg-step-${idx}`);
    const $linked = $card.find('.pbsg-linked-h5p');
    const isOwner = $linked.data('h5p-owner');

    // Non-owner: show warning instead of editing
    if (isOwner === false) {
      const authorName = $linked.data('h5p-author') || 'Unknown user';
      const usageCount = $linked.data('h5p-usage') || 0;
      const usageText = usageCount > 0
        ? ` and used in <strong>${usageCount} other tutorial${usageCount > 1 ? 's' : ''}</strong>`
        : '';

      // Insert warning banner if not already present
      if (!$card.find('.pbsg-ownership-warning').length) {
        const warning = `<div class="pbsg-ownership-warning">
          <div class="pbsg-ownership-warning__title">${icon('warning', 'pbsg-icon--warn')} Cannot edit this quiz</div>
          <div class="pbsg-ownership-warning__body">This H5P is owned by <strong>${esc(authorName)}</strong>${usageText}. Contact the owner to make changes.</div>
          <div class="pbsg-ownership-warning__action">
            <a href="#" class="pbsg-use-as-template" data-idx="${idx}" data-h5p-id="${h5pId}">Use as Template</a>
            <span class="pbsg-ownership-warning__hint">&mdash; creates your own copy to edit freely</span>
          </div>
        </div>`;
        $linked.find('.pbsg-linked-h5p-actions').before(warning);
      }

      // Grey out the Edit H5P link
      $(this).addClass('pbsg-edit-h5p--disabled');
      return;
    }

    // Owner: proceed with inline editing (existing logic)
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

      steps[idx].quiz = quiz;
      steps[idx]._editing_h5p = true;
      setSteps(steps);
      collapsedSteps.delete(idx);
      renderStepCards();
    }).fail(function () {
      alert('Failed to fetch H5P content.');
      renderStepCards();
    });
  });

  // ── Use as Template from ownership warning ──
  $(document).on('click', '.pbsg-use-as-template', function (e) {
    e.preventDefault();
    const idx = parseInt($(this).data('idx'), 10);
    const h5pId = parseInt($(this).data('h5p-id'), 10);
    if (isNaN(idx) || isNaN(h5pId)) return;

    const $link = $(this);
    $link.text('Duplicating...').css('pointer-events', 'none');

    $.post(PBSG_ADMIN.ajaxUrl, {
      action: 'pbsg_duplicate_h5p',
      nonce: PBSG_ADMIN.nonce,
      h5p_id: h5pId,
      post_title: PBSG_ADMIN.postTitle,
      step_index: idx + 1
    }).done(function (res) {
      if (res && res.success) {
        const newId = res.data.h5p_id;
        const newTitle = res.data.title;

        h5pMetaCache[newId] = {
          id: newId,
          title: newTitle,
          user_id: PBSG_ADMIN.currentUserId,
          author_name: 'You',
          is_owner: true,
          usage_count: 0
        };

        const steps = getSteps().map(norm);
        if (steps[idx]) {
          steps[idx].h5p_id = newId;
          steps[idx].quiz = null;
          steps[idx].h5p_cleared = false;
          steps[idx]._editing_h5p = false;
          setSteps(steps);
          renderStepCards();
        }
      } else {
        alert(res && res.data ? res.data.message : 'Failed to duplicate H5P content.');
        $link.text('Use as Template').css('pointer-events', '');
      }
    }).fail(function () {
      alert('Request failed while duplicating H5P content.');
      $link.text('Use as Template').css('pointer-events', '');
    });
  });

  // ── H5P title lock/unlock toggle ──
  $(document).on('click', '.pbsg-h5p-lock-toggle', function (e) {
    e.preventDefault();
    e.stopPropagation();

    const idx = parseInt($(this).data('idx'), 10);
    const h5pId = parseInt($(this).data('h5p-id'), 10);
    const $row = $(this).closest('.pbsg-h5p-title-row');
    const $status = $row.siblings('.pbsg-h5p-title-status[data-idx="' + idx + '"]');

    if ($row.hasClass('pbsg-h5p-title-row--locked')) {
      // Unlock: switch to editable input
      const currentTitle = $(this).data('original-title');
      $row.removeClass('pbsg-h5p-title-row--locked').addClass('pbsg-h5p-title-row--unlocked');
      $row.find('.pbsg-h5p-title-text').replaceWith(
        `<input type="text" class="pbsg-h5p-title-input" value="${esc(currentTitle)}" data-idx="${idx}" data-h5p-id="${h5pId}" data-original="${esc(currentTitle)}">`
      );
      $(this).html(icon('lock-open')); // unlocked
      $row.find('.pbsg-h5p-title-input').focus().select();
    }
  });

  // ── H5P title input: debounced auto-save ──
  $(document).on('input', '.pbsg-h5p-title-input', function () {
    const $input = $(this);
    const $row = $input.closest('.pbsg-h5p-title-row');
    const idx = parseInt($input.data('idx'), 10);
    const $status = $row.siblings('.pbsg-h5p-title-status[data-idx="' + idx + '"]');
    const original = $input.data('original');
    const current = $input.val().trim();

    // Clear any pending save
    if (titleSaveTimer) clearTimeout(titleSaveTimer);

    if (current !== original && current !== '') {
      $status.attr('class', 'pbsg-h5p-title-status pbsg-h5p-title-status--unsaved').html(icon('warning', 'pbsg-icon--warn') + ' Unsaved changes');

      // Start 1.5s debounce
      titleSaveTimer = setTimeout(function () {
        saveTitleAndRelock($input);
      }, 1500);
    } else {
      $status.attr('class', 'pbsg-h5p-title-status').text('');
    }
  });

  // ── H5P title: blur = save (if changed), Escape = cancel ──
  $(document).on('blur', '.pbsg-h5p-title-input', function () {
    const $input = $(this);
    const original = $input.data('original');
    const current = $input.val().trim();

    if (titleSaveTimer) clearTimeout(titleSaveTimer);

    if (current !== original && current !== '') {
      saveTitleAndRelock($input);
    } else {
      relockTitle($input, original);
    }
  });

  $(document).on('keydown', '.pbsg-h5p-title-input', function (e) {
    if (e.key === 'Escape') {
      e.preventDefault();
      if (titleSaveTimer) clearTimeout(titleSaveTimer);
      const $input = $(this);
      relockTitle($input, $input.data('original'));
    } else if (e.key === 'Enter') {
      e.preventDefault();
      $(this).trigger('blur');
    }
  });

  /**
   * Save the H5P title via AJAX, then re-lock the field with visual confirmation.
   */
  function saveTitleAndRelock($input) {
    const h5pId = parseInt($input.data('h5p-id'), 10);
    const newTitle = $input.val().trim();
    const idx = parseInt($input.data('idx'), 10);
    const $row = $input.closest('.pbsg-h5p-title-row');
    const $status = $row.siblings('.pbsg-h5p-title-status[data-idx="' + idx + '"]');

    $.post(PBSG_ADMIN.ajaxUrl, {
      action: 'pbsg_rename_h5p',
      nonce: PBSG_ADMIN.nonce,
      h5p_id: h5pId,
      title: newTitle
    }).done(function (res) {
      if (res && res.success) {
        // Update cache
        if (h5pMetaCache[h5pId]) {
          h5pMetaCache[h5pId].title = newTitle;
        }

        // Visual: saved state
        $row.removeClass('pbsg-h5p-title-row--unlocked').addClass('pbsg-h5p-title-row--saved');
        $status.attr('class', 'pbsg-h5p-title-status pbsg-h5p-title-status--saved').html(icon('check', 'pbsg-icon--ok') + ' Saved');

        // Replace input with static text
        $input.replaceWith(`<span class="pbsg-h5p-title-text">${esc(newTitle)}</span>`);
        $row.find('.pbsg-h5p-lock-icon').html(icon('lock-closed')).data('original-title', newTitle);

        // Fade back to locked state after 2s
        setTimeout(function () {
          $row.removeClass('pbsg-h5p-title-row--saved').addClass('pbsg-h5p-title-row--locked');
          $status.attr('class', 'pbsg-h5p-title-status').text('');
        }, 2000);
      } else {
        alert(res && res.data ? res.data.message : 'Failed to rename H5P content.');
        relockTitle($input, $input.data('original'));
      }
    }).fail(function () {
      alert('Request failed while renaming H5P content.');
      relockTitle($input, $input.data('original'));
    });
  }

  /**
   * Cancel editing and re-lock the title field with original value.
   */
  function relockTitle($input, originalTitle) {
    const idx = parseInt($input.data('idx'), 10);
    const $row = $input.closest('.pbsg-h5p-title-row');
    const $status = $row.siblings('.pbsg-h5p-title-status[data-idx="' + idx + '"]');

    $row.removeClass('pbsg-h5p-title-row--unlocked pbsg-h5p-title-row--saved').addClass('pbsg-h5p-title-row--locked');
    $input.replaceWith(`<span class="pbsg-h5p-title-text">${esc(originalTitle)}</span>`);
    $row.find('.pbsg-h5p-lock-icon').html(icon('lock-closed'));
    $status.attr('class', 'pbsg-h5p-title-status').text('');
  }

  $(window).on('beforeunload.pbsgTitle', function () {
    if ($('.pbsg-h5p-title-input').length > 0) {
      const $input = $('.pbsg-h5p-title-input');
      const original = $input.data('original');
      const current = $input.val().trim();
      if (current !== original && current !== '') {
        return 'You have an unsaved H5P title change.';
      }
    }
  });

  // ═══════════════════════════════════════════════════════════
  //  Cover Image Picker
  // ═══════════════════════════════════════════════════════════
  $(document).on('click', '#pbsg_pick_cover_image', function (e) {
    e.preventDefault();
    const frame = wp.media({ title: 'Select Tutorial Cover Image (16:9, ~1600×900 px, <1 MB recommended)', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
    frame.on('select', function () { const a = frame.state().get('selection').first().toJSON(); $('#pbsg_cover_image_id').val(a.id || 0); $('#pbsg_cover_image_url').val(a.url || ''); $('#pbsg_cover_preview').attr('src', a.url || '').removeClass('pbsg-hidden'); });
    frame.open();
  });
  $(document).on('click', '#pbsg_clear_cover_image', function (e) { e.preventDefault(); $('#pbsg_cover_image_id').val(''); $('#pbsg_cover_image_url').val(''); $('#pbsg_cover_preview').attr('src', '').addClass('pbsg-hidden'); });

  // ═══════════════════════════════════════════════════════════
  //  Sync all forms before WP save
  // ═══════════════════════════════════════════════════════════
  // Issue 7d: Validate Fill in Blanks has at least one *blank* before save
  $(document).on('click', '#publish, #save-post', function (e) {
    // Sync intro description TinyMCE → textarea before WP serializes the form
    var introContent = pbsgGetTinyMCEContent('pbsg_intro_description');
    if (introContent !== null && introContent !== undefined) {
      $('#pbsg_intro_description').val(introContent);
    }
    const steps = getSteps().map(norm);
    let valid = true;
    const caseRiskStepNums = [];

    steps.forEach((s, idx) => {
      syncInstructions(idx);
      syncQuiz(idx);
      syncResource(idx);
      const step = getSteps().map(norm)[idx];
      if (!step || !step.quiz) return;

      // Validate Fill in Blanks has at least one *blank*
      if (step.quiz.type === 'blanks') {
        const sentence = step.quiz.sentence || '';
        const blanks = (sentence.match(/\*[^*]+\*/g) || []).length;
        if (sentence.trim().length > 0 && blanks === 0) {
          valid = false;
          // Expand the step card so user can see the error
          $(`#pbsg-step-${idx}`).removeClass('pbsg-step-card--collapsed');
          collapsedSteps.delete(idx);
          // Highlight the error
          $(`#pbsg-step-${idx} .pbsg-blanks-sentence`).css('border-color', '#8C2004');
          alert('Step ' + (idx + 1) + ': Fill in the Blanks requires at least one *blank* word wrapped in asterisks.');
        } else if (step.quiz.case_sensitive && blanks > 0 && blanksCaseSensitiveRiskyAnswers(sentence)) {
          caseRiskStepNums.push(idx + 1);
        }
      }
    });

    if (!valid) {
      e.preventDefault();
      e.stopImmediatePropagation();
      return false;
    }

    if (caseRiskStepNums.length) {
      const msg = 'Step(s) ' + caseRiskStepNums.join(', ') + ': Case sensitive Fill in the Blanks uses mixed-capitalization answers. Grading requires an exact character match, so students may be marked wrong if capitalization differs. Continue saving?';
      if (!window.confirm(msg)) {
        e.preventDefault();
        e.stopImmediatePropagation();
        return false;
      }
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
    var isVisible = $body.is(':visible');
    
    $body.toggle();
    $chevron.html(icon(isVisible ? 'chevron-right' : 'chevron-down'));
    $(this).attr('aria-expanded', !isVisible);
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
    var useDefault = $(this).is(':checked');

    if (useDefault) {
      // Reset slider to site default ratio
      var defaultRatio = window.PBSG_ADMIN.ratioDefault || 40;
      $ratioSlider.val(defaultRatio);
      updateRatioPreview();
      $ratioHidden.val('');
    } else {
      $ratioHidden.val(parseInt($ratioSlider.val(), 10));
    }

    $controls.css({
      opacity: useDefault ? 0.4 : 1,
      'pointer-events': useDefault ? 'none' : ''
    });
    markDirty();
  });

  // User-resizable checkbox also marks dirty
  $('#pbsg_user_resizable').on('change', markDirty);

  // ═══════════════════════════════════════════════════════════
  //  Dual-Pointer Benchmark Slider Component
  // ═══════════════════════════════════════════════════════════

  /**
   * Initialize a dual-pointer slider on a .pbsg-slider-wrap element.
   *
   * @param {HTMLElement} wrapEl  - The .pbsg-slider-wrap container
   * @param {Function}    onChange - Called with { low: Number, high: Number } on change
   */
  function pbsgDualSlider( wrapEl, onChange ) {
    var $wrap   = $( wrapEl );
    var $track  = $wrap.find( '.pbsg-slider-track' );
    var $thumbs = $wrap.find( '.pbsg-slider-thumb' );
    var $labels = $wrap.find( '.pbsg-slider-label input' );
    var $segs   = $wrap.find( '.pbsg-slider-seg' );

    var min     = parseInt( $wrap.attr( 'data-min' ), 10 ) || 0;
    var max     = parseInt( $wrap.attr( 'data-max' ), 10 ) || 100;
    var inverse = $wrap.attr( 'data-inverse' ) === '1';

    var $segFirst  = $segs.eq( 0 );
    var $segMiddle = $segs.eq( 1 );
    var $segLast   = $segs.eq( 2 );

    var $thumbLow  = $thumbs.eq( 0 );
    var $thumbHigh = $thumbs.eq( 1 );
    var $inputLow  = $labels.eq( 0 );
    var $inputHigh = $labels.eq( 1 );

    function valToPercent( v ) {
      return ( ( v - min ) / ( max - min ) ) * 100;
    }

    function percentToVal( p ) {
      return Math.round( min + ( p / 100 ) * ( max - min ) );
    }

    function clamp( v, lo, hi ) {
      return Math.max( lo, Math.min( hi, v ) );
    }

    function getLow()  { return parseInt( $inputLow.val(), 10 )  || min; }
    function getHigh() { return parseInt( $inputHigh.val(), 10 ) || min; }

    function render() {
      var low  = getLow();
      var high = getHigh();
      var pLow  = valToPercent( low );
      var pHigh = valToPercent( high );

      $segFirst.css( 'width', pLow + '%' );
      $segMiddle.css( 'width', ( pHigh - pLow ) + '%' );
      $segLast.css( 'width', ( 100 - pHigh ) + '%' );

      $thumbLow.css( 'left', pLow + '%' ).attr( 'aria-valuenow', low );
      $thumbHigh.css( 'left', pHigh + '%' ).attr( 'aria-valuenow', high );

      $wrap.find( '.pbsg-slider-label' ).eq( 0 ).css( 'left', pLow + '%' );
      $wrap.find( '.pbsg-slider-label' ).eq( 1 ).css( 'left', pHigh + '%' );
    }

    function fireChange() {
      if ( typeof onChange === 'function' ) {
        onChange( { low: getLow(), high: getHigh() } );
      }
    }

    // Drag handler
    function startDrag( e, isHighThumb ) {
      e.preventDefault();
      var trackRect = $track[0].getBoundingClientRect();

      function onMove( ev ) {
        var clientX = ev.touches ? ev.touches[0].clientX : ev.clientX;
        var pct = ( ( clientX - trackRect.left ) / trackRect.width ) * 100;
        pct = clamp( pct, 0, 100 );
        var val = percentToVal( pct );

        if ( isHighThumb ) {
          val = clamp( val, getLow() + 1, max );
          $inputHigh.val( val );
        } else {
          val = clamp( val, min, getHigh() - 1 );
          $inputLow.val( val );
        }
        render();
      }

      function onUp() {
        $( document ).off( 'mousemove touchmove', onMove );
        $( document ).off( 'mouseup touchend', onUp );
        fireChange();
      }

      $( document ).on( 'mousemove touchmove', onMove );
      $( document ).on( 'mouseup touchend', onUp );
    }

    $thumbLow.on( 'mousedown touchstart', function( e ) { startDrag( e, false ); } );
    $thumbHigh.on( 'mousedown touchstart', function( e ) { startDrag( e, true ); } );

    // Keyboard navigation (Arrow keys to adjust value by 1)
    function handleKeydown( e, isHighThumb ) {
      var step = 1;
      var current = isHighThumb ? getHigh() : getLow();
      var newVal = current;

      if ( e.key === 'ArrowRight' || e.key === 'ArrowUp' ) {
        newVal = current + step;
      } else if ( e.key === 'ArrowLeft' || e.key === 'ArrowDown' ) {
        newVal = current - step;
      } else {
        return;
      }
      e.preventDefault();

      if ( isHighThumb ) {
        newVal = clamp( newVal, getLow() + 1, max );
        $inputHigh.val( newVal );
      } else {
        newVal = clamp( newVal, min, getHigh() - 1 );
        $inputLow.val( newVal );
      }
      render();
      fireChange();
      ( isHighThumb ? $thumbHigh : $thumbLow ).attr( 'aria-valuenow', newVal );
    }

    $thumbLow.on( 'keydown', function( e ) { handleKeydown( e, false ); } );
    $thumbHigh.on( 'keydown', function( e ) { handleKeydown( e, true ); } );

    // Input edit handler
    $inputLow.on( 'change blur', function() {
      var v = clamp( parseInt( this.value, 10 ) || min, min, getHigh() - 1 );
      $inputLow.val( v );
      render();
      fireChange();
    } );

    $inputHigh.on( 'change blur', function() {
      var v = clamp( parseInt( this.value, 10 ) || min, getLow() + 1, max );
      $inputHigh.val( v );
      render();
      fireChange();
    } );

    // Public API for external reset
    wrapEl.pbsgSliderSetValues = function( low, high ) {
      $inputLow.val( clamp( low, min, max - 1 ) );
      $inputHigh.val( clamp( high, min + 1, max ) );
      render();
    };

    // Initial render
    render();
  }

  // ═══════════════════════════════════════════════════════════
  //  Benchmark Settings Section (Stretch Goal 5)
  // ═══════════════════════════════════════════════════════════

  // Collapsible toggle (delegated)
  $(document).on('click', '#pbsg-benchmark-toggle', function () {
    var $body = $('#pbsg-benchmark-body');
    var $chevron = $('#pbsg-benchmark-chevron');
    var isVisible = $body.is(':visible');
    
    $body.toggle();
    $chevron.html(icon(isVisible ? 'chevron-right' : 'chevron-down'));
    $(this).attr('aria-expanded', !isVisible);
  });

  // ═══════════════════════════════════════════════════════════
  //  Close Tutorial Behavior Section
  // ═══════════════════════════════════════════════════════════

  // Collapsible toggle (delegated — matches intro section pattern)
  $(document).on('click', '#pbsg-close-url-toggle', function () {
    var $body = $('#pbsg-close-url-body');
    var $chevron = $('#pbsg-close-url-chevron');
    var isVisible = $body.is(':visible');
    
    $body.toggle();
    $chevron.html(icon(isVisible ? 'chevron-right' : 'chevron-down'));
    $(this).attr('aria-expanded', !isVisible);
  });

  // Use-site-defaults checkbox toggles controls
  var $useSiteBench = $('#pbsg_use_site_benchmarks');
  var $benchControls = $('#pbsg_benchmark_controls');
  var $benchHidden   = $('#pbsg_benchmarks_json');

  $useSiteBench.on('change', function () {
    var useDefault = $(this).is(':checked');

    if (useDefault) {
      // Reset all sliders to site default values read from DOM data attributes
      $('#pbsg_benchmark_controls .pbsg-slider-wrap').each(function () {
        var low  = parseInt($(this).attr('data-default-low'), 10);
        var high = parseInt($(this).attr('data-default-high'), 10);
        if (!isNaN(low) && !isNaN(high) && this.pbsgSliderSetValues) {
          this.pbsgSliderSetValues(low, high);
        }
      });
      $benchHidden.val('');  // empty = use site defaults
    } else {
      syncBenchOverrides();
    }

    // Toggle disabled state
    $('#pbsg_benchmark_controls .pbsg-slider-wrap').toggleClass('pbsg-slider--disabled', useDefault);
    $benchControls.css({
      opacity: useDefault ? 0.4 : 1,
      'pointer-events': useDefault ? 'none' : ''
    });
    markDirty();
  });

  // Sync all benchmark override inputs → hidden JSON field
  function syncBenchOverrides() {
    var obj = {};
    var hasValue = false;
    $('#pbsg_benchmark_controls .pbsg-slider-wrap').each(function () {
      var $inputs = $(this).find('.pbsg-slider-label input');
      var keyLow  = $(this).attr('data-key-low');
      var keyHigh = $(this).attr('data-key-high');
      var valLow  = $inputs.eq(0).val();
      var valHigh = $inputs.eq(1).val();
      if (valLow !== '' && valLow !== undefined) {
        obj[keyLow] = parseInt(valLow, 10);
        hasValue = true;
      }
      if (valHigh !== '' && valHigh !== undefined) {
        obj[keyHigh] = parseInt(valHigh, 10);
        hasValue = true;
      }
    });
    $benchHidden.val(hasValue ? JSON.stringify(obj) : '');
  }

  $(document).on('input change', '.pbsg-bench-override, #pbsg_benchmark_controls .pbsg-slider-label input', function () {
    if (!$useSiteBench.is(':checked')) {
      syncBenchOverrides();
    }
    markDirty();
  });

  // Track dirty state on benchmark inputs
  $(document).on('input change', '#pbsg_use_site_benchmarks, .pbsg-bench-override', markDirty);

  // Initialize dual-pointer sliders on Guide Settings page
  $( '.pbsg-admin-settings-card .pbsg-slider-wrap' ).each( function() {
    pbsgDualSlider( this, function() {
      // Sync all slider values to the hidden JSON field
      var obj = {};
      $( '.pbsg-admin-settings-card .pbsg-slider-wrap' ).each( function() {
        var $inputs = $( this ).find( '.pbsg-slider-label input' );
        var keyLow  = $( this ).attr( 'data-key-low' );
        var keyHigh = $( this ).attr( 'data-key-high' );
        obj[ keyLow ]  = parseInt( $inputs.eq( 0 ).val(), 10 );
        obj[ keyHigh ] = parseInt( $inputs.eq( 1 ).val(), 10 );
      } );
      $( '#pbsg_benchmark_defaults_json' ).val( JSON.stringify( obj ) );
    } );
  } );

  // Initialize dual-pointer sliders on per-tutorial metabox
  $( '#pbsg_benchmark_controls .pbsg-slider-wrap' ).each( function() {
    pbsgDualSlider( this, function() {
      if ( !$useSiteBench.is( ':checked' ) ) {
        syncBenchOverrides();
      }
      markDirty();
    } );
  } );

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
        <h2 style="margin-top:0;">Save All as Template</h2>
        <p style="color:#50575e; margin-bottom:14px;">This will save all current settings, quiz steps, tutorial resources, and configuration as a reusable template for new tutorials.</p>
        <p style="margin:0 0 6px;"><strong>Template name <span style="color:#d63638;">*</span></strong></p>
        <input type="text" id="pbsg-tpl-name" style="width:100%;" placeholder="e.g. Library Catalogue Search" />
        <p style="margin:12px 0 6px;"><strong>Description</strong></p>
        <textarea id="pbsg-tpl-desc" style="width:100%; min-height:70px;" placeholder="Optional \u2014 describe when to use this template"></textarea>
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
    tb_show('Save All as Template', '#TB_inline?inlineId=pbsg-save-tpl-inline&width=560&height=380');

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

  // Initialize TinyMCE on the intro description textarea
  // (NOT using wp_editor() in PHP — it breaks Pressbooks footer script output)
  if (document.getElementById('pbsg_intro_description')) {
    pbsgInitTinyMCE('pbsg_intro_description');
  }

    // Pre-load H5P metadata for ownership rendering
    if (PBSG_ADMIN.h5pAvailable) {
      $.post(PBSG_ADMIN.ajaxUrl, { action: 'pbsg_list_h5p', nonce: PBSG_ADMIN.nonce })
        .done(function (res) {
          if (res && res.success) {
            (res.data.items || []).forEach(function (item) {
              h5pMetaCache[item.id] = item;
            });
            // Re-render cards now that we have ownership data
            renderStepCards();
          }
        });
    }

  // Snapshot initial state AFTER rendering so we have a clean baseline
  // (use setTimeout to let WP finish any post-load mutations)
  setTimeout(markClean, 300);
});