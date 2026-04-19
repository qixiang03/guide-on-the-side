/**
 * Cross-Edit & Ownership Transfer — Admin JS
 *
 * Handles:
 *  - Settings page confirmation popups for toggle changes
 *  - Transfer ownership modal (My Tutorials + editor)
 *  - My Tutorials tab switching
 */
(function ($) {
  'use strict';

  /* ================================================================
     Section 1: Settings page — confirmation on toggle change
     ================================================================ */

  var confirmMessages = {
    cross_edit_on: {
      variant: 'default',
      iconKey: 'lockClosed',
      heading: 'Enable cross-editing?',
      subtitle: 'Librarians will gain new edit permissions across the library.',
      bodyLabel: 'What this changes',
      bullets: [
        'All librarians can <strong>edit</strong> tutorials created by other librarians.',
        "Librarians <strong>cannot delete</strong> or change the publish status of tutorials they don't own.",
        'Administrators continue to have full access — this setting does not affect them.'
      ],
      confirmLabel: 'Enable cross-editing'
    },
    cross_edit_off: {
      variant: 'neutral',
      iconKey: 'lockOpen',
      heading: 'Disable cross-editing?',
      subtitle: "Librarians will lose access to tutorials they don't own.",
      bodyLabel: 'What this changes',
      bullets: [
        'Librarians will only be able to edit <strong>their own</strong> tutorials.',
        "Other librarians' tutorials will be <strong>hidden from their tutorial list</strong>.",
        'Administrators retain full access to all tutorials.'
      ],
      caveat: "<strong>Heads up:</strong> any unsaved changes by librarians currently editing another librarian's tutorial will be lost when they next load the page.",
      confirmLabel: 'Disable cross-editing'
    },
    transfer_on: {
      variant: 'default',
      iconKey: 'shuffle',
      heading: 'Enable ownership transfer?',
      subtitle: 'Librarians will be able to hand off tutorials to other librarians.',
      bodyLabel: 'What this changes',
      bullets: [
        'Librarians can transfer ownership of <strong>their own</strong> tutorials to other librarians or administrators.',
        'The new owner gains full control (edit, publish, delete).',
        'Administrators can still reassign ownership regardless of this setting.'
      ],
      confirmLabel: 'Enable ownership transfer'
    },
    transfer_off: {
      variant: 'neutral',
      iconKey: 'shuffle',
      heading: 'Disable ownership transfer?',
      subtitle: 'Librarians will no longer be able to transfer their tutorials.',
      bodyLabel: 'What this changes',
      bullets: [
        'Librarians will <strong>no longer</strong> be able to transfer their tutorials to other librarians.',
        'Administrators can still reassign tutorial ownership at any time.'
      ],
      confirmLabel: 'Disable ownership transfer'
    }
  };

  function initSettingsConfirmation() {
    var $form = $('form[action$="options.php"]');
    if (!$form.length) return;

    var $crossEdit = $('#pbsg_cross_edit_toggle');
    var $transfer  = $('#pbsg_transfer_toggle');
    if (!$crossEdit.length && !$transfer.length) return;

    // Each item pairs a confirm-message config with the toggle it describes
    // so that cancelling the modal can revert just that toggle independently.
    function changedItems() {
      var out = [];

      if ($crossEdit.length) {
        var cOrig = $crossEdit.data('original') === 1 || $crossEdit.data('original') === '1';
        var cNow  = $crossEdit.is(':checked');
        if (cNow !== cOrig) {
          out.push({
            cfg:      cNow ? confirmMessages.cross_edit_on : confirmMessages.cross_edit_off,
            $toggle:  $crossEdit,
            original: cOrig
          });
        }
      }
      if ($transfer.length) {
        var tOrig = $transfer.data('original') === 1 || $transfer.data('original') === '1';
        var tNow  = $transfer.is(':checked');
        if (tNow !== tOrig) {
          out.push({
            cfg:      tNow ? confirmMessages.transfer_on : confirmMessages.transfer_off,
            $toggle:  $transfer,
            original: tOrig
          });
        }
      }
      return out;
    }

    $form.on('submit', function (e) {
      var items = changedItems();
      if (!items.length) return;  // nothing permission-related changed — let WP save

      e.preventDefault();
      presentChain(items, 0, $form);
    });

    function presentChain(items, index, $formRef) {
      if (index >= items.length) {
        // All decisions made. Submit only if at least one toggle is still changed
        // (i.e. was confirmed rather than cancelled). Cancelled toggles were
        // reverted to their original state inside onCancel.
        var anyStillChanged = items.some(function (it) {
          return it.$toggle.is(':checked') !== it.original;
        });
        if (!anyStillChanged) return;

        // The form has <input name="submit"> (from WP's submit_button()) which
        // shadows the native form.submit method. Call the prototype method
        // directly to bypass the shadow. Native .submit() also skips the
        // 'submit' event, so our own handler isn't re-entered.
        var formEl = $formRef[0];
        if (formEl) {
          HTMLFormElement.prototype.submit.call(formEl);
        }
        return;
      }
      var item = items[index];
      var cfg  = item.cfg;
      window.PbsgModal.open({
        variant:     cfg.variant,
        icon:        (window.pbsgModalIcons && window.pbsgModalIcons[cfg.iconKey]) || '',
        heading:     cfg.heading,
        subtitle:    cfg.subtitle,
        bodyLabel:   cfg.bodyLabel,
        bullets:     cfg.bullets,
        caveat:      cfg.caveat,
        confirmLabel: cfg.confirmLabel,
        onConfirm: function () {
          // Keep the new state; proceed to the next toggle's modal.
          presentChain(items, index + 1, $formRef);
        },
        onCancel:  function () {
          // Revert THIS toggle to its original value, then still ask about
          // the remaining toggles — each decision is independent.
          item.$toggle.prop('checked', item.original);
          presentChain(items, index + 1, $formRef);
        }
      });
    }
  }

  /* ================================================================
     Section 2: Transfer Ownership Modal
     ================================================================ */

  var $overlay = null;
  var pendingPostIds = [];
  var pendingPostTitles = [];

  function createTransferModal() {
    if ($overlay) return;

    var html = '<div class="pbsg-transfer-overlay" id="pbsg-transfer-overlay">' +
      '<div class="pbsg-transfer-modal">' +
        '<h3 id="pbsg-transfer-title">Transfer Ownership</h3>' +
        '<p id="pbsg-transfer-description"></p>' +
        '<div style="margin-top:14px;">' +
          '<label for="pbsg-transfer-target" style="font-weight:600;display:block;margin-bottom:6px;">New Owner</label>' +
          '<select id="pbsg-transfer-target" style="width:100%;"></select>' +
        '</div>' +
        '<div class="pbsg-transfer-modal__actions">' +
          '<button type="button" class="button" id="pbsg-transfer-cancel">Cancel</button>' +
          '<button type="button" class="button button-primary" id="pbsg-transfer-confirm" disabled>Transfer</button>' +
        '</div>' +
      '</div>' +
    '</div>';

    $('body').append(html);
    $overlay = $('#pbsg-transfer-overlay');

    $('#pbsg-transfer-cancel').on('click', closeTransferModal);
    $overlay.on('click', function (e) {
      if (e.target === this) closeTransferModal();
    });

    $('#pbsg-transfer-target').on('change', function () {
      $('#pbsg-transfer-confirm').prop('disabled', !$(this).val());
    });

    $('#pbsg-transfer-confirm').on('click', function () {
      var targetId = $('#pbsg-transfer-target').val();
      var targetName = $('#pbsg-transfer-target option:selected').text();

      if (!targetId) return;

      var confirmMsg;
      if (pendingPostIds.length === 1) {
        confirmMsg = 'Transfer ownership of \'' + pendingPostTitles[0] + '\' to ' + targetName + '? ' +
          'They will become the author and have full control including publishing and deleting this tutorial. ' +
          'This action cannot be undone.';
      } else {
        confirmMsg = 'Transfer ownership of ' + pendingPostIds.length + ' tutorials to ' + targetName + '? ' +
          'They will become the author of all selected tutorials with full control including publishing and deleting. ' +
          'This action cannot be undone.';
      }

      if (!window.confirm(confirmMsg)) return;

      $('#pbsg-transfer-confirm').prop('disabled', true).text('Transferring…');

      $.post(pbsgCrossEdit.ajaxUrl, {
        action: 'pbsg_transfer_ownership',
        _wpnonce: pbsgCrossEdit.nonce,
        post_ids: pendingPostIds,
        new_owner_id: targetId
      })
      .done(function (resp) {
        if (resp.success) {
          window.alert(resp.data.message);
          window.location.reload();
        } else {
          window.alert('Error: ' + (resp.data.message || 'Transfer failed.'));
          $('#pbsg-transfer-confirm').prop('disabled', false).text('Transfer');
        }
      })
      .fail(function () {
        window.alert('Network error. Please try again.');
        $('#pbsg-transfer-confirm').prop('disabled', false).text('Transfer');
      });
    });
  }

  function openTransferModal(postIds, postTitles) {
    createTransferModal();
    pendingPostIds = postIds;
    pendingPostTitles = postTitles;

    var desc;
    if (postIds.length === 1) {
      desc = 'Select a new owner for "<strong>' + $('<span>').text(postTitles[0]).html() + '</strong>".';
    } else {
      desc = 'Select a new owner for <strong>' + postIds.length + ' tutorials</strong>.';
    }
    $('#pbsg-transfer-description').html(desc);

    // Load targets
    var $select = $('#pbsg-transfer-target').html('<option value="">Loading…</option>');
    $('#pbsg-transfer-confirm').prop('disabled', true).text('Transfer');

    $.post(pbsgCrossEdit.ajaxUrl, {
      action: 'pbsg_get_transfer_targets',
      _wpnonce: pbsgCrossEdit.nonce
    })
    .done(function (resp) {
      if (resp.success && resp.data.targets) {
        var options = '<option value="">— Select a user —</option>';
        $.each(resp.data.targets, function (_i, user) {
          options += '<option value="' + user.ID + '">' + $('<span>').text(user.display_name).html() + '</option>';
        });
        $select.html(options);
      } else {
        $select.html('<option value="">No eligible users found</option>');
      }
    })
    .fail(function () {
      $select.html('<option value="">Error loading users</option>');
    });

    $overlay.addClass('pbsg-transfer-overlay--active');
  }

  function closeTransferModal() {
    if ($overlay) {
      $overlay.removeClass('pbsg-transfer-overlay--active');
    }
    pendingPostIds = [];
    pendingPostTitles = [];
  }

  // Expose for inline/bulk row actions
  window.pbsgOpenTransferModal = openTransferModal;

  /* ================================================================
     Section 3: My Tutorials Page
     ================================================================ */

  function initMyTutorialsPage() {
    // Single transfer button
    $(document).on('click', '.pbsg-transfer-single', function () {
      var postId = $(this).data('post-id');
      var postTitle = $(this).data('post-title');
      openTransferModal([postId], [postTitle]);
    });

    // Select all checkbox
    $('#pbsg-select-all-tutorials').on('change', function () {
      var checked = $(this).is(':checked');
      $('.pbsg-tutorial-checkbox').prop('checked', checked);
      updateBulkButton();
    });

    // Individual checkboxes
    $(document).on('change', '.pbsg-tutorial-checkbox', function () {
      updateBulkButton();
    });

    // Bulk transfer button
    $('#pbsg-bulk-transfer').on('click', function () {
      var ids = [];
      var titles = [];
      $('.pbsg-tutorial-checkbox:checked').each(function () {
        ids.push($(this).val());
        titles.push($(this).data('title'));
      });
      if (ids.length > 0) {
        openTransferModal(ids, titles);
      }
    });

    function updateBulkButton() {
      var count = $('.pbsg-tutorial-checkbox:checked').length;
      $('#pbsg-bulk-transfer').prop('disabled', count === 0);
      if (count > 0) {
        $('#pbsg-bulk-transfer').text('Transfer Selected (' + count + ')');
      } else {
        $('#pbsg-bulk-transfer').text('Transfer Selected');
      }
    }
  }

  $(document).ready(function () {
    initSettingsConfirmation();
    initMyTutorialsPage();

    // Auto-open transfer modal when redirected from Pages list bulk action
    if (typeof pbsgCrossEdit !== 'undefined' && pbsgCrossEdit.bulkTransferIds && pbsgCrossEdit.bulkTransferIds.length > 0) {
      var ids = pbsgCrossEdit.bulkTransferIds.map(function (id) { return parseInt(id, 10); });
      var titles = ids.map(function () { return 'Selected tutorial'; });
      // Try to get real titles from page cards if available
      ids.forEach(function (id, i) {
        var $card = $('[data-post-id="' + id + '"]');
        if ($card.length) {
          titles[i] = $card.data('post-title') || titles[i];
        }
      });
      openTransferModal(ids, titles);
    }
  });
})(jQuery);
