/**
 * Accessibility Dashboard - Frontend JavaScript
 * Handles skip links, keyboard navigation tracking, and accessibility enhancements
 */

(function() {
    'use strict';
    
    console.log('Accessibility Enhancer for Pressbooks: Loaded');
    
    /**
     * Cross-browser DOM ready function
     */
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }
    
    /**
     * Initialize all accessibility features
     */
    ready(function() {
        addSkipLink();
        trackKeyboardNavigation();
        enhanceTOCAccessibility();
        enhanceClickableElements();
        
        console.log('Accessibility Enhancer: Initialized');
    });
    
    /**
     * Add skip-to-content link
     */
    function addSkipLink() {
        var skipLink = document.createElement('a');
        skipLink.href = '#content';
        skipLink.className = 'a11y-skip-link';
        skipLink.textContent = 'Skip to main content';
        
        skipLink.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Try to find main content area (Pressbooks-specific selectors)
            var targets = ['#content', '.entry-content', 'main', '[role="main"]', 'article'];
            
            for (var i = 0; i < targets.length; i++) {
                var target = document.querySelector(targets[i]);
                if (target) {
                    target.setAttribute('tabindex', '-1');
                    target.focus();
                    window.scrollTo(0, target.offsetTop);
                    break;
                }
            }
        });
        
        // Insert at the beginning of body
        if (document.body) {
            document.body.insertBefore(skipLink, document.body.firstChild);
        }
    }
    
    /**
     * Track keyboard vs mouse navigation
     * Adds 'keyboard-navigation' class to body when Tab is used
     */
    function trackKeyboardNavigation() {
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Tab') {
                document.body.classList.add('keyboard-navigation');
            }
        });
        
        document.addEventListener('mousedown', function() {
            document.body.classList.remove('keyboard-navigation');
        });
    }
    
    /**
     * Enhance TOC and navigation links with better accessibility
     */
    function enhanceTOCAccessibility() {
        var tocLinks = document.querySelectorAll('#toc a, .page-navigation a, .nav-reading a');
        
        tocLinks.forEach(function(link) {
            // Add title attribute if missing
            if (!link.getAttribute('title')) {
                link.setAttribute('title', link.textContent.trim());
            }
        });
    }
    
    /**
     * Make elements with onclick handlers keyboard accessible
     */
    function enhanceClickableElements() {
        var clickables = document.querySelectorAll('[onclick]:not([tabindex])');
        
        clickables.forEach(function(el) {
            // Make focusable
            el.setAttribute('tabindex', '0');
            
            // Add button role if no role exists
            if (!el.getAttribute('role')) {
                el.setAttribute('role', 'button');
            }
            
            // Allow Enter and Space to trigger click
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    el.click();
                }
            });
        });
    }
    
})();