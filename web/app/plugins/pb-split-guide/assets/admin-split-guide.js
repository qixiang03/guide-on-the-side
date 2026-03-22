jQuery(function ($) {
  // --- Utilities ---
  function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]);
    });
  }

  function safeParseJson(text, fallback) {
    try {
      const v = JSON.parse(text || '');
      return (v && Array.isArray(v)) ? v : (fallback || []);
    } catch (e) {
      return fallback || [];
    }
  }

  function getSteps() {
    return safeParseJson($('#pbsg_steps_json').val(), []);
  }

  function setSteps(steps) {
    $('#pbsg_steps_json').val(JSON.stringify(steps || []));
  }

    function getTemplateSelect() {
    return $('#page_template');
  }

  function isSplitGuideTemplateSelected() {
    const $template = getTemplateSelect();
    return $template.length > 0 && $template.val() === PBSG_ADMIN.templateSlug;
  }

  function toggleMetaBox() {
    const $box = $('#' + PBSG_ADMIN.metaBoxId).closest('.postbox');
    if ($box.length === 0) return;

    if (isSplitGuideTemplateSelected()) {
      $box.show();
    } else {
      $box.hide();
    }
  }

  function forceDefaultSplitGuideTemplate() {
    if (!PBSG_ADMIN.isNewPage) return false;

    const $template = getTemplateSelect();
    if ($template.length === 0) return false;

    const hasTargetOption = $template.find('option[value="' + PBSG_ADMIN.templateSlug + '"]').length > 0;
    if (!hasTargetOption) return false;

    if ($template.val() !== PBSG_ADMIN.templateSlug) {
      $template.val(PBSG_ADMIN.templateSlug).trigger('change');
    }

    toggleMetaBox();
    return true;
  }

  function setupDefaultTemplateWatcher() {
    $(document).on('change', '#page_template', function () {
      toggleMetaBox();
    });

    if (!PBSG_ADMIN.isNewPage) {
      toggleMetaBox();
      return;
    }

    let tries = 0;
    const maxTries = 40;

    const timer = setInterval(function () {
      tries++;
      const done = forceDefaultSplitGuideTemplate();

      if (done || tries >= maxTries) {
        clearInterval(timer);
        toggleMetaBox();
      }
    }, 250);

    $(window).on('load', function () {
      forceDefaultSplitGuideTemplate();
      toggleMetaBox();
    });

    // In case Pressbooks rebuilds the sidebar/dropdown later
    const observer = new MutationObserver(function () {
      forceDefaultSplitGuideTemplate();
      toggleMetaBox();
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  setupDefaultTemplateWatcher();

  // Normalize older data
  function normalizeStep(s) {
    const out = Object.assign({}, s || {});

    if (!out.tutorial_type && out.url) {
      out.tutorial_type = 'url';
      out.tutorial_url = out.url;
    }

    if (!out.tutorial_url) out.tutorial_url = '';
    if (!out.tutorial_attachment_id) out.tutorial_attachment_id = 0;
    if (!out.tutorial_file_name) out.tutorial_file_name = '';
    if (!out.tutorial_file_url) out.tutorial_file_url = '';

    // Branch defaults
    out.branch_mode = out.branch_mode || 'none';
    if (!['none', 'optional', 'mandatory'].includes(out.branch_mode)) {
      out.branch_mode = 'none';
    }

    out.branch_trigger_attempts = parseInt(out.branch_trigger_attempts, 10) || 1;
    if (out.branch_trigger_attempts < 1) out.branch_trigger_attempts = 1;

    if (!out.branch_title) out.branch_title = '';
    if (!out.branch_intro) out.branch_intro = '';

    if (!out.branch_tutorial_type) out.branch_tutorial_type = '';
    if (!out.branch_tutorial_url) out.branch_tutorial_url = '';
    if (!out.branch_tutorial_attachment_id) out.branch_tutorial_attachment_id = 0;
    if (!out.branch_tutorial_file_name) out.branch_tutorial_file_name = '';
    if (!out.branch_tutorial_file_url) out.branch_tutorial_file_url = '';

    return out;
  }

  function tutorialSummary(s) {
    s = normalizeStep(s);

    if (s.tutorial_type === 'url' && s.tutorial_url) {
      return 'URL selected';
    }

    if (s.tutorial_type === 'file' && (s.tutorial_file_name || s.tutorial_attachment_id)) {
      return s.tutorial_file_name
        ? `File: ${s.tutorial_file_name}`
        : 'File selected';
    }

    return '';
  }

  function branchSummary(s) {
    s = normalizeStep(s);

    if (s.branch_mode === 'none') return '';

    const mode = s.branch_mode === 'mandatory' ? 'Mandatory' : 'Optional';
    const count = s.branch_trigger_attempts;

    return `${mode} · after ${count} wrong ${count === 1 ? 'attempt' : 'attempts'}`;
  }

  // --- Steps Table Rendering ---
  function renderStepsTable() {
    const steps = getSteps().map(normalizeStep);
    const $tbody = $('#pbsg-steps-table tbody');

    if ($tbody.length === 0) return;

    $tbody.empty();

    if (steps.length === 0) {
      $tbody.append(`
        <tr>
          <td colspan="5" style="padding:12px; opacity:0.8;">
            No steps yet. Click <strong>Add Step</strong> to create one.
          </td>
        </tr>
      `);
      return;
    }

    steps.forEach((s, idx) => {
      const title = escapeHtml(s.title || '');
      const h5pId = s.h5p_id ? String(s.h5p_id) : '';

      const summary = escapeHtml(tutorialSummary(s));
      const branch = escapeHtml(branchSummary(s));

      const row = `
        <tr data-idx="${idx}">
          <td>
            <input type="text" class="pbsg-step-title" value="${title}" style="width:100%;" />
          </td>
          <td>
            <div style="display:flex; gap:6px; align-items:center;">
              <input type="number" min="1" class="pbsg-step-h5p" value="${escapeHtml(h5pId)}" style="width:100px;" />
              <button type="button" class="button pbsg-pick-h5p">Add H5P</button>
            </div>
          </td>
          <td>
            <div style="display:flex; gap:8px; align-items:center;">
              <div class="pbsg-tutorial-summary" style="flex:1; min-width:0; opacity:.9; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; ${summary ? '' : 'display:none;'}">
                <span>${summary}</span>
              </div>
              <button type="button" class="button pbsg-set-tutorial">Set Tutorial</button>
              <button type="button" class="button pbsg-clear-tutorial">Clear</button>
            </div>
          </td>
          <td>
            <div style="display:flex; gap:8px; align-items:center;">
              <div class="pbsg-branch-summary" style="flex:1; min-width:0; opacity:.9; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; ${branch ? '' : 'display:none;'}">
                <span>${branch}</span>
              </div>
              <button type="button" class="button pbsg-set-branch">Set Branch Review</button>
              <button type="button" class="button pbsg-clear-branch">Clear</button>
            </div>
          </td>
          <td>
            <button type="button" class="button link-delete pbsg-remove-step">Remove</button>
          </td>
        </tr>
      `;
      $tbody.append(row);
    });

    // Persist normalization back into JSON
    setSteps(steps);
  }

  function syncStepsFromTable() {
    const steps = getSteps().map(normalizeStep);

    $('#pbsg-steps-table tbody tr').each(function () {
      const $tr = $(this);
      if ($tr.find('.pbsg-step-title').length === 0) return;

      const idx = parseInt($tr.attr('data-idx'), 10);
      if (isNaN(idx) || !steps[idx]) return;

      steps[idx].title = $tr.find('.pbsg-step-title').val() || '';
      steps[idx].h5p_id = parseInt($tr.find('.pbsg-step-h5p').val(), 10) || 0;

      // Legacy mirror
      if (steps[idx].tutorial_type === 'url') steps[idx].url = steps[idx].tutorial_url || '';
      else steps[idx].url = '';
    });

    setSteps(steps);
  }

  renderStepsTable();

  // Add step
  $('#pbsg-add-step').on('click', function () {
    const steps = getSteps().map(normalizeStep);
    steps.push({
      title: '',
      h5p_id: 0,

      tutorial_type: '',
      tutorial_url: '',
      tutorial_attachment_id: 0,
      tutorial_file_name: '',
      tutorial_file_url: '',

      url: '', // legacy

      branch_mode: 'none',
      branch_trigger_attempts: 1,
      branch_title: '',
      branch_intro: '',
      branch_tutorial_type: '',
      branch_tutorial_url: '',
      branch_tutorial_attachment_id: 0,
      branch_tutorial_file_name: '',
      branch_tutorial_file_url: ''

    });
    setSteps(steps);
    renderStepsTable();
  });

  // Remove step
  $(document).on('click', '.pbsg-remove-step', function () {
    const idx = parseInt($(this).closest('tr').attr('data-idx'), 10);
    const steps = getSteps().map(normalizeStep);
    if (!isNaN(idx)) steps.splice(idx, 1);
    setSteps(steps);
    renderStepsTable();
  });

  // Keep JSON synced on typing
  $(document).on('input', '.pbsg-step-title, .pbsg-step-h5p', function () {
    syncStepsFromTable();
  });

  // --- H5P Picker (Thickbox) ---
  let currentPickRowIdx = null;

  function openH5PPicker(items) {
    const options = items.map(i =>
      `<option value="${i.id}">${escapeHtml(i.title)} (ID: ${i.id})</option>`
    ).join('');

    const html = `
      <div id="pbsg-h5p-modal" style="padding:14px;">
        <h2 style="margin-top:0;">Select an H5P quiz</h2>
        <p style="margin:8px 0 12px;">Pick a quiz to set the <strong>H5P ID</strong> for this step.</p>

        <select id="pbsg-h5p-select" style="width:100%; max-width:520px;">
          <option value="">— Select H5P —</option>
          ${options}
        </select>

        <div style="margin-top:12px; display:flex; gap:8px;">
          <button type="button" class="button button-primary" id="pbsg-h5p-insert">Insert</button>
          <button type="button" class="button" id="pbsg-h5p-cancel">Cancel</button>
        </div>
      </div>
    `;

    if (!$('#pbsg-h5p-inline').length) {
      $('body').append('<div id="pbsg-h5p-inline" style="display:none;"></div>');
    }
    $('#pbsg-h5p-inline').html(html);

    tb_show('Select H5P', '#TB_inline?inlineId=pbsg-h5p-inline&width=640&height=280');

    $('#pbsg-h5p-cancel').on('click', function () {
      tb_remove();
    });

    $('#pbsg-h5p-insert').on('click', function () {
      const id = $('#pbsg-h5p-select').val();
      if (!id || currentPickRowIdx === null) return;

      const $row = $(`#pbsg-steps-table tbody tr[data-idx="${currentPickRowIdx}"]`);
      $row.find('.pbsg-step-h5p').val(id);

      syncStepsFromTable();
      tb_remove();
    });
  }

  $(document).on('click', '.pbsg-pick-h5p', function () {
    if (!isSplitGuideTemplateSelected()) {
      alert('Please select the “Split Guide (H5P + Tutorial)” template first.');
      return;
    }

    const idx = parseInt($(this).closest('tr').attr('data-idx'), 10);
    if (isNaN(idx)) return;
    currentPickRowIdx = idx;

    $.post(PBSG_ADMIN.ajaxUrl, {
      action: 'pbsg_list_h5p',
      nonce: PBSG_ADMIN.nonce
    })
      .done(function (res) {
        if (!res || !res.success) {
          alert(res?.data?.message || 'Could not load H5P items.');
          return;
        }
        openH5PPicker(res.data.items || []);
      })
      .fail(function () {
        alert('Request failed while loading H5P items.');
      });
  });

  // --- Tutorial Picker (Thickbox + WP Media) ---
  let currentTutorialRowIdx = null;

  function openTutorialPicker(step) {
    step = normalizeStep(step);

    const html = `
      <div id="pbsg-tutorial-modal" style="padding:14px;">
        <h2 style="margin-top:0;">Set Tutorial Source</h2>

        <div style="margin:10px 0; display:flex; gap:16px;">
          <label style="display:flex; gap:6px; align-items:center;">
            <input type="radio" name="pbsg_tut_type" value="url" ${step.tutorial_type !== 'file' ? 'checked' : ''}/>
            URL
          </label>
          <label style="display:flex; gap:6px; align-items:center;">
            <input type="radio" name="pbsg_tut_type" value="file" ${step.tutorial_type === 'file' ? 'checked' : ''}/>
            Upload / Select File (PDF / slides)
          </label>
        </div>

        <div id="pbsg-tut-url-block" style="margin-top:10px;">
          <p style="margin:0 0 6px;"><strong>URL</strong></p>
          <input type="url" id="pbsg-tut-url" style="width:100%;" placeholder="https://example.com/tutorial" value="${escapeHtml(step.tutorial_url)}"/>
          <p style="margin:6px 0 0; opacity:.8;">Tip: YouTube watch links will be embedded automatically.</p>
        </div>

        <div id="pbsg-tut-file-block" style="margin-top:10px; display:none;">
          <p style="margin:0 0 6px;"><strong>File</strong></p>
          <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" class="button" id="pbsg-tut-pick-file">Choose / Upload File</button>
            <span id="pbsg-tut-file-label" style="opacity:.85;">${escapeHtml(step.tutorial_file_name || (step.tutorial_attachment_id ? ('Attachment #' + step.tutorial_attachment_id) : 'No file selected'))}</span>
          </div>
          <input type="hidden" id="pbsg-tut-attachment-id" value="${step.tutorial_attachment_id || 0}" />
          <input type="hidden" id="pbsg-tut-file-name" value="${escapeHtml(step.tutorial_file_name || '')}" />
          <input type="hidden" id="pbsg-tut-file-url" value="${escapeHtml(step.tutorial_file_url || '')}" />
          <p style="margin:6px 0 0; opacity:.8;">PDF can be embedded. Slides may open in a new tab depending on browser support.</p>
        </div>

        <div style="margin-top:14px; display:flex; gap:8px;">
          <button type="button" class="button button-primary" id="pbsg-tut-save">Save</button>
          <button type="button" class="button" id="pbsg-tut-cancel">Cancel</button>
        </div>
      </div>
    `;

    if (!$('#pbsg-tutorial-inline').length) {
      $('body').append('<div id="pbsg-tutorial-inline" style="display:none;"></div>');
    }
    $('#pbsg-tutorial-inline').html(html);

    tb_show('Set Tutorial', '#TB_inline?inlineId=pbsg-tutorial-inline&width=720&height=420');

    function refreshBlocks() {
      const t = $('input[name="pbsg_tut_type"]:checked').val();
      if (t === 'file') {
        $('#pbsg-tut-url-block').hide();
        $('#pbsg-tut-file-block').show();
      } else {
        $('#pbsg-tut-file-block').hide();
        $('#pbsg-tut-url-block').show();
      }
    }

    refreshBlocks();
    $(document).off('change.pbsgTut').on('change.pbsgTut', 'input[name="pbsg_tut_type"]', refreshBlocks);

    $('#pbsg-tut-cancel').on('click', function () {
      tb_remove();
    });

    // WP Media picker
    $('#pbsg-tut-pick-file').on('click', function (e) {
      e.preventDefault();

      const frame = wp.media({
        title: 'Select or Upload Tutorial File',
        button: { text: 'Use this file' },
        multiple: false
      });

      frame.on('select', function () {
        const attachment = frame.state().get('selection').first().toJSON();
        $('#pbsg-tut-attachment-id').val(attachment.id || 0);
        $('#pbsg-tut-file-name').val(attachment.filename || attachment.title || '');
        $('#pbsg-tut-file-url').val(attachment.url || '');
        $('#pbsg-tut-file-label').text(attachment.filename || attachment.title || ('Attachment #' + (attachment.id || '')));
      });

      frame.open();
    });

    $('#pbsg-tut-save').on('click', function () {
      if (currentTutorialRowIdx === null) return;

      const steps = getSteps().map(normalizeStep);
      const step = steps[currentTutorialRowIdx];
      if (!step) return;

      const t = $('input[name="pbsg_tut_type"]:checked').val();

      if (t === 'file') {
        const attId = parseInt($('#pbsg-tut-attachment-id').val(), 10) || 0;
        const fileName = $('#pbsg-tut-file-name').val() || '';
        const fileUrl  = $('#pbsg-tut-file-url').val() || '';

        step.tutorial_type = attId ? 'file' : '';
        step.tutorial_attachment_id = attId;
        step.tutorial_file_name = fileName;
        step.tutorial_file_url = fileUrl;

        // Clear URL if file
        step.tutorial_url = '';
        step.url = '';
      } else {
        const url = $('#pbsg-tut-url').val() || '';
        step.tutorial_type = url ? 'url' : '';
        step.tutorial_url = url;
        step.url = url; // legacy mirror

        // Clear file if url
        step.tutorial_attachment_id = 0;
        step.tutorial_file_name = '';
        step.tutorial_file_url = '';
      }

      steps[currentTutorialRowIdx] = step;
      setSteps(steps);

      renderStepsTable();
      tb_remove();
    });
  }


  let currentBranchRowIdx = null;

  function openBranchPicker(step) {
    step = normalizeStep(step);

    const html = `
      <div id="pbsg-branch-modal" style="padding:14px;">
        <h2 style="margin-top:0;">Set Wrong-Answer Branch Review</h2>

        <p style="margin:0 0 12px;">
          This sub-tutorial will appear only if the student answers this question incorrectly.
        </p>

      <div style="margin:12px 0;">
        <p><strong>Branch mode</strong></p>

        <label>
          <input type="radio" name="pbsg_branch_mode" value="none"
            ${step.branch_mode === 'none' ? 'checked' : ''} />
          None
        </label>

        <label>
          <input type="radio" name="pbsg_branch_mode" value="optional"
            ${step.branch_mode === 'optional' ? 'checked' : ''} />
          Optional
        </label>

        <label>
          <input type="radio" name="pbsg_branch_mode" value="mandatory"
            ${step.branch_mode === 'mandatory' ? 'checked' : ''} />
          Mandatory
        </label>
      </div>

        <div style="margin:10px 0;">
          <p style="margin:0 0 6px;"><strong>Branch title</strong></p>
          <input type="text" id="pbsg-branch-title" style="width:100%;" value="${escapeHtml(step.branch_title)}" placeholder="Need a quick review?" />
        </div>

        <div style="margin:10px 0;">
          <p style="margin:0 0 6px;"><strong>Instruction text</strong></p>
          <textarea id="pbsg-branch-intro" style="width:100%; min-height:90px;" placeholder="You answered this question incorrectly. Review this help content before continuing.">${escapeHtml(step.branch_intro)}</textarea>
       
          <div style="margin:10px 0;">
            <p><strong>Show branch after this many incorrect answers</strong></p>
            <input type="number"
              id="pbsg-branch-trigger-attempts"
              min="1"
              value="${step.branch_trigger_attempts || 1}"
              style="width:100px;" />
        </div>
       
        </div>

        <hr style="margin:16px 0;" />

        <div style="margin:10px 0; display:flex; gap:16px;">
          <label style="display:flex; gap:6px; align-items:center;">
            <input type="radio" name="pbsg_branch_tut_type" value="url" ${step.branch_tutorial_type !== 'file' ? 'checked' : ''} />
            URL
          </label>
          <label style="display:flex; gap:6px; align-items:center;">
            <input type="radio" name="pbsg_branch_tut_type" value="file" ${step.branch_tutorial_type === 'file' ? 'checked' : ''} />
            Upload / Select File
          </label>
        </div>

        <div id="pbsg-branch-url-block" style="margin-top:10px;">
          <p style="margin:0 0 6px;"><strong>Branch URL</strong></p>
          <input type="url" id="pbsg-branch-url" style="width:100%;" placeholder="https://example.com/review" value="${escapeHtml(step.branch_tutorial_url)}" />
        </div>

        <div id="pbsg-branch-file-block" style="margin-top:10px; display:none;">
          <p style="margin:0 0 6px;"><strong>Branch file</strong></p>
          <div style="display:flex; gap:8px; align-items:center;">
            <button type="button" class="button" id="pbsg-branch-pick-file">Choose / Upload File</button>
            <span id="pbsg-branch-file-label" style="opacity:.85;">${escapeHtml(step.branch_tutorial_file_name || (step.branch_tutorial_attachment_id ? ('Attachment #' + step.branch_tutorial_attachment_id) : 'No file selected'))}</span>
          </div>
          <input type="hidden" id="pbsg-branch-attachment-id" value="${step.branch_tutorial_attachment_id || 0}" />
          <input type="hidden" id="pbsg-branch-file-name" value="${escapeHtml(step.branch_tutorial_file_name || '')}" />
          <input type="hidden" id="pbsg-branch-file-url" value="${escapeHtml(step.branch_tutorial_file_url || '')}" />
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
      if (t === 'file') {
        $('#pbsg-branch-url-block').hide();
        $('#pbsg-branch-file-block').show();
      } else {
        $('#pbsg-branch-file-block').hide();
        $('#pbsg-branch-url-block').show();
      }
    }

    refreshBranchBlocks();
    $(document).off('change.pbsgBranchType').on('change.pbsgBranchType', 'input[name="pbsg_branch_tut_type"]', refreshBranchBlocks);

    $('#pbsg-branch-cancel').on('click', function () {
      tb_remove();
    });

    $('#pbsg-branch-pick-file').on('click', function (e) {
      e.preventDefault();

      const frame = wp.media({
        title: 'Select or Upload Branch Review File',
        button: { text: 'Use this file' },
        multiple: false
      });

      frame.on('select', function () {
        const attachment = frame.state().get('selection').first().toJSON();
        $('#pbsg-branch-attachment-id').val(attachment.id || 0);
        $('#pbsg-branch-file-name').val(attachment.filename || attachment.title || '');
        $('#pbsg-branch-file-url').val(attachment.url || '');
        $('#pbsg-branch-file-label').text(attachment.filename || attachment.title || ('Attachment #' + (attachment.id || '')));
      });

      frame.open();
    });

    $('#pbsg-branch-save').on('click', function () {
      if (currentBranchRowIdx === null) return;

      const steps = getSteps().map(normalizeStep);
      const step = steps[currentBranchRowIdx];
      if (!step) return;

      step.branch_mode = $('input[name="pbsg_branch_mode"]:checked').val() || 'none';
      step.branch_trigger_attempts = parseInt($('#pbsg-branch-trigger-attempts').val(), 10) || 1;
      if (step.branch_trigger_attempts < 1) step.branch_trigger_attempts = 1;

      step.branch_title = $('#pbsg-branch-title').val() || '';
      step.branch_intro = $('#pbsg-branch-intro').val() || '';

      const t = $('input[name="pbsg_branch_tut_type"]:checked').val();

      if (t === 'file') {
        const attId = parseInt($('#pbsg-branch-attachment-id').val(), 10) || 0;
        step.branch_tutorial_type = attId ? 'file' : '';
        step.branch_tutorial_attachment_id = attId;
        step.branch_tutorial_file_name = $('#pbsg-branch-file-name').val() || '';
        step.branch_tutorial_file_url = $('#pbsg-branch-file-url').val() || '';
        step.branch_tutorial_url = '';
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
      renderStepsTable();
      tb_remove();
    });
  }



  $(document).on('click', '.pbsg-set-tutorial', function () {
    if (!isSplitGuideTemplateSelected()) {
      alert('Please select the “Split Guide (H5P + Tutorial)” template first.');
      return;
    }
    const idx = parseInt($(this).closest('tr').attr('data-idx'), 10);
    if (isNaN(idx)) return;

    currentTutorialRowIdx = idx;
    const steps = getSteps().map(normalizeStep);
    openTutorialPicker(steps[idx] || {});
  });

  $(document).on('click', '.pbsg-clear-tutorial', function () {
    const idx = parseInt($(this).closest('tr').attr('data-idx'), 10);
    if (isNaN(idx)) return;

    const steps = getSteps().map(normalizeStep);
    if (!steps[idx]) return;

    steps[idx].tutorial_type = '';
    steps[idx].tutorial_url = '';
    steps[idx].tutorial_attachment_id = 0;
    steps[idx].tutorial_file_name = '';
    steps[idx].tutorial_file_url = '';
    steps[idx].url = '';

    setSteps(steps);
    renderStepsTable();
  });


  $(document).on('click', '.pbsg-set-branch', function () {
    if (!isSplitGuideTemplateSelected()) {
      alert('Please select the “Split Guide (H5P + Tutorial)” template first.');
      return;
    }

    const idx = parseInt($(this).closest('tr').attr('data-idx'), 10);
    if (isNaN(idx)) return;

    currentBranchRowIdx = idx;
    const steps = getSteps().map(normalizeStep);
    openBranchPicker(steps[idx] || {});
  });

  $(document).on('click', '.pbsg-clear-branch', function () {
    const idx = parseInt($(this).closest('tr').attr('data-idx'), 10);
    if (isNaN(idx)) return;

    const steps = getSteps().map(normalizeStep);
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
    renderStepsTable();
  });

  // --- Page-level cover image picker ---
  $(document).on('click', '#pbsg_pick_cover_image', function (e) {
    e.preventDefault();

    const frame = wp.media({
      title: 'Select Tutorial Cover Image',
      button: { text: 'Use this image' },
      library: { type: 'image' },
      multiple: false
    });

    frame.on('select', function () {
      const attachment = frame.state().get('selection').first().toJSON();
      const imageUrl = attachment.url || '';
      const imageId = attachment.id || 0;

      $('#pbsg_cover_image_id').val(imageId);
      $('#pbsg_cover_image_url').val(imageUrl);

      $('#pbsg_cover_preview')
        .attr('src', imageUrl)
        .show();
    });

    frame.open();
  });

  $(document).on('click', '#pbsg_clear_cover_image', function (e) {
    e.preventDefault();

    $('#pbsg_cover_image_id').val('');
    $('#pbsg_cover_image_url').val('');
    $('#pbsg_cover_preview')
      .attr('src', '')
      .hide();
  });


    // --- Introduction placeholder for the main editor ---
  function setupIntroductionPlaceholder() {
    const placeholderText = 'Add introduction';

    function getEditorWrap() {
      return $('#wp-content-wrap');
    }

    function getEditorContainer() {
      return $('#wp-content-editor-container');
    }

    function getTextarea() {
      return $('#content');
    }

    function ensurePlaceholderEl() {
      const $container = getEditorContainer();
      if (!$container.length) return $();

      if (!$container.find('.pbsg-editor-placeholder').length) {
        $container.css('position', 'relative');
        $container.append(
          '<div class="pbsg-editor-placeholder">' + placeholderText + '</div>'
        );
      }

      return $container.find('.pbsg-editor-placeholder');
    }

    function editorIsEmpty() {
      if (typeof tinymce !== 'undefined') {
        const editor = tinymce.get('content');
        if (editor && !editor.isHidden()) {
          const text = (editor.getContent({ format: 'text' }) || '')
            .replace(/\u00a0/g, ' ')
            .trim();

          const html = (editor.getContent() || '')
            .replace(/<p>(?:\s|&nbsp;|<br[^>]*\/?>)*<\/p>/gi, '')
            .replace(/&nbsp;/gi, '')
            .trim();

          return text === '' && html === '';
        }
      }

      const val = (getTextarea().val() || '').trim();
      return val === '';
    }

    function updatePlaceholder() {
      const $ph = ensurePlaceholderEl();
      if (!$ph.length) return;

      const isVisual = getEditorWrap().hasClass('tmce-active');
      const empty = editorIsEmpty();

      if (isVisual && empty) {
        $ph.show();
      } else {
        $ph.hide();
      }
    }

    function bindEvents() {
      const $container = getEditorContainer();
      if (!$container.length) return;

      ensurePlaceholderEl();

      // click placeholder -> focus editor
      $container.off('click.pbsgPlaceholder').on('click.pbsgPlaceholder', '.pbsg-editor-placeholder', function () {
        const editor = (typeof tinymce !== 'undefined') ? tinymce.get('content') : null;
        if (editor) {
          editor.focus();
        } else {
          getTextarea().trigger('focus');
        }
      });

      // switch Visual / Code tabs
      $(document).on('click.pbsgPlaceholder', '#content-tmce, #content-html', function () {
        setTimeout(updatePlaceholder, 100);
      });

      // textarea mode
      getTextarea().on('input.pbsgPlaceholder change.pbsgPlaceholder', function () {
        updatePlaceholder();
      });

      // TinyMCE mode
      const tryBindTinyMCE = function () {
        if (typeof tinymce === 'undefined') return false;
        const editor = tinymce.get('content');
        if (!editor) return false;

        if (!editor._pbsgPlaceholderBound) {
          editor._pbsgPlaceholderBound = true;

          editor.on('init', updatePlaceholder);
          editor.on('focus', function () {
            ensurePlaceholderEl().hide();
          });
          editor.on('blur', updatePlaceholder);
          editor.on('keyup change SetContent input NodeChange Undo Redo', updatePlaceholder);
        }

        return true;
      };

      let tries = 0;
      const timer = setInterval(function () {
        tries++;
        const ok = tryBindTinyMCE();
        updatePlaceholder();

        if (ok || tries >= 40) {
          clearInterval(timer);
        }
      }, 300);

      $(window).on('load', function () {
        setTimeout(updatePlaceholder, 300);
      });
    }

    bindEvents();
    setTimeout(updatePlaceholder, 300);
  }

  setupIntroductionPlaceholder();

});





