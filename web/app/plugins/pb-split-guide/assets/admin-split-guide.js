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

  function isSplitGuideTemplateSelected() {
    return $('#page_template').val() === PBSG_ADMIN.templateSlug;
  }

  function toggleMetaBox() {
    const $box = $('#' + PBSG_ADMIN.metaBoxId).closest('.postbox');
    if ($box.length === 0) return;

    if (isSplitGuideTemplateSelected()) $box.show();
    else $box.hide();
  }

  toggleMetaBox();
  $('#page_template').on('change', toggleMetaBox);

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
    return out;
  }

  function tutorialSummary(s) {
    s = normalizeStep(s);
    if (s.tutorial_type === 'url' && s.tutorial_url) return `${s.tutorial_url}`;
    if (s.tutorial_type === 'file' && (s.tutorial_file_name || s.tutorial_attachment_id)) {
      const name = s.tutorial_file_name ? s.tutorial_file_name : `Attachment #${s.tutorial_attachment_id}`;
      return `File: ${name}`;
    }
    return 'No tutorial selected';
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
          <td colspan="4" style="padding:12px; opacity:0.8;">
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
              <div class="pbsg-tutorial-summary" style="flex:1; min-width:0; opacity:.9;">
                <span>${summary}</span>
              </div>
              <button type="button" class="button pbsg-set-tutorial">Set Tutorial</button>
              <button type="button" class="button pbsg-clear-tutorial">Clear</button>
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

      url: '' // legacy
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
});


