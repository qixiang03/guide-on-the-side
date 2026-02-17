<?php
/**
 * Plugin Name: Accessibility Dashboard
 * Description: Adds enhanced accessibility features with per-user customization
 * Version: 0.1.0
 * Author: Team 8
 */

if (!defined('ABSPATH')) {
    exit;
}

class Pressbooks_Accessibility_Enhancer {
    
    public function __construct() {
        // Standard WordPress hooks
        add_action('wp_head', array($this, 'add_accessibility_styles'), 999);
        add_action('wp_footer', array($this, 'enqueue_frontend_scripts'), 999);
        add_action('admin_head', array($this, 'add_accessibility_styles'), 999);
        
        // Pressbooks-specific hooks
        add_action('pressbooks_head', array($this, 'add_accessibility_styles'), 999);
        add_action('pb_head', array($this, 'add_accessibility_styles'), 999);
        
        // Alternative: use wp_enqueue_scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_inline_styles'), 999);
        
        // User profile hooks
        add_action('show_user_profile', array($this, 'add_profile_fields'));
        add_action('edit_user_profile', array($this, 'add_profile_fields'));
        add_action('personal_options_update', array($this, 'save_profile_fields'));
        add_action('edit_user_profile_update', array($this, 'save_profile_fields'));
        
        // Enqueue profile CSS and JS
        add_action('admin_enqueue_scripts', array($this, 'enqueue_profile_assets'));
        
        // Pressbooks custom CSS filters
        add_filter('pb_pdf_css_override', array($this, 'add_pdf_accessibility'));
        add_filter('pb_epub_css_override', array($this, 'add_epub_accessibility'));
    }
    
    /**
     * Get current user's focus settings or defaults
     */
    private function get_user_focus_settings() {
        $user_id = get_current_user_id();
        
        if ($user_id && get_user_meta($user_id, 'ae_enable_custom', true)) {
            return array(
                'color' => get_user_meta($user_id, 'ae_focus_color', true) ?: '#0066cc',
                'width' => get_user_meta($user_id, 'ae_focus_width', true) ?: '3px',
                'enabled' => true
            );
        }
        
        // Default settings
        return array(
            'color' => '#0066cc',
            'width' => '3px',
            'enabled' => false
        );
    }
    
    /**
     * Enqueue inline styles
     */
    public function enqueue_inline_styles() {
        wp_register_style('accessibility-enhancer-dummy', false);
        wp_enqueue_style('accessibility-enhancer-dummy');
        wp_add_inline_style('accessibility-enhancer-dummy', $this->get_css_rules());
    }
    
    /**
     * Add accessibility styles
     */
    public function add_accessibility_styles() {
        echo '<style id="accessibility-enhancer-inline">';
        echo $this->get_css_rules();
        echo '</style>';
    }
    
    /**
     * Get CSS rules
     */
    private function get_css_rules() {
        $settings = $this->get_user_focus_settings();
        $focus_color = esc_attr($settings['color']);
        $focus_width = esc_attr($settings['width']);
        
        ob_start();
        ?>
/* Accessibility Enhancer - Enhanced Focus Indicators */
*:focus,
a:focus,
button:focus,
input:focus,
select:focus,
textarea:focus,
[tabindex]:focus,
[role="button"]:focus,
.page-navigation a:focus,
#toc a:focus,
.nav-reading a:focus {
    outline: <?php echo $focus_width; ?> solid <?php echo $focus_color; ?> !important;
    outline-offset: 2px !important;
    box-shadow: 0 0 0 4px <?php echo $this->hex_to_rgba($focus_color, 0.2); ?> !important;
}

/* Pressbooks-specific elements */
.entry-content a:focus,
.entry-title a:focus,
#content a:focus {
    outline: <?php echo $focus_width; ?> solid <?php echo $focus_color; ?> !important;
    outline-offset: 2px !important;
}

/* Skip link */
.a11y-skip-link {
    position: absolute;
    top: -40px;
    left: 6px;
    z-index: 999999;
    background: <?php echo $focus_color; ?>;
    color: white;
    padding: 8px 12px;
    text-decoration: none;
    font-size: 14px;
    font-weight: bold;
    border-radius: 0 0 4px 4px;
}

.a11y-skip-link:focus {
    top: 0 !important;
    outline: 3px solid white !important;
}

/* Keyboard navigation mode */
body.keyboard-navigation *:focus {
    outline-width: calc(<?php echo $focus_width; ?> + 1px) !important;
    box-shadow: 0 0 0 6px <?php echo $this->hex_to_rgba($focus_color, 0.3); ?> !important;
}

/* High contrast support */
@media (prefers-contrast: high) {
    *:focus {
        outline-width: 4px !important;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    .a11y-skip-link {
        transition: none !important;
    }
}
        <?php
        return ob_get_clean();
    }
    
    /**
     * Convert hex color to rgba
     */
    private function hex_to_rgba($hex, $alpha = 1) {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        return "rgba($r, $g, $b, $alpha)";
    }
    
