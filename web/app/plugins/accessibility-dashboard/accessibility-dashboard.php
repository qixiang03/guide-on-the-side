<?php
/**
 * Plugin Name: Accessibility Dashboard
 * Description: Adds enhanced accessibility features with per-user customization
 * Version: 1.0.0
 * Author: Team 8
 */

if (!defined('ABSPATH')) {
    exit;
}

class Pressbooks_Accessibility_Enhancer {
    
    public function __construct() {
        // Try multiple hooks to register color schemes
        add_action('after_setup_theme', array($this, 'register_admin_color_schemes'), 999);
        add_action('admin_init', array($this, 'register_admin_color_schemes'), 999);
        add_action('admin_head', array($this, 'register_admin_color_schemes'), 1);
        
        // Standard WordPress hooks
        add_action('wp_head', array($this, 'add_accessibility_styles'), 999);
        add_action('wp_footer', array($this, 'enqueue_frontend_scripts'), 999);
        add_action('admin_head', array($this, 'add_accessibility_styles'), 999);
        
        // Enqueue custom fonts if selected
        add_action('wp_enqueue_scripts', array($this, 'enqueue_custom_fonts'), 10);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_custom_fonts'), 10);
        
        // Expose custom shortcuts to frontend
        add_action('wp_enqueue_scripts', array($this, 'localize_shortcuts_script'), 10);
        
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
        
        // Set default color scheme for new users
        add_action('user_register', array($this, 'set_default_admin_color'));
        
        // Pressbooks custom CSS filters
        add_filter('pb_pdf_css_override', array($this, 'add_pdf_accessibility'));
        add_filter('pb_epub_css_override', array($this, 'add_epub_accessibility'));
    }
    
    /**
     * Enqueue custom fonts from Google Fonts if UPEI Library Default is selected
     */
    public function enqueue_custom_fonts() {
        $user_id = get_current_user_id();
        $font_family = $user_id ? get_user_meta($user_id, 'ae_font_family', true) : 'default';
        
        if ($font_family === 'upei-default') {
            wp_enqueue_style('ae-upei-fonts', 'https://fonts.googleapis.com/css2?family=Lusitana:wght@400;700&family=Roboto+Condensed:wght@400;700&family=Roboto:wght@400;700&display=swap', array(), null);
        }
    }

    /**
     * Pass shortcut configuration to the frontend javascript
     */
    public function localize_shortcuts_script() {
        $user_id = get_current_user_id();
        if (!$user_id) return;
        
        $enable_shortcuts = get_user_meta($user_id, 'ae_enable_shortcuts', true);
        
        if ($enable_shortcuts) {
            $shortcuts = array(
                'prev' => get_user_meta($user_id, 'ae_shortcut_prev', true) ?: 'ArrowLeft',
                'next' => get_user_meta($user_id, 'ae_shortcut_next', true) ?: 'ArrowRight',
                'focus_quiz' => get_user_meta($user_id, 'ae_shortcut_quiz', true) ?: 'q',
                'focus_tutorial' => get_user_meta($user_id, 'ae_shortcut_tutorial', true) ?: 't',
            );
            
            wp_register_script('accessibility-enhancer-shortcuts', false);
            wp_enqueue_script('accessibility-enhancer-shortcuts');
            wp_add_inline_script('accessibility-enhancer-shortcuts', 'window.aeShortcuts = ' . wp_json_encode($shortcuts) . ';');
        }
    }

    /**
     * Register custom admin color schemes
     */
    public function register_admin_color_schemes() {
        global $_wp_admin_css_colors;
        
        // Prevent duplicate registration
        if (isset($_wp_admin_css_colors['upei-library'])) {
            return;
        }
        
        $plugin_url = plugin_dir_url(__FILE__);
        $plugin_path = plugin_dir_path(__FILE__);
        
        // Check if wp_admin_css_color function exists
        if (!function_exists('wp_admin_css_color')) {
            return;
        }
        
        // UPEI Library Theme (Default for new users)
        $upei_css = $plugin_path . 'styles/admin-colors-upei.css';
        if (file_exists($upei_css)) {
            wp_admin_css_color(
                'upei-library',
                __('UPEI Library', 'accessibility-enhancer'),
                $plugin_url . 'styles/admin-colors-upei.css',
                array('#333333', '#8C2004', '#517E1B', '#f1f1f1'),
                array(
                    'base' => '#f5f5f5',
                    'focus' => '#8C2004',
                    'current' => '#333333'
                )
            );
        }
        
        // Enhanced Contrast - Blue/Orange (for deuteranopia - red-green colorblindness)
        $colorblind_css = $plugin_path . 'styles/admin-colors-colorblind.css';
        if (file_exists($colorblind_css)) {
            wp_admin_css_color(
                'colorblind-friendly',
                __('Colorblind Friendly (Blue/Orange)', 'accessibility-enhancer'),
                $plugin_url . 'styles/admin-colors-colorblind.css',
                array('#003f87', '#0066cc', '#ff6600', '#f0f0f0'),
                array(
                    'base' => '#f0f0f0',
                    'focus' => '#ff6600',
                    'current' => '#0066cc'
                )
            );
        }
        
        // Force refresh the global array
        wp_cache_delete('admin_colors', 'admin_color_schemes');
    }
    
