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

    if (isSplitGuideTemplateSelected()) {
      $box.show();
    } else {
      $box.hide();
    }
  }


  // Live toggle when template changes
  toggleMetaBox();
  $('#page_template').on('change', toggleMetaBox);




  // --- Steps Table Rendering ---
  function renderStepsTable() {
    const steps = getSteps();
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
      const url = escapeHtml(s.url || '');

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
            <input type="url" class="pbsg-step-url" value="${url}" style="width:100%;" placeholder="https://example.com/tutorial" />
          </td>
          <td>
            <button type="button" class="button link-delete pbsg-remove-step">Remove</button>
          </td>
        </tr>
      `;
      $tbody.append(row);
    });
  }

  function syncStepsFromTable() {
    const steps = [];
    $('#pbsg-steps-table tbody tr').each(function () {
      const $tr = $(this);

      // Skip the "No steps yet" placeholder row
      if ($tr.find('.pbsg-step-title').length === 0) return;

      steps.push({
        title: $tr.find('.pbsg-step-title').val() || '',
        h5p_id: parseInt($tr.find('.pbsg-step-h5p').val(), 10) || 0,
        url: $tr.find('.pbsg-step-url').val() || ''
      });
    });
    setSteps(steps);
  }

  // Initial render
  renderStepsTable();

  // Add step
  $('#pbsg-add-step').on('click', function () {
    const steps = getSteps();
    steps.push({ title: '', h5p_id: 0, url: '' });
    setSteps(steps);
    renderStepsTable();
  });

  // Remove step
  $(document).on('click', '.pbsg-remove-step', function () {
    const idx = parseInt($(this).closest('tr').attr('data-idx'), 10);
    const steps = getSteps();
    if (!isNaN(idx)) steps.splice(idx, 1);
    setSteps(steps);
    renderStepsTable();
  });

  // Keep JSON synced on typing
  $(document).on('input', '.pbsg-step-title, .pbsg-step-h5p, .pbsg-step-url', function () {
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

      // Update that row's H5P id input
      const $row = $(`#pbsg-steps-table tbody tr[data-idx="${currentPickRowIdx}"]`);
      $row.find('.pbsg-step-h5p').val(id);

      // Sync JSON
      syncStepsFromTable();

      tb_remove();
    });
  }

  $(document).on('click', '.pbsg-pick-h5p', function () {
    // Ensure template is selected (optional)
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
});