    /**
     * Enqueue frontend JavaScript
     */
    public function enqueue_frontend_scripts() {
        wp_enqueue_script(
            'accessibility-enhancer-frontend',
            plugin_dir_url(__FILE__) . 'assets/frontend.js',
            array(),
            '1.0.0',
            true
        );
    }
    
    /**
     * Enqueue profile page assets (CSS and JS)
     */
    public function enqueue_profile_assets($hook) {
        // Only load on profile and user-edit pages
        if ($hook !== 'profile.php' && $hook !== 'user-edit.php') {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'accessibility-enhancer-profile',
            plugin_dir_url(__FILE__) . 'styles/profile.css',
            array(),
            '1.0.0'
        );
        
        // Enqueue JS
        wp_enqueue_script(
            'accessibility-enhancer-profile',
            plugin_dir_url(__FILE__) . 'assets/profile.js',
            array(),
            '1.0.0',
            true
        );
    }
    
    /**
     * Add fields to user profile page
     */
    public function add_profile_fields($user) {
        $enable_custom = get_user_meta($user->ID, 'ae_enable_custom', true);
        $focus_color = get_user_meta($user->ID, 'ae_focus_color', true) ?: '#0066cc';
        $focus_width = get_user_meta($user->ID, 'ae_focus_width', true) ?: '3px';
        ?>
        
        <div class="ae-profile-section">
            <h2>Accessibility Settings</h2>
            <p>Customize keyboard focus indicators to improve navigation visibility.</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Enable Custom Focus Indicators</th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="ae_enable_custom" 
                                   id="ae_enable_custom"
                                   value="1" 
                                   <?php checked($enable_custom, '1'); ?> />
                            Use custom focus indicator settings
                        </label>
                        <p class="description">
                            When enabled, your custom focus settings will be applied across the site.
                        </p>
                    </td>
                </tr>
                
                <tr id="ae_color_row">
                    <th scope="row">
                        <label for="ae_focus_color">Focus Outline Color</label>
                    </th>
                    <td>
                        <input type="color" 
                               name="ae_focus_color" 
                               id="ae_focus_color"
                               value="<?php echo esc_attr($focus_color); ?>" />
                        <p class="description">
                            Choose the color for keyboard focus outlines (default: #0066cc - blue)
                        </p>
                    </td>
                </tr>
                
                <tr id="ae_width_row">
                    <th scope="row">
                        <label for="ae_focus_width">Focus Outline Width</label>
                    </th>
                    <td>
                        <input type="text" 
                               name="ae_focus_width" 
                               id="ae_focus_width"
                               value="<?php echo esc_attr($focus_width); ?>" 
                               class="small-text"
                               placeholder="3px" />
                        <p class="description">
                            Enter a CSS width value (e.g., 2px, 3px, 4px, 5px)
                        </p>
                    </td>
                </tr>
            </table>
            
            <div class="ae-preview-box">
                <p>Test your focus settings (press Tab to navigate):</p>
                <div class="ae-test-elements">
                    <button type="button">Test Button</button>
                    <input type="text" placeholder="Test Input">
                    <a href="#test">Test Link</a>
                    <select>
                        <option>Test Dropdown</option>
                    </select>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Save profile fields
     */
    public function save_profile_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        // Save enable/disable setting
        if (isset($_POST['ae_enable_custom'])) {
            update_user_meta($user_id, 'ae_enable_custom', '1');
        } else {
            delete_user_meta($user_id, 'ae_enable_custom');
        }
        
        // Save color setting
        if (isset($_POST['ae_focus_color'])) {
            $color = sanitize_hex_color($_POST['ae_focus_color']);
            if ($color) {
                update_user_meta($user_id, 'ae_focus_color', $color);
            }
        }
        
        // Save width setting
        if (isset($_POST['ae_focus_width'])) {
            $width = sanitize_text_field($_POST['ae_focus_width']);
            // Validate CSS width format
            if (preg_match('/^\d+\.?\d*(px|em|rem|%)$/', $width)) {
                update_user_meta($user_id, 'ae_focus_width', $width);
            }
        }
    }
    
    /**
     * Add accessibility to PDF exports
     */
    public function add_pdf_accessibility($css) {
        $css .= "\n/* Accessibility - High contrast for print */\n";
        $css .= "a { text-decoration: underline; }\n";
        return $css;
    }
    
    /**
     * Add accessibility to EPUB exports
     */
    public function add_epub_accessibility($css) {
        return $css;
    }
    
    /**
     * Activation
     */
    public static function activate() {
        // No default options needed since settings are per-user
    }
    
    public static function deactivate() {
        // Cleanup if needed
    }
}

// Initialize
new Pressbooks_Accessibility_Enhancer();

register_activation_hook(__FILE__, array('Pressbooks_Accessibility_Enhancer', 'activate'));
register_deactivation_hook(__FILE__, array('Pressbooks_Accessibility_Enhancer', 'deactivate'));