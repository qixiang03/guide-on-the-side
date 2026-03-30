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
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-roles.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-admin-menu-filter.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-librarian-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-h5p-factory.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-template-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-export-import.php';
require_once plugin_dir_path(__FILE__) . 'class-pbsg-analytics.php';
require_once plugin_dir_path(__FILE__) . 'class-pbsg-analytics-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-certificate.php';
require_once plugin_dir_path(__FILE__) . 'accessibility-dashboard/class-pbsg-accessibility-dashboard.php';


class PB_Split_Guide_Plugin {
  const TEMPLATE_SLUG = 'split-guide-template.php';

  // Meta keys
  const META_STEPS = '_pbsg_steps_json';
  const META_NOTE  = '_pbsg_header_note';
  const META_COVER_ID = '_pbsg_cover_image_id';

  // Structured intro meta keys (Phase 7)
  const META_INTRO_DESC    = '_pbsg_intro_description';
  const META_INTRO_OBJ     = '_pbsg_intro_objectives';
  const META_INTRO_DURATION = '_pbsg_intro_duration';
  const META_INTRO_PREREQS = '_pbsg_intro_prerequisites';

  // Layout meta keys (Stretch Goal 5)
  const META_LEFT_RATIO      = '_pbsg_left_ratio';
  const META_USER_RESIZABLE  = '_pbsg_user_resizable';
  const OPTION_DEFAULT_RATIO = 'pbsg_default_left_ratio';
  const RATIO_MIN = 10;
  const RATIO_MAX = 50;
  const RATIO_DEFAULT = 40;

  // Benchmark meta/option keys (Stretch Goal 5 — Performance Thresholds)
  const META_BENCHMARKS          = '_pbsg_benchmarks';
  const OPTION_BENCHMARK_DEFAULTS = 'pbsg_benchmark_defaults';

  // Hardcoded fallback benchmark defaults (used when no option saved)
  const BENCHMARK_FALLBACKS = [
    'completion_rate_green'  => 70,
    'completion_rate_amber'  => 50,
    'score_green'            => 70,
    'score_amber'            => 50,
    'correct_rate_green'     => 70,
    'correct_rate_amber'     => 50,
    'giveup_low'             => 2,
    'giveup_high'            => 10,
    'retries_low'            => 3,
    'retries_high'           => 8,
    'attention_completion'   => 60,
    'attention_score'        => 50,
  ];

