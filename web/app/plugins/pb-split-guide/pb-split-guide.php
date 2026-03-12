<?php
/**
 * Plugin Name: PB Split Guide (Multi-step H5P + Tutorial)
 * Description: Adds a Tutorial Page with a split-screen Template. Supports multiple steps (each step = H5P quiz + tutorial source) with Prev/Next navigation on the same page.
 * Version: 0.5.0
 * Author: Team 8
 */

if (!defined('ABSPATH')) exit;

// Load Composer deps (TCPDF)
$autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

require_once plugin_dir_path(__FILE__) . 'includes/steps-normalizer.php';
require_once plugin_dir_path( __FILE__ ) . 'class-pbsg-analytics.php';
require_once plugin_dir_path( __FILE__ ) . 'class-pbsg-analytics-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-certificate.php';


class PB_Split_Guide_Plugin {
  const TEMPLATE_SLUG = 'split-guide-template.php';

  // Meta keys
  const META_STEPS = '_pbsg_steps_json';
  const META_NOTE  = '_pbsg_header_note';

  public function __construct() {
    add_filter('theme_page_templates', [$this, 'register_page_template']);
    add_filter('template_include', [$this, 'load_page_template']);

    add_action('add_meta_boxes_page', [$this, 'add_meta_boxes']);
    add_action('save_post_page', [$this, 'save_meta'], 10, 2);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('wp_ajax_pbsg_list_h5p', [$this, 'ajax_list_h5p']);

    // Rename "Pages" to "Tutorials" — use gettext filter (like Pressbooks does
    // for "Sites" → "Books") so it works everywhere regardless of menu rebuild order.
    if (function_exists('is_admin') && is_admin()) {
      add_filter('gettext', [$this, 'rename_pages_to_tutorials_gettext'], 20, 3);
      add_filter('ngettext', [$this, 'rename_pages_to_tutorials_ngettext'], 20, 5);
    }

    // Also patch the $menu globals after Pressbooks SideBar (priority 999)
    add_action('admin_menu', [$this, 'rename_pages_menu_globals'], 1001);
    add_action('network_admin_menu', [$this, 'rename_pages_menu_globals'], 1001);

    // Reorder menu at the very last moment before rendering.
    // The add_menu_classes filter fires AFTER uksort, usort, and all other
    // menu processing — right before HTML output. Nothing can override this.
    add_filter('add_menu_classes', [$this, 'reorder_admin_menu'], 1000);

  }

  /**
   * Rename "Pages" strings to "Tutorials" via gettext filter.
   * This mirrors how Pressbooks renames "Sites" to "Books".
   */
  public function rename_pages_to_tutorials_gettext($translated, $text, $domain) {
    if (!is_admin()) return $translated;

    $replacements = [
      'Pages'                   => 'Tutorials',
      'Page'                    => 'Tutorial',
      'Add New Page'            => 'Add New Tutorial',
      'Add Page'                => 'Add Tutorial',
      'Edit Page'               => 'Edit Tutorial',
      'New Page'                => 'New Tutorial',
      'View Page'               => 'View Tutorial',
      'View Pages'              => 'View Tutorials',
      'Search Pages'            => 'Search Tutorials',
      'All Pages'               => 'All Tutorials',
      'No pages found.'         => 'No tutorials found.',
      'No pages found in Trash.'=> 'No tutorials found in Trash.',
      'Parent Page:'            => 'Parent Tutorial:',
      'Parent Page'             => 'Parent Tutorial',
      'Page Attributes'         => 'Tutorial Attributes',
      'Page published.'         => 'Tutorial published.',
      'Page updated.'           => 'Tutorial updated.',
      'Page scheduled.'         => 'Tutorial scheduled.',
      'Page draft updated.'     => 'Tutorial draft updated.',
      'Page saved.'             => 'Tutorial saved.',
      'Page submitted.'         => 'Tutorial submitted.',
      'Page reverted to draft.' => 'Tutorial reverted to draft.',
    ];

    if (isset($replacements[$text])) {
      return $replacements[$text];
    }

    return $translated;
  }

  /**
   * Handle plural forms (ngettext) for Pages → Tutorials.
   */
  public function rename_pages_to_tutorials_ngettext($translated, $single, $plural, $number, $domain) {
    if (!is_admin()) return $translated;

    if ($single === '%s page' || $single === '%s Page') {
      return sprintf(($number === 1) ? '%s Tutorial' : '%s Tutorials', $number);
    }

    return $translated;
  }

