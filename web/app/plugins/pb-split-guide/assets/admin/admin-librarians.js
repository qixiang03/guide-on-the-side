/**
 * Manage Librarians — Admin JS
 *
 * Handles:
 *  - Toggle registration form panel
 *  - Confirm deactivation dialog
 *  - Client-side email validation
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $registerPanel = $('#pbsg-register-panel');
        var $toggleBtn = $('#pbsg-toggle-register-form');
        var $cancelBtn = $('#pbsg-cancel-register');
        var $promotePanel = $('#pbsg-promote-panel');
        var $promoteToggleBtn = $('#pbsg-toggle-promote-form');
        var $promoteCancelBtn = $('#pbsg-cancel-promote');
        var $deactivateForm = $('#pbsg-deactivate-form');

        // Toggle registration form (close promote panel if open)
        $toggleBtn.on('click', function () {
            $promotePanel.slideUp(200);
            $registerPanel.slideToggle(200, function () {
                if ($registerPanel.is(':visible')) {
                    $registerPanel.find('input[name="username"]').trigger('focus');
                }
            });
        });

        // Cancel registration
        $cancelBtn.on('click', function () {
            $registerPanel.slideUp(200);
        });

        // Toggle promote form (close register panel if open)
        $promoteToggleBtn.on('click', function () {
            $registerPanel.slideUp(200);
            $promotePanel.slideToggle(200, function () {
                if ($promotePanel.is(':visible')) {
                    $promotePanel.find('select[name="user_id"]').trigger('focus');
                }
            });
        });

        // Cancel promote
        $promoteCancelBtn.on('click', function () {
            $promotePanel.slideUp(200);
        });
        /**
         * Build localized confirm string; %1$s and %2$s are replaced in order.
         *
         * @param {string} fmt
         * @param {string} name
         * @param {string} login
         * @return {string}
         */
        function formatUserConfirm(fmt, name, login) {
            if (!fmt) {
                return '';
            }
            return fmt.replace('%1$s', name).replace('%2$s', login);
        }

        // Confirm deactivation
        $deactivateForm.on('submit', function (e) {
            var $f = $(this);
            var displayName = $f.attr('data-display-name') || '';
            var userLogin = $f.attr('data-user-login') || '';
            var msg;
            if (displayName && userLogin && typeof pbsgLibrarians !== 'undefined' && pbsgLibrarians.confirmDeactivateFmt) {
                msg = formatUserConfirm(pbsgLibrarians.confirmDeactivateFmt, displayName, userLogin);
            } else if (typeof pbsgLibrarians !== 'undefined' && pbsgLibrarians.confirmDeactivateFallback) {
                msg = pbsgLibrarians.confirmDeactivateFallback;
            } else {
                msg = 'Are you sure you want to deactivate this librarian?';
            }

            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });

        // Confirm reactivation (restores Librarian role)
        $(document).on('submit', 'form.pbsg-reactivate-form', function (e) {
            var $f = $(this);
            var displayName = $f.attr('data-display-name') || '';
            var userLogin = $f.attr('data-user-login') || '';
            var msg;
            if (displayName && userLogin && typeof pbsgLibrarians !== 'undefined' && pbsgLibrarians.confirmReactivateFmt) {
                msg = formatUserConfirm(pbsgLibrarians.confirmReactivateFmt, displayName, userLogin);
            } else if (typeof pbsgLibrarians !== 'undefined' && pbsgLibrarians.confirmReactivateFallback) {
                msg = pbsgLibrarians.confirmReactivateFallback;
            } else {
                msg = 'Are you sure you want to reactivate this librarian?';
            }

            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });

        // Client-side email validation on registration form
        $('.pbsg-register-form').on('submit', function (e) {
            var email = $(this).find('input[name="email"]').val().trim();
            if (email && !isValidEmail(email)) {
                e.preventDefault();
                window.alert('Please enter a valid email address.');
                $(this).find('input[name="email"]').trigger('focus');
            }
        });

        // Client-side email validation on edit form
        $('.pbsg-edit-form').on('submit', function (e) {
            var email = $(this).find('input[name="email"]').val().trim();
            if (email && !isValidEmail(email)) {
                e.preventDefault();
                window.alert('Please enter a valid email address.');
                $(this).find('input[name="email"]').trigger('focus');
            }
        });

        /**
         * Build localized confirm string; %1$s and %2$s are replaced in order.
         *
         * @param {string} fmt
         * @param {string} name
         * @param {string} login
         * @return {string}
         */
        function formatUserConfirm(fmt, name, login) {
            if (!fmt) {
                return '';
            }
            return fmt.replace('%1$s', name).replace('%2$s', login);
        }

        /**
         * Basic email validation.
         *
         * @param {string} email
         * @return {boolean}
         */
        function isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
    });
})(jQuery);