  public function __construct() {
    add_filter('theme_page_templates', [$this, 'register_page_template']);
    add_filter('template_include', [$this, 'load_page_template']);

    add_action('add_meta_boxes_page', [$this, 'add_meta_boxes']);
    add_action('save_post_page', [$this, 'save_meta'], 10, 2);
    add_action('admin_init', [$this, 'maybe_remove_editor']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('wp_ajax_pbsg_list_h5p', [$this, 'ajax_list_h5p']);
    add_action('wp_ajax_pbsg_create_h5p', [$this, 'ajax_create_h5p']);
    add_action('wp_ajax_pbsg_get_h5p_content', [$this, 'ajax_get_h5p_content']);
    add_action('wp_ajax_pbsg_upload_file', [$this, 'ajax_upload_file']);
    add_action('wp_ajax_pbsg_list_tutorials', [$this, 'ajax_list_tutorials']);

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

    //Change Trash to Delete
    add_filter('page_row_actions', [$this, 'pbsg_change_trash_to_delete'], 10, 2);

    add_action('network_admin_menu', [$this, 'pbsg_hide_network_menus'], 999);
    add_action('admin_head', [$this, 'pbsg_hide_admin_ui_css']);
    add_action('network_admin_head', [$this, 'pbsg_hide_admin_ui_css']);
    add_action('admin_footer', [$this, 'pbsg_hide_network_menu_js']);
    add_action('network_admin_footer', [$this, 'pbsg_hide_network_menu_js']);

    add_action('admin_menu', [$this, 'register_admin_menu']);
    add_action('admin_init', [$this, 'redirect_my_books_to_my_tutorials']);
    add_action('admin_bar_menu', [$this, 'change_my_books_admin_bar_link'], 999);

    add_action('admin_menu', [$this, 'pbsg_hide_h5p_menu_for_students'], 999);
    add_action('admin_head', [$this, 'pbsg_hide_h5p_menu_css_for_students']);

    // Stretch Goal 5: Guide settings (layout + benchmarks)
    add_action('admin_init', [$this, 'register_guide_settings']);
    add_action('admin_menu', [$this, 'register_guide_settings_page']);

    // Template picker & export/import (Sprint 7 SG3 & SG4)
    add_action('load-post-new.php',             [$this, 'maybe_redirect_to_template_picker']);
    add_action('admin_menu',                    [$this, 'register_template_picker_page']);
    add_action('wp_ajax_pbsg_get_templates',    [$this, 'ajax_get_templates']);
    add_action('wp_ajax_pbsg_save_as_template', [$this, 'ajax_save_as_template']);
    add_action('wp_ajax_pbsg_create_from_template', [$this, 'ajax_create_from_template']);

    // Ensure template table exists (handles already-active installs)
    add_action('admin_init', ['PBSG_Template_Manager', 'maybe_create_tables'], 1);
  }
  

  public function pbsg_hide_h5p_menu_for_students() {
    if (!is_admin()) return;

    $user = wp_get_current_user();
    $roles = (array) $user->roles;

    if (in_array('student', $roles, true)) {
        remove_menu_page('h5p');
        remove_menu_page('h5p_new');
        remove_menu_page('h5p_libraries');
        remove_menu_page('h5p_content');
    }
  }

  public function pbsg_hide_h5p_menu_css_for_students() {
      if (!is_admin()) return;

      $user = wp_get_current_user();
      $roles = (array) $user->roles;

      if (!in_array('student', $roles, true)) return;
      ?>
      <style>
          #adminmenu a[href*="h5p"],
          #adminmenu .toplevel_page_h5p,
          #adminmenu .menu-top.toplevel_page_h5p {
              display: none !important;
          }
      </style>
      <?php
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

  public function change_my_books_admin_bar_link($wp_admin_bar) {
    if (!is_admin()) return;

    $node = $wp_admin_bar->get_node('my-sites');

    if ($node) {
        $node->href = admin_url('admin.php?page=pbsg-my-tutorials');
        $wp_admin_bar->add_node($node);
    }
  }

  public function pbsg_change_trash_to_delete($actions, $post) {

    if ($post->post_type === 'page') {

        if (isset($actions['trash'])) {
            $actions['trash'] = str_replace('Trash', 'Delete', $actions['trash']);
        }

    }

    return $actions;
  }

  public function redirect_my_books_to_my_tutorials() {
    if (!is_admin()) return;

    if (!current_user_can('read')) return;

    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

    // Pressbooks dashboard / My Books landing page
    if ($page === 'pb_home_page') {
        wp_safe_redirect(admin_url('admin.php?page=pbsg-my-tutorials'));
        exit;
    }
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

  public function pbsg_hide_network_menus() {
    remove_menu_page('pb_network_integrations');
  }

  public function pbsg_hide_admin_ui_css() {
      ?>
      <style>
          #adminmenu a[href*="page=pb_network_integrations"] {
              display: none !important;
          }
      </style>
      <?php
  }

  public function pbsg_hide_network_menu_js() {
      ?>
      <script>
      document.addEventListener('DOMContentLoaded', function () {
          var link = document.querySelector('#adminmenu a[href*="page=pb_network_integrations"]');
          if (link) {
              var li = link.closest('li');
              if (li) li.style.display = 'none';
          }
      });
      </script>
      <?php
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

    // Desired slug order for admin/super-admin.
    // Items not listed here keep their original order at the end.
    // Separators (empty slugs) are preserved in-place around their group.
    $desired_order = [
      'index.php',                   // Dashboard
      'pb_home_page',                // Books (Pressbooks)
      'users.php',                   // Users
      'pbsg-manage-librarians',      // Manage Librarians
      'themes.php',                  // Appearance
      'edit.php?post_type=page',     // Tutorials
      'pbsg-analytics',              // Tutorial Analytics
      'plugins.php',                 // Plugins
      'h5p',                         // H5P Content
      'options-general.php',         // Settings
    ];

    // Index menu items by slug, collecting separators separately
    $by_slug    = [];
    $separators = [];
    foreach ($menu as $key => $item) {
      $slug = $item[2] ?? '';
      if ($slug === '' || strpos($slug, 'separator') === 0) {
        $separators[$key] = $item;
      } else {
        $by_slug[$slug] = $item;
      }
    }

    // Build ordered menu: desired items first, then everything else
    $ordered   = [];
    $placed    = [];

    foreach ($desired_order as $slug) {
      if (isset($by_slug[$slug])) {
        $ordered[] = $by_slug[$slug];
        $placed[$slug] = true;
      }
    }

    // Append any remaining items not in the desired order
    foreach ($by_slug as $slug => $item) {
      if (!isset($placed[$slug])) {
        $ordered[] = $item;
      }
    }

    return $ordered;
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

    public function register_admin_menu() {
    add_menu_page(
      __('My Tutorials', 'pb-split-guide'),
      __('My Tutorials', 'pb-split-guide'),
      'read',
      'pbsg-my-tutorials',
      [$this, 'render_my_tutorials_page'],
      'dashicons-welcome-learn-more',
      3
    );
  }

  public function render_my_tutorials_page() {
    if (!current_user_can('read')) {
      wp_die(__('You do not have permission to view this page.', 'pb-split-guide'));
    }

    $tutorials = $this->get_my_tutorials_data();

    $template = plugin_dir_path(__FILE__) . 'templates/admin-my-tutorials.php';

    if (file_exists($template)) {
      include $template;
    } else {
      echo '<div class="wrap"><h1>My Tutorials</h1><p>Template file not found.</p></div>';
    }
  }

  private function get_my_tutorials_data() {
    $tutorials = [];

    $query_args = [
      'post_type'      => 'page',
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'meta_key'       => '_wp_page_template',
      'meta_value'     => self::TEMPLATE_SLUG,
    ];

    // "My Tutorials" shows only the current user's tutorials for librarians.
    // Admins see all tutorials here (they can manage everything).
    if ( PBSG_Roles::is_librarian() && ! PBSG_Roles::is_admin() ) {
      $query_args['author'] = get_current_user_id();
    }

    $pages = get_posts( $query_args );

    if (empty($pages)) {
      return $tutorials;
    }

    foreach ($pages as $page) {
      $post_id = (int) $page->ID;

      $cover_id  = (int) get_post_meta($post_id, self::META_COVER_ID, true);
      $cover_url = '';

      if ($cover_id) {
        $cover_url = wp_get_attachment_image_url($cover_id, 'large');
      }

      if (!$cover_url) {
        $cover_url = 'https://via.placeholder.com/1200x675?text=Tutorial';
      }

      $tutorials[] = [
        'title'     => get_the_title($post_id),
        'link'      => get_permalink($post_id),
        'edit_link' => current_user_can('edit_post', $post_id) ? get_edit_post_link($post_id) : '',
        'cover'     => $cover_url,
      ];
    }

    return $tutorials;
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
    $cover_image_id  = (int) get_post_meta($post->ID, self::META_COVER_ID, true);
    $cover_image_url = $cover_image_id ? wp_get_attachment_image_url($cover_image_id, 'large') : '';

    // Structured intro fields
    $intro_desc    = get_post_meta($post->ID, self::META_INTRO_DESC, true);
    $intro_obj_raw = get_post_meta($post->ID, self::META_INTRO_OBJ, true);
    $intro_objectives = is_string($intro_obj_raw) ? json_decode($intro_obj_raw, true) : [];
    if (!is_array($intro_objectives)) $intro_objectives = [];
    $intro_duration = get_post_meta($post->ID, self::META_INTRO_DURATION, true);
    $intro_prereqs  = get_post_meta($post->ID, self::META_INTRO_PREREQS, true);

    // Fallback: if structured intro is empty but post_content has text, pre-fill description
    if (empty($intro_desc)) {
      $post_content = get_post_field('post_content', $post->ID);
      $stripped = trim(wp_strip_all_tags($post_content));
      if ($stripped !== '') {
        $intro_desc = $stripped;
      }
    }

    // Layout settings (Stretch Goal 5)
    $site_default_ratio = (int) get_option(self::OPTION_DEFAULT_RATIO, self::RATIO_DEFAULT);
    $site_default_ratio = max(self::RATIO_MIN, min(self::RATIO_MAX, $site_default_ratio));
    $per_guide_ratio    = get_post_meta($post->ID, self::META_LEFT_RATIO, true);
    $use_site_default   = ($per_guide_ratio === '' || $per_guide_ratio === false);
    $effective_ratio    = $use_site_default ? $site_default_ratio : max(self::RATIO_MIN, min(self::RATIO_MAX, (int) $per_guide_ratio));
    $user_resizable     = get_post_meta($post->ID, self::META_USER_RESIZABLE, true) === '1';

    $h5p_available = PBSG_H5P_Factory::is_h5p_available();
    $plugin_url = plugin_dir_url(__FILE__);
    ?>
    <?php // CSS + JS loaded via wp_enqueue_style / wp_enqueue_script in enqueue_admin_assets() ?>
    <?php // Inline fallback: ensures layout works even if external CSS fails to load ?>
    <style>
      .pbsg-metabox .pbsg-hidden { display: none !important; }
      .pbsg-metabox .pbsg-intro-section { background: #fff; border: 1px solid #E0E0E0; border-radius: 8px; margin-bottom: 20px; overflow: hidden; }
      .pbsg-metabox .pbsg-section-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: #F8F8F8; border-bottom: 1px solid #F1F1F1; cursor: pointer; }
      .pbsg-metabox .pbsg-section-header-left { display: flex; align-items: center; gap: 10px; }
      .pbsg-metabox .pbsg-intro-body { padding: 20px; }
      .pbsg-metabox .pbsg-intro-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
      .pbsg-metabox .pbsg-intro-row { display: flex; gap: 14px; }
      .pbsg-metabox .pbsg-field { margin-bottom: 14px; }
      .pbsg-metabox .pbsg-field-label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 4px; }
      .pbsg-metabox .pbsg-field input[type="text"],
      .pbsg-metabox .pbsg-field input[type="url"],
      .pbsg-metabox .pbsg-field textarea { width: 100%; padding: 8px 12px; border: 1px solid #E0E0E0; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
      .pbsg-metabox .pbsg-field--half { flex: 1; min-width: 160px; }
      .pbsg-metabox .pbsg-objectives-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 8px; }
      .pbsg-metabox .pbsg-objective-row { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: #F8F8F8; border: 1px solid #F1F1F1; border-radius: 4px; border-left: 3px solid #517E1B; }
      .pbsg-metabox .pbsg-objective-input { flex: 1; border: none !important; background: transparent !important; font-size: 14px; padding: 4px 0 !important; box-shadow: none !important; }
      .pbsg-metabox .pbsg-objective-remove { background: none; border: none; color: #646970; cursor: pointer; font-size: 16px; }
      .pbsg-metabox .pbsg-steps-container { display: flex; flex-direction: column; gap: 16px; }
      .pbsg-metabox .pbsg-step-card { background: #fff; border: 1px solid #E0E0E0; border-radius: 8px; overflow: hidden; }
      .pbsg-metabox .pbsg-step-header { display: flex; align-items: center; gap: 12px; padding: 14px 20px; background: #F8F8F8; border-bottom: 1px solid #F1F1F1; }
      .pbsg-metabox .pbsg-step-body { display: grid; grid-template-columns: 1fr 1fr; }
      .pbsg-metabox .pbsg-step-card--collapsed .pbsg-step-body { display: none; }
      .pbsg-metabox .pbsg-panel { padding: 20px; }
      .pbsg-metabox .pbsg-panel-quiz { border-right: 1px solid #F1F1F1; }
      .pbsg-metabox .pbsg-add-step-btn { display: flex; align-items: center; gap: 8px; padding: 14px 28px; background: #fff; border: 2px dashed #E0E0E0; border-radius: 8px; font-size: 15px; font-weight: 600; color: #646970; cursor: pointer; width: 100%; justify-content: center; }
      .pbsg-metabox .pbsg-cover-preview.pbsg-hidden { display: none !important; }
      @media (max-width: 900px) { .pbsg-metabox .pbsg-intro-grid, .pbsg-metabox .pbsg-step-body { grid-template-columns: 1fr; } }
    </style>
    <div class="pbsg-metabox">

      <!-- ══════════ Tutorial Introduction Section ══════════ -->
      <div id="pbsg-intro-section" class="pbsg-intro-section">

        <div id="pbsg-intro-toggle" class="pbsg-section-header">
          <div class="pbsg-section-header-left">
            <span class="pbsg-section-icon">&#x1F4DD;</span>
            <span class="pbsg-section-title">Tutorial Introduction</span>
            <span class="pbsg-badge pbsg-badge--info">What students see before starting</span>
          </div>
          <span id="pbsg-intro-chevron" class="pbsg-chevron">&#x25BC;</span>
        </div>

        <div id="pbsg-intro-body" class="pbsg-intro-body">
          <div class="pbsg-intro-grid">

            <!-- Left: Intro Fields -->
            <div class="pbsg-intro-fields">
              <div class="pbsg-field">
                <label for="pbsg_intro_description" class="pbsg-field-label">Description</label>
                <textarea
                  id="pbsg_intro_description"
                  name="pbsg_intro_description"
                  rows="3"
                  placeholder="Brief overview of what this tutorial covers..."
                ><?php echo esc_textarea($intro_desc); ?></textarea>
              </div>

              <div class="pbsg-field">
                <label class="pbsg-field-label">What Students Will Learn</label>
                <div id="pbsg-objectives-list" class="pbsg-objectives-list">
                  <?php if (!empty($intro_objectives)): ?>
                    <?php foreach ($intro_objectives as $obj): ?>
                      <div class="pbsg-objective-row">
                        <span class="pbsg-objective-check">&#x2713;</span>
                        <input type="text" class="pbsg-objective-input" value="<?php echo esc_attr($obj); ?>" />
                        <button type="button" class="pbsg-objective-remove" title="Remove">&times;</button>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <button type="button" class="pbsg-btn-outline" id="pbsg-add-objective">+ Add Objective</button>
                <input type="hidden" class="pbsg-hidden" id="pbsg_intro_objectives" name="pbsg_intro_objectives"
                       value="<?php echo esc_attr(wp_json_encode($intro_objectives)); ?>" style="display:none" />
              </div>

              <div class="pbsg-intro-row">
                <div class="pbsg-field pbsg-field--half">
                  <label for="pbsg_intro_duration" class="pbsg-field-label">Estimated Duration</label>
                  <input type="text" id="pbsg_intro_duration" name="pbsg_intro_duration"
                         value="<?php echo esc_attr($intro_duration); ?>" placeholder="e.g. 15 minutes" />
                </div>
                <div class="pbsg-field pbsg-field--half">
                  <label for="pbsg_intro_prerequisites" class="pbsg-field-label">
                    Prerequisites <span class="pbsg-field-optional">(optional)</span>
                  </label>
                  <input type="text" id="pbsg_intro_prerequisites" name="pbsg_intro_prerequisites"
                         value="<?php echo esc_attr($intro_prereqs); ?>" placeholder="e.g. A valid UPEI library account" />
                </div>
              </div>
            </div>

            <!-- Right: Cover Image + Header Note -->
            <div class="pbsg-intro-cover-col">
              <div class="pbsg-field">
                <label class="pbsg-field-label">Cover Image <span class="pbsg-field-optional">(optional)</span></label>
                <div class="pbsg-cover-image-box">
                  <img
                    id="pbsg_cover_preview"
                    class="pbsg-cover-preview<?php echo $cover_image_url ? '' : ' pbsg-hidden'; ?>"
                    src="<?php echo esc_url($cover_image_url); ?>"
                    alt=""
                  />
                  <input type="hidden" class="pbsg-hidden" id="pbsg_cover_image_id" name="pbsg_cover_image_id"
                         value="<?php echo esc_attr($cover_image_id); ?>" style="display:none" />
                  <input type="hidden" class="pbsg-hidden" id="pbsg_cover_image_url"
                         value="<?php echo esc_attr($cover_image_url); ?>" style="display:none" />
                  <div class="pbsg-cover-actions">
                    <button type="button" class="button" id="pbsg_pick_cover_image">Choose Image</button>
                    <button type="button" class="button" id="pbsg_clear_cover_image">Clear</button>
                  </div>
                </div>
              </div>

              <div class="pbsg-field">
                <label for="pbsg_header_note" class="pbsg-field-label">
                  Header Note <span class="pbsg-field-optional">(optional)</span>
                </label>
                <input type="text" id="pbsg_header_note" name="pbsg_header_note"
                       value="<?php echo esc_attr($note); ?>"
                       placeholder="Banner text shown to students" />
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- ══════════ Layout Settings Section (Stretch Goal 5) ══════════ -->
      <div id="pbsg-layout-section" class="pbsg-intro-section">

        <div id="pbsg-layout-toggle" class="pbsg-section-header">
          <div class="pbsg-section-header-left">
            <span class="pbsg-section-icon">&#x2194;</span>
            <span class="pbsg-section-title">Layout Settings</span>
            <span class="pbsg-badge pbsg-badge--info">Per-guide customisation</span>
          </div>
          <span id="pbsg-layout-chevron" class="pbsg-chevron">&#x25B6;</span>
        </div>

        <div id="pbsg-layout-body" class="pbsg-intro-body" style="display:none;">

          <!-- 5a: Ratio override -->
          <div class="pbsg-field">
            <label class="pbsg-field-label">Quiz Panel Width</label>

            <div style="display:flex; align-items:center; gap:10px; margin-bottom:12px;">
              <input type="checkbox" id="pbsg_use_default_ratio"
                     <?php checked($use_site_default); ?> style="accent-color:#517E1B;" />
              <label for="pbsg_use_default_ratio" style="font-size:13px; color:#646970;">
                Use site default (currently <strong style="color:#517E1B;"><?php echo esc_html($site_default_ratio); ?>%</strong>)
              </label>
            </div>

            <div id="pbsg_ratio_controls" <?php if ($use_site_default) echo 'style="opacity:0.4; pointer-events:none;"'; ?>>
              <div style="display:flex; align-items:center; gap:14px; margin-bottom:6px;">
                <span style="font-size:12px; color:#646970;">10%</span>
                <input type="range" id="pbsg_left_ratio_slider"
                       min="<?php echo self::RATIO_MIN; ?>"
                       max="<?php echo self::RATIO_MAX; ?>"
                       value="<?php echo esc_attr($effective_ratio); ?>"
                       style="flex:1; accent-color:#517E1B;" />
                <span style="font-size:12px; color:#646970;">50%</span>
                <span id="pbsg_ratio_value" style="
                  font-size:18px; font-weight:700; color:#517E1B;
                  min-width:52px; text-align:center;
                "><?php echo esc_html($effective_ratio); ?>%</span>
              </div>

              <!-- Preview bar -->
              <div id="pbsg_ratio_preview" style="
                display:flex; height:40px; border-radius:6px; overflow:hidden;
                border:1px solid #E0E0E0; margin-top:4px;
              ">
                <div id="pbsg_preview_left" style="
                  width:<?php echo esc_attr($effective_ratio); ?>%;
                  background:#e8f0dc; border-right:3px solid #517E1B;
                  display:flex; align-items:center; justify-content:center;
                  font-size:11px; font-weight:600; color:#517E1B;
                  transition: width 0.2s ease;
                ">Quiz &middot; <span id="pbsg_preview_left_label"><?php echo esc_html($effective_ratio); ?></span>%</div>
                <div style="
                  flex:1; background:#F8F8F8;
                  display:flex; align-items:center; justify-content:center;
                  font-size:11px; font-weight:600; color:#646970;
                ">Content &middot; <span id="pbsg_preview_right_label"><?php echo esc_html(100 - $effective_ratio); ?></span>%</div>
              </div>
            </div>

            <!-- Hidden field carries the actual value to save_meta -->
            <input type="hidden" id="pbsg_left_ratio" name="pbsg_left_ratio"
                   value="<?php echo $use_site_default ? '' : esc_attr($effective_ratio); ?>" />
          </div>

          <div style="height:1px; background:#F1F1F1; margin:18px 0;"></div>

          <!-- 5b: User-resizable toggle -->
          <div class="pbsg-field">
            <label class="pbsg-field-label">User Adjustable</label>

            <div style="
              display:flex; align-items:flex-start; gap:10px;
              padding:12px 16px; background:#F8F8F8;
              border:1px solid #F1F1F1; border-radius:4px;
            ">
              <input type="checkbox" id="pbsg_user_resizable" name="pbsg_user_resizable"
                     value="1" <?php checked($user_resizable); ?>
                     style="margin-top:2px; accent-color:#517E1B;" />
              <div>
                <label for="pbsg_user_resizable" style="font-size:14px; font-weight:500; color:#333;">
                  Allow students to resize panels
                </label>
                <div style="font-size:12px; color:#646970; margin-top:2px;">
                  When enabled, students can drag the divider between the quiz and content
                  panels to adjust the layout. The ratio resets on page reload.
                  The <?php echo self::RATIO_MIN; ?>%&ndash;<?php echo self::RATIO_MAX; ?>% range is always enforced.
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

      <!-- ══════════ Benchmark Settings Section (Stretch Goal 5) ══════════ -->
      <?php
        $site_benchmarks = self::resolve_benchmarks(); // site defaults
        $per_bench_raw   = get_post_meta($post->ID, self::META_BENCHMARKS, true);
        $per_bench       = $per_bench_raw ? json_decode($per_bench_raw, true) : [];
        if (!is_array($per_bench)) $per_bench = [];
        $use_site_benchmarks = empty($per_bench);
      ?>
      <div id="pbsg-benchmark-section" class="pbsg-intro-section">

        <div id="pbsg-benchmark-toggle" class="pbsg-section-header">
          <div class="pbsg-section-header-left">
            <span class="pbsg-section-icon">&#x1F4CA;</span>
            <span class="pbsg-section-title">Benchmark Settings</span>
            <span class="pbsg-badge pbsg-badge--info">Performance thresholds for analytics</span>
          </div>
          <span id="pbsg-benchmark-chevron" class="pbsg-chevron">&#x25B6;</span>
        </div>

        <div id="pbsg-benchmark-body" class="pbsg-intro-body" style="display:none;">

          <div class="pbsg-field">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
              <input type="checkbox" id="pbsg_use_site_benchmarks"
                     <?php checked($use_site_benchmarks); ?> style="accent-color:#517E1B;" />
              <label for="pbsg_use_site_benchmarks" style="font-size:13px; color:#646970;">
                Use site defaults (set by admin in
                <a href="<?php echo esc_url(admin_url('admin.php?page=pbsg-guide-settings')); ?>">Guide Settings</a>)
              </label>
            </div>
          </div>

          <div id="pbsg_benchmark_controls" <?php if ($use_site_benchmarks) echo 'style="opacity:0.4; pointer-events:none;"'; ?>>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

              <?php
              $bench_fields = [
                ['label' => 'Completion Rate',  'prefix' => 'completion_rate', 'type' => 'percent',  'desc' => 'Badge colours for tutorial completion rate'],
                ['label' => 'Tutorial Score',   'prefix' => 'score',           'type' => 'percent',  'desc' => 'Badge colours for average quiz score'],
                ['label' => 'Correct Rate',     'prefix' => 'correct_rate',    'type' => 'percent',  'desc' => 'Badge colours for per-question correct rate'],
                ['label' => 'Give-up Count',    'prefix' => 'giveup',          'type' => 'inverse',  'desc' => 'Lower is better — high give-ups get flagged'],
                ['label' => 'Max Retries',      'prefix' => 'retries',         'type' => 'inverse',  'desc' => 'Lower is better — high retries get flagged'],
              ];

              foreach ($bench_fields as $bf):
                if ($bf['type'] === 'percent'):
                  $green_key = $bf['prefix'] . '_green';
                  $amber_key = $bf['prefix'] . '_amber';
                  $green_val = isset($per_bench[$green_key]) ? $per_bench[$green_key] : '';
                  $amber_val = isset($per_bench[$amber_key]) ? $per_bench[$amber_key] : '';
                  $green_ph  = $site_benchmarks[$green_key];
                  $amber_ph  = $site_benchmarks[$amber_key];
              ?>
              <div class="pbsg-bench-group" style="padding:12px; background:#F8F8F8; border:1px solid #F1F1F1; border-radius:4px;">
                <label style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;"><?php echo esc_html($bf['label']); ?></label>
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#517E1B;"></span>
                  <span style="font-size:12px; width:60px;">Green &ge;</span>
                  <input type="number" class="pbsg-bench-override" data-key="<?php echo esc_attr($green_key); ?>"
                         value="<?php echo esc_attr($green_val); ?>"
                         placeholder="<?php echo esc_attr($green_ph); ?>"
                         min="0" max="100" style="width:60px; font-size:13px;" />
                  <span style="font-size:12px;">%</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#D4A017;"></span>
                  <span style="font-size:12px; width:60px;">Amber &ge;</span>
                  <input type="number" class="pbsg-bench-override" data-key="<?php echo esc_attr($amber_key); ?>"
                         value="<?php echo esc_attr($amber_val); ?>"
                         placeholder="<?php echo esc_attr($amber_ph); ?>"
                         min="0" max="100" style="width:60px; font-size:13px;" />
                  <span style="font-size:12px;">%</span>
                </div>
                <div style="font-size:11px; color:#646970; margin-top:4px;"><?php echo esc_html($bf['desc']); ?></div>
              </div>

              <?php else: // inverse
                $low_key  = $bf['prefix'] . '_low';
                $high_key = $bf['prefix'] . '_high';
                $low_val  = isset($per_bench[$low_key]) ? $per_bench[$low_key] : '';
                $high_val = isset($per_bench[$high_key]) ? $per_bench[$high_key] : '';
                $low_ph   = $site_benchmarks[$low_key];
                $high_ph  = $site_benchmarks[$high_key];
              ?>
              <div class="pbsg-bench-group" style="padding:12px; background:#F8F8F8; border:1px solid #F1F1F1; border-radius:4px;">
                <label style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;"><?php echo esc_html($bf['label']); ?></label>
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#517E1B;"></span>
                  <span style="font-size:12px; width:60px;">Green &le;</span>
                  <input type="number" class="pbsg-bench-override" data-key="<?php echo esc_attr($low_key); ?>"
                         value="<?php echo esc_attr($low_val); ?>"
                         placeholder="<?php echo esc_attr($low_ph); ?>"
                         min="0" style="width:60px; font-size:13px;" />
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                  <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#8C2004;"></span>
                  <span style="font-size:12px; width:60px;">Red &gt;</span>
                  <input type="number" class="pbsg-bench-override" data-key="<?php echo esc_attr($high_key); ?>"
                         value="<?php echo esc_attr($high_val); ?>"
                         placeholder="<?php echo esc_attr($high_ph); ?>"
                         min="0" style="width:60px; font-size:13px;" />
                </div>
                <div style="font-size:11px; color:#646970; margin-top:4px;"><?php echo esc_html($bf['desc']); ?></div>
              </div>
              <?php endif; endforeach; ?>

              <!-- Needs Attention Triggers -->
              <div class="pbsg-bench-group" style="padding:12px; background:#F8F8F8; border:1px solid #F1F1F1; border-radius:4px;">
                <label style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;">&#x26A0; Needs Attention</label>
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                  <span style="font-size:12px; width:100px;">Completion &lt;</span>
                  <input type="number" class="pbsg-bench-override" data-key="attention_completion"
                         value="<?php echo esc_attr(isset($per_bench['attention_completion']) ? $per_bench['attention_completion'] : ''); ?>"
                         placeholder="<?php echo esc_attr($site_benchmarks['attention_completion']); ?>"
                         min="0" max="100" style="width:60px; font-size:13px;" />
                  <span style="font-size:12px;">%</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                  <span style="font-size:12px; width:100px;">Avg Score &lt;</span>
                  <input type="number" class="pbsg-bench-override" data-key="attention_score"
                         value="<?php echo esc_attr(isset($per_bench['attention_score']) ? $per_bench['attention_score'] : ''); ?>"
                         placeholder="<?php echo esc_attr($site_benchmarks['attention_score']); ?>"
                         min="0" max="100" style="width:60px; font-size:13px;" />
                  <span style="font-size:12px;">%</span>
                </div>
                <div style="font-size:11px; color:#646970; margin-top:4px;">Tutorials below these thresholds are flagged</div>
              </div>

            </div>

            <p class="description" style="margin-top:12px; font-size:11px; color:#646970;">
              Leave fields blank to inherit the site default. Only fill in values you want to override for this tutorial.
            </p>
          </div>

          <!-- Hidden field carries the per-tutorial benchmark JSON -->
          <input type="hidden" id="pbsg_benchmarks_json" name="pbsg_benchmarks"
                 value="<?php echo esc_attr($per_bench_raw ?: ''); ?>" />

        </div>
      </div>

      <!-- ══════════ Steps Section ══════════ -->
      <div id="pbsg-steps-container" class="pbsg-steps-container">
        <!-- Steps are rendered by JS -->
      </div>

      <div class="pbsg-add-step-area" style="display:flex; gap:10px; align-items:center; margin-top:12px;">
        <button type="button" id="pbsg-add-step" class="pbsg-add-step-btn">
          <span class="pbsg-add-step-plus">+</span>
          Add Page
        </button>
        <button type="button" class="button" id="pbsg-save-as-template">Save as Template</button>
      </div>

      <input type="hidden" class="pbsg-hidden"
             id="pbsg_steps_json"
             name="pbsg_steps_json"
             value="<?php echo esc_attr($steps_json); ?>" style="display:none" />

      <?php if (!$h5p_available): ?>
        <div class="notice notice-warning inline" style="margin-top:12px;">
          <p><strong>H5P plugin not detected.</strong> Inline quiz authoring requires the H5P plugin.
          You can still link existing H5P content by ID, or install the H5P plugin to enable inline quiz creation.</p>
        </div>
      <?php endif; ?>
    </div>
    <?php
  }

  // ══════════ Stretch Goal 5: Guide Settings (Admin — Layout + Benchmarks) ══════════

  public function register_guide_settings() {
    register_setting('pbsg_guide_settings', self::OPTION_DEFAULT_RATIO, [
      'type'              => 'integer',
      'sanitize_callback' => [$this, 'sanitize_ratio'],
      'default'           => self::RATIO_DEFAULT,
    ]);
    register_setting('pbsg_guide_settings', self::OPTION_BENCHMARK_DEFAULTS, [
      'type'              => 'string',
      'sanitize_callback' => [$this, 'sanitize_benchmark_defaults'],
      'default'           => wp_json_encode(self::BENCHMARK_FALLBACKS),
    ]);
  }

  public function sanitize_ratio($value) {
    $v = absint($value);
    return max(self::RATIO_MIN, min(self::RATIO_MAX, $v));
  }

  /**
   * Sanitize benchmark defaults — validate each field as a non-negative number.
   */
  public function sanitize_benchmark_defaults($value) {
    $decoded = is_string($value) ? json_decode($value, true) : $value;
    if (!is_array($decoded)) $decoded = [];
    $clean = [];
    foreach (self::BENCHMARK_FALLBACKS as $key => $fallback) {
      $clean[$key] = isset($decoded[$key]) ? max(0, intval($decoded[$key])) : $fallback;
    }
    return wp_json_encode($clean);
  }

  /**
   * Resolve effective benchmarks for a given tutorial.
   * Per-tutorial override → site default → hardcoded fallback.
   */
  public static function resolve_benchmarks($tutorial_id = 0) {
    // Start with hardcoded fallbacks
    $benchmarks = self::BENCHMARK_FALLBACKS;

    // Layer 2: site-wide defaults
    $site_raw = get_option(self::OPTION_BENCHMARK_DEFAULTS, '');
    if ($site_raw) {
      $site = json_decode($site_raw, true);
      if (is_array($site)) {
        foreach ($benchmarks as $key => $val) {
          if (isset($site[$key])) $benchmarks[$key] = intval($site[$key]);
        }
      }
    }

    // Layer 3: per-tutorial overrides (if provided)
    if ($tutorial_id > 0) {
      $per_raw = get_post_meta($tutorial_id, self::META_BENCHMARKS, true);
      if ($per_raw) {
        $per = json_decode($per_raw, true);
        if (is_array($per) && !empty($per)) {
          foreach ($benchmarks as $key => $val) {
            if (isset($per[$key]) && $per[$key] !== '') {
              $benchmarks[$key] = intval($per[$key]);
            }
          }
        }
      }
    }

    return $benchmarks;
  }

  public function register_guide_settings_page() {
    add_submenu_page(
      'pbsg-my-tutorials',
      __('Guide Settings', 'pb-split-guide'),
      __('Guide Settings', 'pb-split-guide'),
      'manage_options',
      'pbsg-guide-settings',
      [$this, 'render_guide_settings_page']
    );
  }

  public function render_guide_settings_page() {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have permission to access this page.', 'pb-split-guide'));
    }

    $current_ratio = (int) get_option(self::OPTION_DEFAULT_RATIO, self::RATIO_DEFAULT);
    $current_ratio = max(self::RATIO_MIN, min(self::RATIO_MAX, $current_ratio));

    $bench = self::resolve_benchmarks(); // site defaults (no tutorial ID)
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Guide Settings', 'pb-split-guide'); ?></h1>
      <p class="description" style="margin-bottom:20px;">
        Site-wide defaults for tutorial layout and analytics benchmarks. Librarians can override benchmarks per tutorial.
      </p>

      <form method="post" action="options.php">
        <?php settings_fields('pbsg_guide_settings'); ?>

        <!-- ═══ Section 1: Layout ═══ -->
        <div class="pbsg-admin-settings-card" style="
          background: #fff; border: 1px solid #E0E0E0; border-radius: 8px;
          padding: 24px; max-width: 720px; margin-bottom: 24px;
        ">
          <h2 style="margin-top:0; font-size:18px;">&#x2194; Default Panel Layout</h2>
          <p class="description" style="margin-bottom: 16px;">
            Sets the default left/right ratio for all new tutorials. Librarians can override this per guide.
          </p>

          <div style="display:flex; align-items:center; gap:14px; margin-bottom:6px;">
            <span style="font-size:12px; color:#646970;">10%</span>
            <input type="range" id="pbsg_admin_ratio_slider"
                   name="<?php echo esc_attr(self::OPTION_DEFAULT_RATIO); ?>"
                   min="<?php echo self::RATIO_MIN; ?>"
                   max="<?php echo self::RATIO_MAX; ?>"
                   value="<?php echo esc_attr($current_ratio); ?>"
                   style="flex:1; accent-color:#517E1B;" />
            <span style="font-size:12px; color:#646970;">50%</span>
            <span id="pbsg_admin_ratio_value" style="
              font-size:18px; font-weight:700; color:#517E1B;
              min-width:52px; text-align:center;
            "><?php echo esc_html($current_ratio); ?>%</span>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:11px; color:#646970; margin-bottom:14px;">
            <span>&larr; Mostly content</span>
            <span>Equal split &rarr;</span>
          </div>

          <div id="pbsg_admin_preview" style="
            display:flex; height:48px; border-radius:6px; overflow:hidden; border:1px solid #E0E0E0;
          ">
            <div id="pbsg_admin_preview_left" style="
              width:<?php echo esc_attr($current_ratio); ?>%;
              background:#e8f0dc; border-right:3px solid #517E1B;
              display:flex; align-items:center; justify-content:center;
              font-size:12px; font-weight:600; color:#517E1B; transition: width 0.2s ease;
            ">Quiz &middot; <span id="pbsg_admin_left_label"><?php echo esc_html($current_ratio); ?></span>%</div>
            <div style="
              flex:1; background:#F8F8F8;
              display:flex; align-items:center; justify-content:center;
              font-size:12px; font-weight:600; color:#646970;
            ">Tutorial Content &middot; <span id="pbsg_admin_right_label"><?php echo esc_html(100 - $current_ratio); ?></span>%</div>
          </div>
        </div>

        <!-- ═══ Section 2: Benchmark Defaults ═══ -->
        <div class="pbsg-admin-settings-card" style="
          background: #fff; border: 1px solid #E0E0E0; border-radius: 8px;
          padding: 24px; max-width: 720px; margin-bottom: 24px;
        ">
          <h2 style="margin-top:0; font-size:18px;">&#x1F4CA; Default Performance Benchmarks</h2>
          <p class="description" style="margin-bottom: 16px;">
            These thresholds determine badge colours and &ldquo;Needs Attention&rdquo; flags on the analytics dashboard.
            Librarians can override these per tutorial for harder or easier guides.
          </p>

          <!-- Hidden field carries the JSON blob -->
          <input type="hidden" id="pbsg_benchmark_defaults_json"
                 name="<?php echo esc_attr(self::OPTION_BENCHMARK_DEFAULTS); ?>"
                 value="<?php echo esc_attr(wp_json_encode($bench)); ?>" />

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

            <!-- Completion Rate -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">Completion Rate Badges</label>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#517E1B;"></span>
                <span style="font-size:12px; width:80px;">Green &ge;</span>
                <input type="number" class="pbsg-bench-input" data-key="completion_rate_green"
                       value="<?php echo esc_attr($bench['completion_rate_green']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#D4A017;"></span>
                <span style="font-size:12px; width:80px;">Amber &ge;</span>
                <input type="number" class="pbsg-bench-input" data-key="completion_rate_amber"
                       value="<?php echo esc_attr($bench['completion_rate_amber']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="font-size:11px; color:#646970; margin-top:4px;">Below amber = red badge</div>
            </div>

            <!-- Tutorial Score -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">Tutorial Score Badges</label>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#517E1B;"></span>
                <span style="font-size:12px; width:80px;">Green &ge;</span>
                <input type="number" class="pbsg-bench-input" data-key="score_green"
                       value="<?php echo esc_attr($bench['score_green']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#D4A017;"></span>
                <span style="font-size:12px; width:80px;">Amber &ge;</span>
                <input type="number" class="pbsg-bench-input" data-key="score_amber"
                       value="<?php echo esc_attr($bench['score_amber']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="font-size:11px; color:#646970; margin-top:4px;">Below amber = red badge</div>
            </div>

            <!-- Correct Rate -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">Correct Rate Badges</label>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#517E1B;"></span>
                <span style="font-size:12px; width:80px;">Green &ge;</span>
                <input type="number" class="pbsg-bench-input" data-key="correct_rate_green"
                       value="<?php echo esc_attr($bench['correct_rate_green']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#D4A017;"></span>
                <span style="font-size:12px; width:80px;">Amber &ge;</span>
                <input type="number" class="pbsg-bench-input" data-key="correct_rate_amber"
                       value="<?php echo esc_attr($bench['correct_rate_amber']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="font-size:11px; color:#646970; margin-top:4px;">Below amber = red badge</div>
            </div>

            <!-- Give-ups (inverse) -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">Give-up Count Badges</label>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#517E1B;"></span>
                <span style="font-size:12px; width:80px;">Green &le;</span>
                <input type="number" class="pbsg-bench-input" data-key="giveup_low"
                       value="<?php echo esc_attr($bench['giveup_low']); ?>"
                       min="0" style="width:60px;" />
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#8C2004;"></span>
                <span style="font-size:12px; width:80px;">Red &gt;</span>
                <input type="number" class="pbsg-bench-input" data-key="giveup_high"
                       value="<?php echo esc_attr($bench['giveup_high']); ?>"
                       min="0" style="width:60px;" />
              </div>
              <div style="font-size:11px; color:#646970; margin-top:4px;">Lower is better (inverse metric)</div>
            </div>

            <!-- Max Retries (inverse) -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">Max Retries Badges</label>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#517E1B;"></span>
                <span style="font-size:12px; width:80px;">Green &le;</span>
                <input type="number" class="pbsg-bench-input" data-key="retries_low"
                       value="<?php echo esc_attr($bench['retries_low']); ?>"
                       min="0" style="width:60px;" />
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#8C2004;"></span>
                <span style="font-size:12px; width:80px;">Red &gt;</span>
                <input type="number" class="pbsg-bench-input" data-key="retries_high"
                       value="<?php echo esc_attr($bench['retries_high']); ?>"
                       min="0" style="width:60px;" />
              </div>
              <div style="font-size:11px; color:#646970; margin-top:4px;">Lower is better (inverse metric)</div>
            </div>

            <!-- Needs Attention Triggers -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600; font-size:13px; display:block; margin-bottom:8px;">&#x26A0; Needs Attention Triggers</label>
              <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                <span style="font-size:12px; width:120px;">Completion &lt;</span>
                <input type="number" class="pbsg-bench-input" data-key="attention_completion"
                       value="<?php echo esc_attr($bench['attention_completion']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:12px; width:120px;">Avg Score &lt;</span>
                <input type="number" class="pbsg-bench-input" data-key="attention_score"
                       value="<?php echo esc_attr($bench['attention_score']); ?>"
                       min="0" max="100" style="width:60px;" />
                <span style="font-size:12px;">%</span>
              </div>
              <div style="font-size:11px; color:#646970; margin-top:4px;">Tutorials below these are flagged on the dashboard</div>
            </div>

          </div>
        </div>

        <!-- Save -->
        <div style="max-width:720px; display:flex; gap:10px;">
          <?php submit_button(__('Save Settings', 'pb-split-guide'), 'primary', 'submit', false); ?>
          <button type="button" class="button" id="pbsg_admin_reset"
                  style="height:30px;">Reset All to Defaults</button>
        </div>
      </form>

      <script>
      (function(){
        /* ── Layout slider ── */
        var slider = document.getElementById('pbsg_admin_ratio_slider');
        var valEl  = document.getElementById('pbsg_admin_ratio_value');
        var leftL  = document.getElementById('pbsg_admin_left_label');
        var rightL = document.getElementById('pbsg_admin_right_label');
        var prevL  = document.getElementById('pbsg_admin_preview_left');

        slider.addEventListener('input', function(){
          var v = parseInt(slider.value, 10);
          valEl.textContent = v + '%';
          leftL.textContent = v;
          rightL.textContent = 100 - v;
          prevL.style.width = v + '%';
        });

        /* ── Benchmark inputs → hidden JSON field sync ── */
        var benchHidden = document.getElementById('pbsg_benchmark_defaults_json');
        var benchInputs = document.querySelectorAll('.pbsg-bench-input');

        function syncBenchJSON() {
          var obj = {};
          benchInputs.forEach(function(el) {
            obj[el.getAttribute('data-key')] = parseInt(el.value, 10) || 0;
          });
          benchHidden.value = JSON.stringify(obj);
        }

        benchInputs.forEach(function(el) {
          el.addEventListener('input', syncBenchJSON);
        });

        /* ── Reset button ── */
        var defaults = <?php echo wp_json_encode(self::BENCHMARK_FALLBACKS); ?>;
        document.getElementById('pbsg_admin_reset').addEventListener('click', function(){
          slider.value = <?php echo self::RATIO_DEFAULT; ?>;
          slider.dispatchEvent(new Event('input'));
          benchInputs.forEach(function(el) {
            var key = el.getAttribute('data-key');
            if (defaults[key] !== undefined) el.value = defaults[key];
          });
          syncBenchJSON();
        });
      })();
      </script>
    </div>
    <?php
  }

  public function save_meta($post_id, $post) {
    if (!isset($_POST['pbsg_nonce']) || !wp_verify_nonce($_POST['pbsg_nonce'], 'pbsg_save_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $steps_json = isset($_POST['pbsg_steps_json']) ? wp_unslash($_POST['pbsg_steps_json']) : '[]';
    $steps = json_decode($steps_json, true);

    $clean = PBSG_Steps_Normalizer::normalize($steps);

    // Auto-create H5P content for steps that have quiz data but no h5p_id
    $post_title = get_the_title($post_id);
    $h5p_errors = [];

    foreach ($clean as $idx => &$step) {
      if (!empty($step['quiz']) && PBSG_H5P_Factory::is_h5p_available()) {

        // Issue 5: Edit existing H5P content (update in place)
        if (!empty($step['_editing_h5p']) && !empty($step['h5p_id']) && $step['h5p_id'] > 0) {
          $result = PBSG_H5P_Factory::update($step['h5p_id'], $step['quiz']);
          if (is_wp_error($result)) {
            $h5p_errors[] = sprintf('Step %d (update): %s', $idx + 1, $result->get_error_message());
          }
          // h5p_id stays the same

        // Create new H5P content
        } elseif (empty($step['h5p_id']) || $step['h5p_id'] === 0) {
          $h5p_id = PBSG_H5P_Factory::create(
            $step['quiz'],
            $post_title,
            $idx + 1,
            $step['title'] ?? ''
          );

          if (is_wp_error($h5p_id)) {
            $h5p_errors[] = sprintf('Step %d: %s', $idx + 1, $h5p_id->get_error_message());
          } else {
            $step['h5p_id'] = $h5p_id;
          }
        }
      }
      // Strip transient data from stored JSON
      unset($step['quiz'], $step['_editing_h5p']);
    }
    unset($step);

    // Show admin notices for any H5P creation errors
    if (!empty($h5p_errors)) {
      set_transient('pbsg_h5p_errors_' . $post_id, $h5p_errors, 60);
      add_action('admin_notices', function () use ($post_id) {
        $errors = get_transient('pbsg_h5p_errors_' . $post_id);
        if ($errors) {
          delete_transient('pbsg_h5p_errors_' . $post_id);
          echo '<div class="notice notice-warning is-dismissible"><p><strong>PB Split Guide:</strong> Some quizzes could not be created:</p><ul>';
          foreach ($errors as $err) {
            echo '<li>' . esc_html($err) . '</li>';
          }
          echo '</ul></div>';
        }
      });
    }

    update_post_meta($post_id, self::META_STEPS, wp_json_encode($clean));

    $note = isset($_POST['pbsg_header_note']) ? sanitize_text_field($_POST['pbsg_header_note']) : '';
    update_post_meta($post_id, self::META_NOTE, $note);

    $cover_image_id = isset($_POST['pbsg_cover_image_id']) ? absint($_POST['pbsg_cover_image_id']) : 0;

    if ($cover_image_id > 0) {
      update_post_meta($post_id, self::META_COVER_ID, $cover_image_id);
    } else {
      delete_post_meta($post_id, self::META_COVER_ID);
    }

    // Save structured intro fields (Phase 7)
    $intro_desc = isset($_POST['pbsg_intro_description'])
      ? sanitize_textarea_field(wp_unslash($_POST['pbsg_intro_description'])) : '';
    update_post_meta($post_id, self::META_INTRO_DESC, $intro_desc);

    $intro_objectives_raw = isset($_POST['pbsg_intro_objectives'])
      ? wp_unslash($_POST['pbsg_intro_objectives']) : '[]';
    $intro_objectives = json_decode($intro_objectives_raw, true);
    if (!is_array($intro_objectives)) $intro_objectives = [];
    $intro_objectives = array_values(array_filter(array_map('sanitize_text_field', $intro_objectives)));
    update_post_meta($post_id, self::META_INTRO_OBJ, wp_json_encode($intro_objectives));

    $intro_duration = isset($_POST['pbsg_intro_duration'])
      ? sanitize_text_field(wp_unslash($_POST['pbsg_intro_duration'])) : '';
    update_post_meta($post_id, self::META_INTRO_DURATION, $intro_duration);

    $intro_prereqs = isset($_POST['pbsg_intro_prerequisites'])
      ? sanitize_textarea_field(wp_unslash($_POST['pbsg_intro_prerequisites'])) : '';
    update_post_meta($post_id, self::META_INTRO_PREREQS, $intro_prereqs);

    // Save layout settings (Stretch Goal 5)
    $left_ratio_raw = isset($_POST['pbsg_left_ratio']) ? $_POST['pbsg_left_ratio'] : '';
    if ($left_ratio_raw === '' || $left_ratio_raw === false) {
      delete_post_meta($post_id, self::META_LEFT_RATIO);
    } else {
      $left_ratio = max(self::RATIO_MIN, min(self::RATIO_MAX, absint($left_ratio_raw)));
      update_post_meta($post_id, self::META_LEFT_RATIO, $left_ratio);
    }

    $user_resizable = !empty($_POST['pbsg_user_resizable']) ? '1' : '';
    if ($user_resizable === '1') {
      update_post_meta($post_id, self::META_USER_RESIZABLE, '1');
    } else {
      delete_post_meta($post_id, self::META_USER_RESIZABLE);
    }

    // Save benchmark overrides (Stretch Goal 5 — Performance Thresholds)
    $bench_raw = isset($_POST['pbsg_benchmarks']) ? wp_unslash($_POST['pbsg_benchmarks']) : '';
    if (!empty($bench_raw)) {
      $bench_data = json_decode($bench_raw, true);
      if (is_array($bench_data)) {
        // Strip empty values — only store actual overrides
        $bench_clean = [];
        foreach (self::BENCHMARK_FALLBACKS as $key => $fallback) {
          if (isset($bench_data[$key]) && $bench_data[$key] !== '' && $bench_data[$key] !== null) {
            $bench_clean[$key] = max(0, intval($bench_data[$key]));
          }
        }
        if (!empty($bench_clean)) {
          update_post_meta($post_id, self::META_BENCHMARKS, wp_json_encode($bench_clean));
        } else {
          delete_post_meta($post_id, self::META_BENCHMARKS);
        }
      } else {
        delete_post_meta($post_id, self::META_BENCHMARKS);
      }
    } else {
      delete_post_meta($post_id, self::META_BENCHMARKS);
    }

    // Force Split Guide template if this tutorial is using the PB Split Guide fields
    $current_template = get_post_meta($post_id, '_wp_page_template', true);
    $has_split_guide_data = !empty($clean) || !empty($note) || $cover_image_id > 0
      || !empty($intro_desc) || !empty($intro_objectives);

    if (($current_template === '' || $current_template === 'default') && $has_split_guide_data) {
      update_post_meta($post_id, '_wp_page_template', self::TEMPLATE_SLUG);
    }
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

  /**
   * Remove the native WP/Pressbooks editor for Split Guide pages.
   * The structured intro fields replace the TinyMCE editor.
   */
  public function maybe_remove_editor() {
    global $pagenow;
    if (!in_array($pagenow, ['post.php', 'post-new.php'], true)) return;

    if ($pagenow === 'post-new.php') {
      // New pages default to Split Guide template
      remove_post_type_support('page', 'editor');
      return;
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id <= 0) return;

    $template = get_post_meta($post_id, '_wp_page_template', true);
    if ($template === self::TEMPLATE_SLUG) {
      remove_post_type_support('page', 'editor');
    }
  }

  public function enqueue_admin_assets($hook) {
  if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;

  $screen = get_current_screen();
  if (!$screen || $screen->post_type !== 'page') return;

  add_thickbox();
  wp_enqueue_media();

  // SortableJS — optional, loaded independently so it doesn't block the main script
  wp_enqueue_script(
    'sortablejs',
    'https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js',
    [],
    '1.15.6',
    true
  );

  wp_enqueue_script(
    'pbsg_admin_js',
    plugin_dir_url(__FILE__) . 'assets/admin-split-guide.js',
    ['jquery', 'thickbox'],
    '0.7.0',
    true
  );

  wp_enqueue_style(
    'pbsg-admin',
    plugin_dir_url(__FILE__) . 'assets/admin/admin-split-guide.css',
    [],
    '2.1.1'  // bumped to force cache bust
  );

  $current_template = get_post_meta(get_the_ID(), '_wp_page_template', true);
  wp_localize_script('pbsg_admin_js', 'PBSG_ADMIN', [
    'templateSlug'    => self::TEMPLATE_SLUG,
    'metaBoxId'       => 'pbsg_settings',
    'ajaxUrl'         => admin_url('admin-ajax.php'),
    'nonce'           => wp_create_nonce('pbsg_h5p_picker'),
    'templateNonce'   => wp_create_nonce('pbsg_template_picker'),
    'exportNonce'     => wp_create_nonce('pbsg_export_import'),
    'uploadNonce'     => wp_create_nonce('pbsg_upload_file'),
    'tutorialNonce'   => wp_create_nonce('pbsg_list_tutorials'),
    'isNewPage'       => ($hook === 'post-new.php'),
    'currentTemplate' => $current_template,
    'h5pAvailable'    => PBSG_H5P_Factory::is_h5p_available(),
    'maxUploadSize'   => wp_max_upload_size(),
    'maxUploadLabel'  => size_format(wp_max_upload_size()),
  ]);

  // Extra inline script: force the template on Add New Tutorial page.
  if ($hook === 'post-new.php') {
    $template_slug = esc_js(self::TEMPLATE_SLUG);

    wp_add_inline_script('pbsg_admin_js', "
      jQuery(function($){
        function pbsgForceTemplateNow() {
          var \$template = $('#page_template');
          if (!\$template.length) return false;

          var hasOption = \$template.find('option[value=\"{$template_slug}\"]').length > 0;
          if (!hasOption) return false;

          if (\$template.val() !== '{$template_slug}') {
            \$template.val('{$template_slug}').trigger('change');
          }

          return true;
        }

        var tries = 0;
        var timer = setInterval(function() {
          tries++;
          if (pbsgForceTemplateNow() || tries >= 40) {
            clearInterval(timer);
          }
        }, 250);

        $(window).on('load', function() {
          pbsgForceTemplateNow();
        });
      });
    ", 'after');
  }
}

  // ── Template Picker (Sprint 7 SG3 & SG4) ───────────────────────────────
  /**
   * Redirect post-new.php?post_type=page → template picker page.
   */
  public function maybe_redirect_to_template_picker() {
    $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : 'post';
    if ($post_type !== 'page') return;
    if (!current_user_can('edit_pages')) return;
    wp_safe_redirect(admin_url('admin.php?page=pbsg-new-tutorial'));
    exit;
  }

  public function register_template_picker_page() {
    add_submenu_page(
      null,               // hidden — no parent
      'New Tutorial',
      'New Tutorial',
      'edit_pages',
      'pbsg-new-tutorial',
      [$this, 'render_template_picker_page']
    );
  }

  public function render_template_picker_page() {
    if (!current_user_can('edit_pages')) {
      wp_die(__('You do not have permission to access this page.'));
    }
    require plugin_dir_path(__FILE__) . 'templates/admin-new-tutorial.php';
  }

  public function ajax_get_templates() {
    check_ajax_referer('pbsg_template_picker', 'nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error(['message' => 'Forbidden'], 403);
    wp_send_json_success(['templates' => PBSG_Template_Manager::get_templates()]);
  }

  public function ajax_save_as_template() {
    check_ajax_referer('pbsg_template_picker', 'nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error(['message' => 'Forbidden'], 403);

    $post_id     = isset($_POST['post_id'])     ? absint($_POST['post_id'])                                          : 0;
    $name        = isset($_POST['name'])        ? sanitize_text_field(wp_unslash($_POST['name']))                   : '';
    $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description']))        : '';
    $category    = isset($_POST['category'])    ? sanitize_text_field(wp_unslash($_POST['category']))               : '';

    if (!$post_id || !$name) {
      wp_send_json_error(['message' => 'Name and post_id are required.']);
    }
    if (!current_user_can('edit_post', $post_id)) {
      wp_send_json_error(['message' => 'You cannot edit this post.'], 403);
    }

    $steps_json  = isset($_POST['steps_json'])  ? wp_unslash($_POST['steps_json'])                              : null;
    $header_note = isset($_POST['header_note']) ? sanitize_text_field(wp_unslash($_POST['header_note']))        : null;

    $id = PBSG_Template_Manager::save_as_template($post_id, $name, $description, $category, $steps_json, $header_note);
    if (!$id) wp_send_json_error(['message' => 'Could not save template.']);
    wp_send_json_success(['id' => $id, 'message' => 'Template saved successfully.']);
  }

  public function ajax_create_from_template() {
    check_ajax_referer('pbsg_template_picker', 'nonce');
    if (!current_user_can('edit_pages')) wp_send_json_error(['message' => 'Forbidden'], 403);

    $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;
    $title       = isset($_POST['title'])       ? sanitize_text_field(wp_unslash($_POST['title'])) : '';

    $post_id = PBSG_Template_Manager::create_from_template($template_id, $title);
    if (is_wp_error($post_id)) {
      wp_send_json_error(['message' => $post_id->get_error_message()]);
    }
    wp_send_json_success([
      'post_id'  => $post_id,
      'edit_url' => get_edit_post_link($post_id, 'url'),
    ]);
  }

  // ── H5P & File Upload ─────────────────────────────────────────────────

  /**
   * AJAX handler for drag-and-drop file uploads.
   * Uses media_handle_upload() so the file enters the WP media library
   * exactly like the media picker would do it.
   */
  public function ajax_upload_file() {
    check_ajax_referer('pbsg_upload_file', 'nonce');

    if (!current_user_can('upload_files')) {
      wp_send_json_error(['message' => 'You do not have permission to upload files.']);
    }

    if (empty($_FILES['pbsg_file'])) {
      wp_send_json_error(['message' => 'No file received.']);
    }

    // media_handle_upload reads from $_FILES[$key] and handles
    // validation, MIME checks, moving to wp-content/uploads, and
    // creating the attachment post — all in one call.
    $attachment_id = media_handle_upload('pbsg_file', 0);

    if (is_wp_error($attachment_id)) {
      wp_send_json_error(['message' => $attachment_id->get_error_message()]);
    }

    $url      = wp_get_attachment_url($attachment_id);
    $filename = basename(get_attached_file($attachment_id));

    wp_send_json_success([
      'id'       => (int) $attachment_id,
      'url'      => $url,
      'filename' => $filename,
    ]);
  }

  public function ajax_list_h5p() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    if (!current_user_can('view_h5p_contents')) {
      wp_send_json_error(['message' => 'Insufficient permissions']);
    }

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

  public function ajax_create_h5p() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    if (!current_user_can('edit_h5p_contents')) {
      wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $quiz = json_decode(wp_unslash($_POST['quiz'] ?? ''), true);
    if (!$quiz || empty($quiz['type'])) {
      wp_send_json_error(['message' => 'Invalid quiz data']);
    }

    $post_title = sanitize_text_field(wp_unslash($_POST['post_title'] ?? ''));
    $step_index = absint($_POST['step_index'] ?? 0);
    $step_title = sanitize_text_field(wp_unslash($_POST['step_title'] ?? ''));

    $result = PBSG_H5P_Factory::create($quiz, $post_title, $step_index, $step_title);

    if (is_wp_error($result)) {
      wp_send_json_error(['message' => $result->get_error_message()]);
    }

    wp_send_json_success(['h5p_id' => $result]);
  }

  public function ajax_get_h5p_content() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    if (!current_user_can('view_h5p_contents')) {
      wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $h5p_id = absint($_POST['h5p_id'] ?? 0);
    if ($h5p_id <= 0) {
      wp_send_json_error(['message' => 'Invalid H5P ID']);
    }

    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT c.parameters, c.library_id, l.name as library_name
       FROM {$wpdb->prefix}h5p_contents c
       JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id = l.id
       WHERE c.id = %d",
      $h5p_id
    ), ARRAY_A);

    if (!$row) {
      wp_send_json_error(['message' => 'H5P content not found']);
    }

    $quiz = PBSG_H5P_Factory::reverse($row['library_name'], $row['parameters']);

    wp_send_json_success([
      'quiz'    => $quiz,
      'library' => $row['library_name'],
    ]);
  }

  public function ajax_list_tutorials() {
    check_ajax_referer('pbsg_list_tutorials', 'nonce');

    if (!current_user_can('edit_pages')) {
      wp_send_json_error(['message' => 'Permission denied.'], 403);
    }

    $args = [
      'post_type'      => 'page',
      'post_status'    => ['publish', 'private', 'draft', 'pending'],
      'posts_per_page' => 200,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'meta_key'       => '_wp_page_template',
      'meta_value'     => self::TEMPLATE_SLUG,
    ];

    if (class_exists('PBSG_Roles') && PBSG_Roles::is_librarian() && !PBSG_Roles::is_admin()) {
      $args['author'] = get_current_user_id();
    }

    $posts = get_posts($args);
    $data = [];

    foreach ($posts as $p) {
      $data[] = [
        'id'    => $p->ID,
        'title' => get_the_title($p->ID) ?: ('Tutorial #' . $p->ID),
        'url'   => get_permalink($p->ID),
        'status'=> $p->post_status,
      ];
    }

    wp_send_json_success($data);
  }



}

new PB_Split_Guide_Plugin();

register_activation_hook( __FILE__, function() {
  PBSG_Roles::activate();
  PBSG_Analytics::create_tables();
  PBSG_Template_Manager::create_tables();
} );
register_deactivation_hook( __FILE__, array( 'PBSG_Roles', 'deactivate' ) );

PBSG_Roles::init();
PBSG_Analytics::init();
PBSG_Analytics_Dashboard::init();
PBSG_Certificate::init();
PBSG_Admin_Menu_Filter::init();
PBSG_Librarian_Manager::init();
PBSG_Export_Import::init();