  /**
   * Directly patch the $menu and $submenu globals after Pressbooks SideBar
   * has finished rebuilding menus (priority 999).
   * Also repositions Tutorial Analytics immediately after Tutorials.
   */
  public function rename_pages_menu_globals() {
    global $menu, $submenu;

    if (!is_array($menu)) return;

    // Rename "Pages" in the top-level $menu array
    foreach ($menu as &$item) {
      if (isset($item[2]) && $item[2] === 'edit.php?post_type=page') {
        $item[0] = 'Tutorials';
        break;
      }
    }
    unset($item);

    // Rename submenu items
    if (isset($submenu['edit.php?post_type=page']) && is_array($submenu['edit.php?post_type=page'])) {
      foreach ($submenu['edit.php?post_type=page'] as &$sub) {
        if ($sub[2] === 'edit.php?post_type=page') {
          $sub[0] = 'All Tutorials';
        } elseif ($sub[2] === 'post-new.php?post_type=page') {
          $sub[0] = 'Add Tutorial';
        }
      }
      unset($sub);
    }

    // Update the post type object labels
    $post_type = get_post_type_object('page');
    if ($post_type) {
      $post_type->labels->name               = 'Tutorials';
      $post_type->labels->singular_name      = 'Tutorial';
      $post_type->labels->add_new            = 'Add Tutorial';
      $post_type->labels->add_new_item       = 'Add New Tutorial';
      $post_type->labels->edit_item          = 'Edit Tutorial';
      $post_type->labels->new_item           = 'New Tutorial';
      $post_type->labels->view_item          = 'View Tutorial';
      $post_type->labels->view_items         = 'View Tutorials';
      $post_type->labels->search_items       = 'Search Tutorials';
      $post_type->labels->not_found          = 'No tutorials found';
      $post_type->labels->not_found_in_trash = 'No tutorials found in Trash';
      $post_type->labels->all_items          = 'All Tutorials';
      $post_type->labels->menu_name          = 'Tutorials';
      $post_type->labels->name_admin_bar     = 'Tutorial';
    }

  }

  /**
   * Reorder admin menu via the add_menu_classes filter.
   * This fires as the absolute last filter before the sidebar HTML is rendered,
   * after all uksort/usort processing. Nothing can override this.
   *
   * @param array $menu The full $menu array keyed by numeric positions.
   * @return array Reordered menu array.
   */
  public function reorder_admin_menu($menu) {
    if (!is_array($menu)) return $menu;

    $analytics_slug = 'pbsg-analytics';
    $pages_slug     = 'edit.php?post_type=page';

    // Find analytics item and its key
    $analytics_key  = null;
    $analytics_item = null;
    $pages_key      = null;

    foreach ($menu as $key => $item) {
      if (isset($item[2]) && $item[2] === $analytics_slug) {
        $analytics_key  = $key;
        $analytics_item = $item;
      }
      if (isset($item[2]) && $item[2] === $pages_slug) {
        $pages_key = $key;
      }
    }

    // Only reorder if both exist
    if ($analytics_key === null || $pages_key === null) return $menu;

    // Remove analytics from current position
    unset($menu[$analytics_key]);

    // Rebuild as a sequential array, inserting analytics right after pages
    $new_menu = [];
    foreach ($menu as $key => $item) {
      $new_menu[] = $item;
      if ($key === $pages_key) {
        $new_menu[] = $analytics_item;
      }
    }

    return $new_menu;
  }

  public function register_page_template($templates) {
    $templates[self::TEMPLATE_SLUG] = 'Split Guide (H5P + Tutorial)';
    return $templates;
  }

  public function load_page_template($template) {
    if (!is_page()) return $template;

    $page_id = get_queried_object_id();
    $selected = get_post_meta($page_id, '_wp_page_template', true);

    if ($selected === self::TEMPLATE_SLUG) {
      $plugin_template = plugin_dir_path(__FILE__) . 'templates/' . self::TEMPLATE_SLUG;
      if (file_exists($plugin_template)) return $plugin_template;
    }
    return $template;
  }

  public function add_meta_boxes($post) {
    add_meta_box(
      'pbsg_settings',
      'Split Guide Settings',
      [$this, 'render_metabox'],
      'page',
      'normal',
      'high'
    );
  }