    /**
     * Set default admin color scheme for new users
     */
    public function set_default_admin_color($user_id) {
        update_user_meta($user_id, 'admin_color', 'upei-library');
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
        
        $user_id = get_current_user_id();
        $font_family = $user_id ? get_user_meta($user_id, 'ae_font_family', true) : 'default';
        
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

<?php if ($font_family && $font_family !== 'default') : ?>
    <?php if ($font_family === 'upei-default') : ?>
/* UPEI Library Default Typography */
body, p, span, div, li, td, th {
    font-family: 'Roboto', sans-serif !important;
}
h1, h2, h3, h4, h5, h6, strong, b, .entry-title {
    font-family: 'Lusitana', serif !important;
}
button, input, select, textarea, .nav, .menu, a.button, .page-navigation a, .a11y-skip-link {
    font-family: 'Roboto Condensed', sans-serif !important;
}
    <?php else : ?>
/* Custom Sitewide Font */
body, h1, h2, h3, h4, h5, h6, p, a, span, div, li, td, th, button, input, select, textarea {
    font-family: <?php echo esc_attr($font_family); ?> !important;
}
    <?php endif; ?>
<?php endif; ?>

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
        $font_family = get_user_meta($user->ID, 'ae_font_family', true) ?: 'default';
        
        // Shortcut Settings
        $enable_shortcuts = get_user_meta($user->ID, 'ae_enable_shortcuts', true);
        $shortcut_prev = get_user_meta($user->ID, 'ae_shortcut_prev', true) ?: 'ArrowLeft';
        $shortcut_next = get_user_meta($user->ID, 'ae_shortcut_next', true) ?: 'ArrowRight';
        $shortcut_quiz = get_user_meta($user->ID, 'ae_shortcut_quiz', true) ?: 'q';
        $shortcut_tutorial = get_user_meta($user->ID, 'ae_shortcut_tutorial', true) ?: 't';
        ?>
        
        <div class="ae-profile-section">
            <h2>Accessibility Settings</h2>
            <p>Customize keyboard focus indicators, typography, and keyboard shortcuts to improve readability and navigation.</p>
            
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
                    </td>
                </tr>

                <tr id="ae_font_row">
                    <th scope="row">
                        <label for="ae_font_family">Sitewide Font Family</label>
                    </th>
                    <td>
                        <select name="ae_font_family" id="ae_font_family">
                            <option value="default" <?php selected($font_family, 'default'); ?>>System Default</option>
                            <option value="upei-default" <?php selected($font_family, 'upei-default'); ?>>UPEI Library Default</option>
                            <option value="Arial, Helvetica, sans-serif" <?php selected($font_family, 'Arial, Helvetica, sans-serif'); ?>>Arial</option>
                            <option value="Verdana, Geneva, sans-serif" <?php selected($font_family, 'Verdana, Geneva, sans-serif'); ?>>Verdana</option>
                            <option value="Tahoma, Geneva, sans-serif" <?php selected($font_family, 'Tahoma, Geneva, sans-serif'); ?>>Tahoma</option>
                        </select>
                        <p class="description">
                            Select an accessibility-friendly font to override the default site typography.
                        </p>
                    </td>
                </tr>
            </table>

            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #ddd;" />
            <h3>Custom Keyboard Shortcuts</h3>
            <p>Define custom keys to control tutorial pages efficiently. Use character keys (e.g. 'q') or key names (e.g. 'ArrowLeft').</p>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable Custom Shortcuts</th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="ae_enable_shortcuts" 
                                   id="ae_enable_shortcuts"
                                   value="1" 
                                   <?php checked($enable_shortcuts, '1'); ?> />
                            Use custom keyboard shortcuts on tutorial pages
                        </label>
                    </td>
                </tr>

                <tr class="ae-shortcut-row">
                    <th scope="row">
                        <label for="ae_shortcut_prev">Previous Button Key</label>
                    </th>
                    <td>
                        <input type="text" 
                               name="ae_shortcut_prev" 
                               id="ae_shortcut_prev"
                               value="<?php echo esc_attr($shortcut_prev); ?>" 
                               class="regular-text" />
                    </td>
                </tr>

                <tr class="ae-shortcut-row">
                    <th scope="row">
                        <label for="ae_shortcut_next">Next Button Key</label>
                    </th>
                    <td>
                        <input type="text" 
                               name="ae_shortcut_next" 
                               id="ae_shortcut_next"
                               value="<?php echo esc_attr($shortcut_next); ?>" 
                               class="regular-text" />
                    </td>
                </tr>

                <tr class="ae-shortcut-row">
                    <th scope="row">
                        <label for="ae_shortcut_quiz">Focus Quiz Button Key</label>
                    </th>
                    <td>
                        <input type="text" 
                               name="ae_shortcut_quiz" 
                               id="ae_shortcut_quiz"
                               value="<?php echo esc_attr($shortcut_quiz); ?>" 
                               class="regular-text" />
                    </td>
                </tr>

                <tr class="ae-shortcut-row">
                    <th scope="row">
                        <label for="ae_shortcut_tutorial">Focus Tutorial Button Key</label>
                    </th>
                    <td>
                        <input type="text" 
                               name="ae_shortcut_tutorial" 
                               id="ae_shortcut_tutorial"
                               value="<?php echo esc_attr($shortcut_tutorial); ?>" 
                               class="regular-text" />
                    </td>
                </tr>
            </table>
            
            <?php 
            $preview_style = '';
            if ($font_family !== 'default' && $font_family !== 'upei-default') {
                $preview_style .= 'font-family: ' . esc_attr($font_family) . '; ';
            } elseif ($font_family === 'upei-default') {
                $preview_style .= "font-family: 'Roboto', sans-serif; ";
            }
            ?>
            <div class="ae-preview-box">
                <p>Test your focus settings (press Tab to navigate):</p>
                <div class="ae-test-elements" style="<?php echo $preview_style; ?>">
                    <button aria-label="Button Focus Test Element" type="button" <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>Test Button</button>
                    <input aria-label="Text Focus Test Element" type="text" placeholder="Test Input" <?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>
                    <a aria-label="Link Focus Test Element" href="#test">Test Link</a>
                    <select aria-label="Dropdown Focus Test Element"<?php if($font_family === 'upei-default') echo 'style="font-family: \'Roboto Condensed\', sans-serif;"'; ?>>
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
        
        // Save focus custom settings
        if (isset($_POST['ae_enable_custom'])) {
            update_user_meta($user_id, 'ae_enable_custom', '1');
        } else {
            delete_user_meta($user_id, 'ae_enable_custom');
        }
        
        if (isset($_POST['ae_focus_color'])) {
            $color = sanitize_hex_color($_POST['ae_focus_color']);
            if ($color) {
                update_user_meta($user_id, 'ae_focus_color', $color);
            }
        }
        
        if (isset($_POST['ae_focus_width'])) {
            $width = sanitize_text_field($_POST['ae_focus_width']);
            if (preg_match('/^\d+\.?\d*(px|em|rem|%)$/', $width)) {
                update_user_meta($user_id, 'ae_focus_width', $width);
            }
        }

        // Save font setting
        if (isset($_POST['ae_font_family'])) {
            $font_family = sanitize_text_field(wp_unslash($_POST['ae_font_family']));
            update_user_meta($user_id, 'ae_font_family', $font_family);
        }

        // Save Custom Shortcuts settings
        if (isset($_POST['ae_enable_shortcuts'])) {
            update_user_meta($user_id, 'ae_enable_shortcuts', '1');
        } else {
            delete_user_meta($user_id, 'ae_enable_shortcuts');
        }

        $shortcut_fields = ['ae_shortcut_prev', 'ae_shortcut_next', 'ae_shortcut_quiz', 'ae_shortcut_tutorial'];
        foreach ($shortcut_fields as $field) {
            if (isset($_POST[$field])) {
                // Keep the raw string (allowing 'ArrowLeft', 'a', etc) but remove potentially dangerous characters.
                $key = sanitize_text_field(wp_unslash($_POST[$field]));
                update_user_meta($user_id, $field, $key);
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
        // Set default color scheme for existing users without one
        $users = get_users(array('fields' => array('ID')));
        foreach ($users as $user) {
            $current_color = get_user_meta($user->ID, 'admin_color', true);
            if (empty($current_color)) {
                update_user_meta($user->ID, 'admin_color', 'upei-library');
            }
        }
    }
    
    public static function deactivate() {
        // Cleanup if needed
    }
}

// Initialize
new Pressbooks_Accessibility_Enhancer();

register_activation_hook(__FILE__, array('Pressbooks_Accessibility_Enhancer', 'activate'));
register_deactivation_hook(__FILE__, array('Pressbooks_Accessibility_Enhancer', 'deactivate'));