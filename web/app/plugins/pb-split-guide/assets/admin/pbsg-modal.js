/**
 * PbsgModal — generic in-page confirmation modal.
 *
 * Usage:
 *   PbsgModal.open({
 *     variant: 'default' | 'neutral' | 'destructive',
 *     icon: '<svg ...>',            // pre-rendered SVG HTML (from pbsg_icon())
 *     heading: 'Enable cross-editing?',
 *     subtitle: 'Librarians will gain new edit permissions...',
 *     bodyLabel: 'What this changes',
 *     bullets: ['First point.', 'Second point.', 'Third point.'],
 *     caveat: 'Heads up: ...',      // optional
 *     confirmLabel: 'Enable cross-editing',
 *     onConfirm: function () { ... },
 *     onCancel:  function () { ... } // optional
 *   });
 *
 * Opens a single active modal. Second open() before close swaps content.
 */
(function (global) {
  'use strict';

  var state = {
    backdrop: null,
    modal: null,
    triggerEl: null,
    onConfirm: null,
    onCancel: null,
    escHandler: null,
  };

  function buildDom() {
    if (state.backdrop) return;

    var backdrop = document.createElement('div');
    backdrop.className = 'pbsg-modal__backdrop';
    backdrop.setAttribute('role', 'presentation');

    var modal = document.createElement('div');
    modal.className = 'pbsg-modal';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'pbsg-modal-heading');
    modal.setAttribute('aria-describedby', 'pbsg-modal-body');
    modal.innerHTML = [
      '<div class="pbsg-modal__head">',
      '  <div class="pbsg-modal__icon" data-role="icon"></div>',
      '  <div>',
      '    <p class="pbsg-modal__heading" id="pbsg-modal-heading" data-role="heading"></p>',
      '    <p class="pbsg-modal__subtitle" data-role="subtitle"></p>',
      '  </div>',
      '</div>',
      '<div class="pbsg-modal__body" id="pbsg-modal-body">',
      '  <p class="pbsg-modal__body-label" data-role="body-label"></p>',
      '  <ul class="pbsg-modal__bullets" data-role="bullets"></ul>',
      '  <div class="pbsg-modal__caveat" data-role="caveat" hidden></div>',
      '</div>',
      '<div class="pbsg-modal__foot">',
      '  <button type="button" class="pbsg-modal__btn-cancel" data-role="cancel">Cancel</button>',
      '  <button type="button" class="pbsg-modal__btn-confirm" data-role="confirm"></button>',
      '</div>'
    ].join('');

    backdrop.appendChild(modal);
    document.body.appendChild(backdrop);

    // Backdrop click = cancel
    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal(false);
    });

    // Cancel button
    modal.querySelector('[data-role="cancel"]').addEventListener('click', function () {
      closeModal(false);
    });

    // Confirm button
    modal.querySelector('[data-role="confirm"]').addEventListener('click', function () {
      closeModal(true);
    });

    state.backdrop = backdrop;
    state.modal = modal;
  }

  function openModal(opts) {
    buildDom();
    opts = opts || {};

    var variant = opts.variant || 'default';
    state.modal.classList.remove('pbsg-modal--neutral', 'pbsg-modal--destructive', 'pbsg-modal--warn');
    if (variant === 'neutral') state.modal.classList.add('pbsg-modal--neutral');
    if (variant === 'destructive') state.modal.classList.add('pbsg-modal--destructive');
    if (variant === 'warn') state.modal.classList.add('pbsg-modal--warn');

    state.modal.querySelector('[data-role="icon"]').innerHTML = opts.icon || '';
    state.modal.querySelector('[data-role="heading"]').textContent = opts.heading || '';
    state.modal.querySelector('[data-role="subtitle"]').textContent = opts.subtitle || '';
    state.modal.querySelector('[data-role="body-label"]').textContent = opts.bodyLabel || '';

    var bulletsHost = state.modal.querySelector('[data-role="bullets"]');
    bulletsHost.innerHTML = '';
    (opts.bullets || []).forEach(function (text) {
      var li = document.createElement('li');
      li.innerHTML = text; // pre-sanitized HTML allowed (for <strong>)
      bulletsHost.appendChild(li);
    });

    var caveat = state.modal.querySelector('[data-role="caveat"]');
    if (opts.caveat) {
      caveat.innerHTML = opts.caveat;
      caveat.hidden = false;
    } else {
      caveat.innerHTML = '';
      caveat.hidden = true;
    }

    var confirmBtn = state.modal.querySelector('[data-role="confirm"]');
    confirmBtn.textContent = opts.confirmLabel || 'Confirm';

    state.onConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
    state.onCancel  = typeof opts.onCancel  === 'function' ? opts.onCancel  : null;
    state.triggerEl = document.activeElement;

    state.backdrop.classList.add('is-open');
    document.body.style.overflow = 'hidden';

    // Focus first focusable — the cancel button by default
    setTimeout(function () {
      state.modal.querySelector('[data-role="cancel"]').focus();
    }, 10);

    // ESC closes
    state.escHandler = function (e) {
      if (e.key === 'Escape') closeModal(false);
      if (e.key === 'Tab') trapTab(e);
    };
    document.addEventListener('keydown', state.escHandler);
  }

  function trapTab(e) {
    var focusable = state.modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    if (!focusable.length) return;
    var first = focusable[0];
    var last  = focusable[focusable.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function closeModal(confirmed) {
    if (!state.backdrop) return;
    state.backdrop.classList.remove('is-open');
    document.body.style.overflow = '';
    if (state.escHandler) {
      document.removeEventListener('keydown', state.escHandler);
      state.escHandler = null;
    }

    var cb = confirmed ? state.onConfirm : state.onCancel;
    state.onConfirm = null;
    state.onCancel  = null;

    if (state.triggerEl && typeof state.triggerEl.focus === 'function') {
      state.triggerEl.focus();
    }
    state.triggerEl = null;

    if (cb) cb();
  }

  global.PbsgModal = {
    open: openModal,
    close: function () { closeModal(false); }
  };
})(window);