  public function render_metabox($post) {
    wp_nonce_field('pbsg_save_meta', 'pbsg_nonce');

    $steps_json = get_post_meta($post->ID, self::META_STEPS, true);
    if (empty($steps_json)) $steps_json = '[]';

    $decoded = json_decode($steps_json, true);
    if (!is_array($decoded)) {
      $decoded = [];
      $steps_json = '[]';
    }

    $note = get_post_meta($post->ID, self::META_NOTE, true);
    ?>
    <div class="pbsg-metabox">
      <p><strong>Steps</strong> (each step = one H5P quiz + one tutorial source)</p>

      <table class="widefat striped" id="pbsg-steps-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width: 25%;">Step title (optional)</th>
            <th style="width: 22%;">H5P</th>
            <th>Tutorial Source</th>
            <th style="width: 10%;">Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>

      <p style="margin-top:10px;">
        <button type="button" class="button" id="pbsg-add-step">Add Step</button>
      </p>

      <input type="hidden"
             id="pbsg_steps_json"
             name="pbsg_steps_json"
             value="<?php echo esc_attr($steps_json); ?>" />

      <hr style="margin: 14px 0;" />

      <p>
        <label for="pbsg_header_note"><strong>Header Note (optional)</strong></label><br/>
        <input
          type="text"
          id="pbsg_header_note"
          name="pbsg_header_note"
          value="<?php echo esc_attr($note); ?>"
          style="width: 100%;"
          placeholder="Example: If the webpage is not displaying below..."
        />
      </p>

      <p><em>Tip: Click “Add H5P” to pick a quiz. Click “Set Tutorial” to choose URL or upload PDF/slides.</em></p>
    </div>
    <?php
  }

  public function save_meta($post_id, $post) {
    if (!isset($_POST['pbsg_nonce']) || !wp_verify_nonce($_POST['pbsg_nonce'], 'pbsg_save_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    //Update 
    $steps_json = isset($_POST['pbsg_steps_json']) ? wp_unslash($_POST['pbsg_steps_json']) : '[]';
    $steps = json_decode($steps_json, true);

    // Delegate normalization to a pure, unit-testable function
    $clean = PBSG_Steps_Normalizer::normalize($steps);

    update_post_meta($post_id, self::META_STEPS, wp_json_encode($clean));

    $note = isset($_POST['pbsg_header_note']) ? sanitize_text_field($_POST['pbsg_header_note']) : '';
    update_post_meta($post_id, self::META_NOTE, $note);
  }

  public function enqueue_assets() {
    if (!is_page()) return;

    $page_id = get_queried_object_id();
    $selected = get_post_meta($page_id, '_wp_page_template', true);
    if ($selected !== self::TEMPLATE_SLUG) return;

    wp_enqueue_style(
      'pbsg_split_guide_css',
      plugin_dir_url(__FILE__) . 'assets/split-guide.css',
      [],
      '0.5.0'
    );

    $steps_json = get_post_meta( $page_id, '_pbsg_steps_json', true );
    $steps_data = json_decode( $steps_json, true );
    $total_steps = is_array( $steps_data ) ? count( $steps_data ) : 1;

    wp_localize_script( 'pbsg-tracker', 'pbsgTracker', array(
        'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
        'tutorialPageId' => $page_id,
        'totalSteps'     => $total_steps,
    ) ); 
  }

  public function enqueue_admin_assets($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;

    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'page') return;

    add_thickbox();

    // IMPORTANT: enable WP Media Library uploader
    wp_enqueue_media();

    wp_enqueue_script(
      'pbsg_admin_js',
      plugin_dir_url(__FILE__) . 'assets/admin-split-guide.js',
      ['jquery', 'thickbox'],
      '0.5.0',
      true
    );

    // Load admin CSS (and JS if needed)
    wp_enqueue_style(
        'pbsg-admin',
        plugin_dir_url(__FILE__) . 'assets/admin/admin-split-guide.css',
        [],
        '1.0.1' // bump version to bust cache
    );

    wp_localize_script('pbsg_admin_js', 'PBSG_ADMIN', [
      'templateSlug' => self::TEMPLATE_SLUG,
      'metaBoxId'    => 'pbsg_settings',
      'ajaxUrl'      => admin_url('admin-ajax.php'),
      'nonce'        => wp_create_nonce('pbsg_h5p_picker'),
    ]);
  }

  public function ajax_list_h5p() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    global $wpdb;
    $table = $wpdb->prefix . 'h5p_contents';

    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
      wp_send_json_error(['message' => 'H5P table not found. Are you using the standard H5P plugin?']);
    }

    $rows = $wpdb->get_results("SELECT id, title FROM {$table} ORDER BY id DESC LIMIT 300", ARRAY_A);

    $items = array_map(function ($r) {
      return [
        'id' => (int)$r['id'],
        'title' => $r['title'] ? $r['title'] : ('H5P #' . (int)$r['id']),
      ];
    }, $rows ?: []);

    wp_send_json_success(['items' => $items]);
  }
}

new PB_Split_Guide_Plugin();

register_activation_hook( __FILE__, array( 'PBSG_Analytics', 'create_tables' ) );

PBSG_Analytics::init();
PBSG_Analytics_Dashboard::init();
PBSG_Certificate::init();