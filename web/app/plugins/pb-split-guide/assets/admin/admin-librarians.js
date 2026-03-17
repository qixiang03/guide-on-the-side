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
        var $deactivateForm = $('#pbsg-deactivate-form');

        // Toggle registration form
        $toggleBtn.on('click', function () {
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

        // Confirm deactivation
        $deactivateForm.on('submit', function (e) {
            var msg = (typeof pbsgLibrarians !== 'undefined' && pbsgLibrarians.confirmDeactivate)
                ? pbsgLibrarians.confirmDeactivate
                : 'Are you sure you want to deactivate this librarian?';

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
