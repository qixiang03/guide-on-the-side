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
    cross_edit_on: 'Enable cross-editing? All librarians will be able to edit tutorials created by other librarians. They will not be able to delete or change the publish status of tutorials they don\'t own.',
    cross_edit_off: 'Disable cross-editing? Librarians will only be able to edit their own tutorials. Any unsaved changes by librarians currently editing another\'s tutorial will be lost.',
    transfer_on: 'Enable ownership transfer? Librarians will be able to transfer ownership of their own tutorials to other librarians or administrators.',
    transfer_off: 'Disable ownership transfer? Librarians will no longer be able to transfer their tutorials to other librarians. Administrators can still reassign tutorial ownership at any time.'
  };

  function initSettingsConfirmation() {
    var $form = $('form[action="options.php"]');
    if (!$form.length) return;

    var $crossEdit = $('#pbsg_cross_edit_toggle');
    var $transfer  = $('#pbsg_transfer_toggle');

    if (!$crossEdit.length && !$transfer.length) return;

    $form.on('submit', function (e) {
      var messages = [];

      if ($crossEdit.length) {
        var crossOriginal = $crossEdit.data('original') === 1 || $crossEdit.data('original') === '1';
        var crossCurrent  = $crossEdit.is(':checked');
        if (crossCurrent !== crossOriginal) {
          messages.push(crossCurrent ? confirmMessages.cross_edit_on : confirmMessages.cross_edit_off);
        }
      }

      if ($transfer.length) {
        var transferOriginal = $transfer.data('original') === 1 || $transfer.data('original') === '1';
        var transferCurrent  = $transfer.is(':checked');
        if (transferCurrent !== transferOriginal) {
          messages.push(transferCurrent ? confirmMessages.transfer_on : confirmMessages.transfer_off);
        }
      }

      if (messages.length > 0) {
        var combined = messages.join('\n\n');
        if (!window.confirm(combined)) {
          e.preventDefault();
        }
      }
    });
  }

  /* ================================================================
     Section 2: Transfer Ownership Modal (placeholder for Task 5)
     ================================================================ */

  /* ================================================================
     Section 3: My Tutorials Page (placeholder for Task 6)
     ================================================================ */

  function initMyTutorialsPage() {
    // Tab switching and inline transfer actions — implemented in Task 6
  }

  $(document).ready(function () {
    initSettingsConfirmation();
    initMyTutorialsPage();
  });
})(jQuery);
