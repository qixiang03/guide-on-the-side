/**
 * Accessibility Dashboard - Profile Page JavaScript
 * Handles live preview and form interactions on user profile page
 */

(function() {
    'use strict';
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    /**
     * Initialize profile page functionality
     */
    function init() {
        var enableCheckbox = document.getElementById('ae_enable_custom');
        var colorRow = document.getElementById('ae_color_row');
        var widthRow = document.getElementById('ae_width_row');
        var colorInput = document.getElementById('ae_focus_color');
        var widthInput = document.getElementById('ae_focus_width');
        var testElements = document.querySelectorAll('.ae-test-elements *');
        
        // Check if elements exist (in case we're not on profile page)
        if (!enableCheckbox) {
            return;
        }
        
        /**
         * Toggle visibility of color and width fields
         */
        function toggleFields() {
            var enabled = enableCheckbox.checked;
            colorRow.style.display = enabled ? 'table-row' : 'none';
            widthRow.style.display = enabled ? 'table-row' : 'none';
        }
        
        /**
         * Update live preview of focus styles
         */
        function updatePreview() {
            if (!enableCheckbox.checked) {
                return;
            }
            
            var color = colorInput.value;
            var width = widthInput.value;
            
            testElements.forEach(function(el) {
                el.style.setProperty('outline', width + ' solid ' + color, 'important');
                el.style.setProperty('outline-offset', '2px', 'important');
            });
        }
        
        // Attach event listeners
        enableCheckbox.addEventListener('change', toggleFields);
        colorInput.addEventListener('input', updatePreview);
        widthInput.addEventListener('input', updatePreview);
        
        // Update preview when test elements are focused
        testElements.forEach(function(el) {
            el.addEventListener('focus', function() {
                if (enableCheckbox.checked) {
                    updatePreview();
                }
            });
        });
        
        // Set initial state
        toggleFields();
    }
    
})();