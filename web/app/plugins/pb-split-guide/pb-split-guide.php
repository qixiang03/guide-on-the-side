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
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-h5p-usage-map.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-icons.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-embed-check.php';
require_once plugin_dir_path(__FILE__) . 'class-pbsg-analytics.php';
require_once plugin_dir_path(__FILE__) . 'class-pbsg-analytics-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-certificate.php';
require_once plugin_dir_path(__FILE__) . 'accessibility-dashboard/class-pbsg-accessibility-dashboard.php';



class PB_Split_Guide_Plugin {
  /**
   * Plugin version. Used as the asset cache-bust key when `filemtime()` is
   * unavailable (read errors, restricted permissions, sync-conflict quirks).
   * Bump when shipping frontend asset changes.
   */
  const VERSION = '0.5.1';

  const TEMPLATE_SLUG = 'split-guide-template.php';

  /** Some installs (e.g. Pressbooks) store {@see TEMPLATE_SLUG} with a templates/ prefix. */
  public static function is_split_guide_template($value) {
    return $value === self::TEMPLATE_SLUG || $value === 'templates/' . self::TEMPLATE_SLUG;
  }

  // Meta keys
  const META_STEPS = '_pbsg_steps_json';
  const META_NOTE  = '_pbsg_header_note';
  const META_COVER_ID = '_pbsg_cover_image_id';
  const META_CLOSE_URL = '_pbsg_close_tutorial_url';

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

  // Cross-editing & ownership transfer (Sprint 6)
  const OPTION_CROSS_EDIT    = 'pbsg_cross_edit_enabled';
  const OPTION_TRANSFER      = 'pbsg_ownership_transfer_enabled';

  // Style template defaults (WYSIWYG CSS Template System)
  const OPTION_STYLE_DEFAULTS = 'pbsg_style_defaults';
  const STYLE_DEFAULTS = [
      'font_family'   => 'Roboto, sans-serif',
      'heading_font'  => 'Lusitana, serif',
      'font_size'     => '16px',
      'text_color'    => '#333333',
      'accent_color'  => '#8C2004',
      'button_color'  => '#517E1B',
  ];

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
  ];

  public function __construct() {
    add_filter('theme_page_templates', [$this, 'register_page_template']);
    add_filter('template_include', [$this, 'load_page_template']);

    add_action('add_meta_boxes_page', [$this, 'add_meta_boxes']);
    add_action('save_post_page', [$this, 'save_meta'], 10, 2);
    add_action('admin_init', [$this, 'maybe_remove_editor']);
    add_action('edit_form_after_title', [$this, 'render_split_guide_editor_notice']);
    add_filter('use_block_editor_for_post_type', [$this, 'use_block_editor_for_page_tutorial'], 9999, 2);
    add_filter('use_block_editor_for_post', [$this, 'use_block_editor_for_split_guide_post'], 9999, 2);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('wp_ajax_pbsg_list_h5p', [$this, 'ajax_list_h5p']);
    add_action('wp_ajax_pbsg_create_h5p', [$this, 'ajax_create_h5p']);
    add_action('wp_ajax_pbsg_get_h5p_content', [$this, 'ajax_get_h5p_content']);
    add_action('wp_ajax_pbsg_upload_file', [$this, 'ajax_upload_file']);
    add_action('wp_ajax_pbsg_list_tutorials', [$this, 'ajax_list_tutorials']);
    add_action('wp_ajax_pbsg_transfer_ownership', [$this, 'ajax_transfer_ownership']);
    add_action('wp_ajax_pbsg_get_transfer_targets', [$this, 'ajax_get_transfer_targets']);
    add_action('wp_ajax_pbsg_rename_h5p', [$this, 'ajax_rename_h5p']);
    add_action('wp_ajax_pbsg_duplicate_h5p', [$this, 'ajax_duplicate_h5p']);

    // Just-in-time embeddability probe used by split-guide.js before
    // setting iframe.src. Available to logged-out users because tutorials
    // may be viewed publicly. Nonce + SSRF guard enforced in the handler.
    add_action('wp_ajax_pbsg_probe_embed',        [$this, 'ajax_probe_embed']);
    add_action('wp_ajax_nopriv_pbsg_probe_embed', [$this, 'ajax_probe_embed']);

    // Bulk action: Transfer Ownership on the Tutorials (Pages) list table
    add_filter('bulk_actions-edit-page', [$this, 'register_transfer_bulk_action']);
    add_filter('handle_bulk_actions-edit-page', [$this, 'handle_transfer_bulk_action'], 10, 3);
    add_action('admin_notices', [$this, 'transfer_bulk_action_notice']);

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
    add_action('network_admin_menu', [$this, 'register_admin_menu']);
    add_action('admin_init', [$this, 'redirect_my_books_to_my_tutorials']);
    add_action('admin_bar_menu', [$this, 'change_my_books_admin_bar_link'], 999);

    add_action('admin_menu', [$this, 'pbsg_hide_h5p_menu_for_students'], 999);
    add_action('admin_head', [$this, 'pbsg_hide_h5p_menu_css_for_students']);

    // Stretch Goal 5: Guide settings (layout + benchmarks)
    add_action('admin_init', [$this, 'register_guide_settings']);
    add_action('admin_menu', [$this, 'register_guide_settings_page']);
    add_action('network_admin_menu', [$this, 'register_guide_settings_page']);

    // Template picker & export/import (Sprint 7 SG3 & SG4)
    // Priority 5 on admin_init fires BEFORE Pressbooks' redirect_away_from_bad_urls (priority 10),
    // which would otherwise intercept post-new.php?post_type=page and redirect to book_dashboard
    // because 'page' is not in Pressbooks' list_post_types() whitelist.
    add_action('admin_init',                    [$this, 'maybe_redirect_to_template_picker'], 5);
    add_action('admin_menu',                    [$this, 'register_template_picker_page']);
    add_action('network_admin_menu',            [$this, 'register_template_picker_page']);
    add_action('wp_ajax_pbsg_get_templates',    [$this, 'ajax_get_templates']);
    add_action('wp_ajax_pbsg_save_as_template', [$this, 'ajax_save_as_template']);
    add_action('wp_ajax_pbsg_create_from_template', [$this, 'ajax_create_from_template']);

    // Ensure template table exists (handles already-active installs)
    add_action('admin_init', ['PBSG_Template_Manager', 'maybe_create_tables'], 1);

    add_action('template_redirect', [$this, 'handle_error_page']);
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
      'pbsg-my-tutorials',           // My Tutorials
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

    if (self::is_split_guide_template($selected)) {
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

    // Owner metabox and Tutorial Attributes removal — only for Split Guide tutorials
    $template = get_post_meta($post->ID, '_wp_page_template', true);
    if (self::is_split_guide_template($template)) {
      add_meta_box(
        'pbsg_owner_box',
        __('Tutorial Owner', 'pb-split-guide'),
        [$this, 'render_owner_metabox'],
        'page',
        'side',
        'high'
      );

      // Hide Tutorial Attributes (Page Attributes) — not meaningful for tutorials
      remove_meta_box('pageparentdiv', 'page', 'side');
    }
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

    $is_admin = PBSG_Roles::is_admin();
    $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'recent';
    if (!in_array($current_tab, ['recent', 'owned'], true)) {
      $current_tab = 'recent';
    }

    $tutorials = $this->get_my_tutorials_data($current_tab);
    $transfer_enabled = $is_admin || self::is_transfer_enabled();

    $template = plugin_dir_path(__FILE__) . 'templates/admin-my-tutorials.php';

    if (file_exists($template)) {
      include $template;
    } else {
      echo '<div class="wrap"><h1>My Tutorials</h1><p>Template file not found.</p></div>';
    }
  }

  /**
   * Get tutorial data for the "My Tutorials" page.
   *
   * @param string $tab 'recent' for recently worked on, 'owned' for own tutorials.
   * @return array
   */
  private function get_my_tutorials_data( string $tab = 'recent' ) {
    $tutorials = [];
    $current_user_id = get_current_user_id();
    $is_admin = PBSG_Roles::is_admin();

    // Admins see all tutorials regardless of tab
    if ( $is_admin ) {
      $query_args = [
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft', 'pending'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_key'       => '_wp_page_template',
        'meta_value'     => self::TEMPLATE_SLUG,
      ];
    } elseif ( $tab === 'owned' ) {
      // Tab 2: Only tutorials owned by current user
      $query_args = [
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft', 'pending'],
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_key'       => '_wp_page_template',
        'meta_value'     => self::TEMPLATE_SLUG,
        'author'         => $current_user_id,
      ];
    } else {
      // Tab 1 (default): Recently Worked On — own + touched tutorials
      // First get all tutorials, then filter by touched/owned
      $query_args = [
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft', 'pending'],
        'posts_per_page' => -1,
        'meta_key'       => '_wp_page_template',
        'meta_value'     => self::TEMPLATE_SLUG,
      ];
    }

    $pages = get_posts( $query_args );

    if ( empty( $pages ) ) {
      return $tutorials;
    }

    foreach ( $pages as $page ) {
      $post_id  = (int) $page->ID;
      $is_owner = ( (int) $page->post_author === $current_user_id );

      // For "recent" tab (non-admin), filter to owned or touched
      if ( ! $is_admin && $tab === 'recent' ) {
        if ( ! $is_owner ) {
          $editors = get_post_meta( $post_id, '_pbsg_editors', true );
          if ( ! is_array( $editors ) || ! isset( $editors[ $current_user_id ] ) ) {
            continue; // Skip — user hasn't touched this tutorial
          }
        }
      }

      $cover_id  = (int) get_post_meta( $post_id, self::META_COVER_ID, true );
      $cover_url = '';

      if ( $cover_id ) {
        $cover_url = wp_get_attachment_image_url( $cover_id, 'large' );
      }

      if ( ! $cover_url ) {
        $cover_url = 'https://via.placeholder.com/1200x675?text=Tutorial';
      }

      $owner = get_userdata( (int) $page->post_author );
      $owner_name = $owner ? $owner->display_name : __( 'Unknown', 'pb-split-guide' );

      // Get last_edited timestamp for sorting
      $last_edited = '';
      if ( ! $is_admin && $tab === 'recent' ) {
        if ( $is_owner ) {
          $last_edited = $page->post_modified;
        } else {
          $editors = get_post_meta( $post_id, '_pbsg_editors', true );
          if ( is_array( $editors ) && isset( $editors[ $current_user_id ] ) ) {
            $last_edited = $editors[ $current_user_id ]['last_edited'];
          }
        }
      }

      $tutorials[] = [
        'id'          => $post_id,
        'title'       => get_the_title( $post_id ),
        'link'        => get_permalink( $post_id ),
        'edit_link'   => current_user_can( 'edit_post', $post_id ) ? get_edit_post_link( $post_id ) : '',
        'cover'       => $cover_url,
        'owner_name'  => $owner_name,
        'is_owner'    => $is_owner || $is_admin,
        'last_edited' => $last_edited,
        'status'      => $page->post_status,
      ];
    }

    // Sort "recent" tab by last_edited descending
    if ( ! $is_admin && $tab === 'recent' ) {
      usort( $tutorials, function ( $a, $b ) {
        return strcmp( $b['last_edited'], $a['last_edited'] );
      });
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
    $close_tutorial_url = get_post_meta($post->ID, self::META_CLOSE_URL, true);

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

        <button type="button" id="pbsg-intro-toggle" class="pbsg-section-header" aria-expanded="true" aria-controls="pbsg-intro-body">
          <div class="pbsg-section-header-left">
            <span class="pbsg-section-icon"><?php echo pbsg_icon('document'); ?></span>
            <span class="pbsg-section-title">Tutorial Introduction</span>
            <span class="pbsg-badge pbsg-badge--info">What students see before starting</span>
          </div>
          <span id="pbsg-intro-chevron" class="pbsg-chevron"><?php echo pbsg_icon('chevron-down'); ?></span>
        </button>

        <div id="pbsg-intro-body" class="pbsg-intro-body">
          <div class="pbsg-intro-grid">

            <!-- Left: Intro Fields -->
            <div class="pbsg-intro-fields">
              <div class="pbsg-field">
                <label for="pbsg_intro_description" class="pbsg-field-label">Description</label>
                <textarea
                  id="pbsg_intro_description"
                  name="pbsg_intro_description"
                  rows="4"
                  class="pbsg-wysiwyg-target"
                ><?php echo esc_textarea($intro_desc); ?></textarea>
              </div>

              <div class="pbsg-field">
                <label class="pbsg-field-label">What Students Will Learn</label>
                <div id="pbsg-objectives-list" class="pbsg-objectives-list">
                  <?php if (!empty($intro_objectives)): ?>
                    <?php foreach ($intro_objectives as $obj): ?>
                      <div class="pbsg-objective-row">
                        <span class="pbsg-objective-check"><?php echo pbsg_icon('check', 'pbsg-icon--ok'); ?></span>
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
                <div class="pbsg-cover-image-box" title="Recommended: wide landscape (16:9), about 1600×900 px; keep under ~1 MB to avoid heavy compression or distortion.">
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
                    <button type="button" class="button" id="pbsg_pick_cover_image" title="Recommended: 16:9, ~1600×900 px, under ~1 MB">Choose Image</button>
                    <button type="button" class="button" id="pbsg_clear_cover_image">Clear</button>
                  </div>
                </div>
                <p class="description" style="margin-top:8px; font-size:12px; color:#646970;">
                  <strong>Tip:</strong> Use a wide landscape image (16:9), around <strong>1600×900 px</strong>, and keep file size under about <strong>1 MB</strong> so it displays clearly without heavy compression or stretching.
                </p>
              </div>

              <div class="pbsg-field">
                <label for="pbsg_header_note" class="pbsg-field-label">
                  Header Note <span class="pbsg-field-optional">(optional)</span>
                </label>
                <input type="text" id="pbsg_header_note" name="pbsg_header_note"
                       value="<?php echo esc_attr($note); ?>"
                       placeholder="e.g. If the page does not load, open it in a new tab" />
                <p class="description">Shown as a banner above the resource panel on every step.</p>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- ══════════ Layout Settings Section (Stretch Goal 5) ══════════ -->
      <div id="pbsg-layout-section" class="pbsg-intro-section">

        <button type="button" id="pbsg-layout-toggle" class="pbsg-section-header" aria-expanded="false" aria-controls="pbsg-layout-body">
          <div class="pbsg-section-header-left">
            <span class="pbsg-section-icon"><?php echo pbsg_icon('arrow-horizontal'); ?></span>
            <span class="pbsg-section-title">Layout Settings</span>
            <span class="pbsg-badge pbsg-badge--info">Per-guide customisation</span>
          </div>
          <span id="pbsg-layout-chevron" class="pbsg-chevron"><?php echo pbsg_icon('chevron-right'); ?></span>
        </button>

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

        <button type="button" id="pbsg-benchmark-toggle" class="pbsg-section-header" aria-expanded="false" aria-controls="pbsg-benchmark-body">          <div class="pbsg-section-header-left">
            <span class="pbsg-section-icon"><?php echo pbsg_icon('chart-bar'); ?></span>
            <span class="pbsg-section-title">Benchmark Settings</span>
            <span class="pbsg-badge pbsg-badge--info">Performance thresholds for analytics</span>
          </div>
          <span id="pbsg-benchmark-chevron" class="pbsg-chevron"><?php echo pbsg_icon('chevron-right'); ?></span>
        </button>

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

            <?php
              // Helper: resolve per-tutorial value falling back to site default
              $pv = function( $key ) use ( $per_bench, $site_benchmarks ) {
                return isset( $per_bench[ $key ] ) ? $per_bench[ $key ] : $site_benchmarks[ $key ];
              };
            ?>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">

              <!-- 1. Completion Rate -->
              <div class="pbsg-bench-group">
                <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Completion Rate Badges</label>
                <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                  Badge colours for tutorial completion rate.
                </div>
                <div class="pbsg-slider-wrap" data-min="0" data-max="100" data-inverse="0"
                     data-key-low="completion_rate_amber" data-key-high="completion_rate_green"
                     data-default-low="<?php echo esc_attr( $site_benchmarks['completion_rate_amber'] ); ?>"
                     data-default-high="<?php echo esc_attr( $site_benchmarks['completion_rate_green'] ); ?>">
                  <div class="pbsg-slider-track">
                    <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                  </div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Amber threshold for Completion Rate"
                       aria-valuemin="0" aria-valuemax="100"
                       aria-valuenow="<?php echo esc_attr( $pv('completion_rate_amber') ); ?>"></div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Green threshold for Completion Rate"
                       aria-valuemin="0" aria-valuemax="100"
                       aria-valuenow="<?php echo esc_attr( $pv('completion_rate_green') ); ?>"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="completion_rate_amber"
                       value="<?php echo esc_attr( $pv('completion_rate_amber') ); ?>"
                       min="0" max="100"
                       aria-label="Amber threshold value for Completion Rate"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="completion_rate_green"
                       value="<?php echo esc_attr( $pv('completion_rate_green') ); ?>"
                       min="0" max="100"
                       aria-label="Green threshold value for Completion Rate"></div>
                </div>
                <div class="pbsg-slider-scale"><span>0%</span><span>100%</span></div>
                <div class="pbsg-slider-legend">
                  <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; On track<br>
                  <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Worth reviewing<br>
                  <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Needs attention
                </div>
              </div>

              <!-- 2. Avg Score -->
              <div class="pbsg-bench-group">
                <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Avg Score Badges</label>
                <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                  Badge colours for average quiz score.
                </div>
                <div class="pbsg-slider-wrap" data-min="0" data-max="100" data-inverse="0"
                     data-key-low="score_amber" data-key-high="score_green"
                     data-default-low="<?php echo esc_attr( $site_benchmarks['score_amber'] ); ?>"
                     data-default-high="<?php echo esc_attr( $site_benchmarks['score_green'] ); ?>">
                  <div class="pbsg-slider-track">
                    <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                  </div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Amber threshold for Avg Score"
                       aria-valuemin="0" aria-valuemax="100"
                       aria-valuenow="<?php echo esc_attr( $pv('score_amber') ); ?>"></div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Green threshold for Avg Score"
                       aria-valuemin="0" aria-valuemax="100"
                       aria-valuenow="<?php echo esc_attr( $pv('score_green') ); ?>"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="score_amber"
                       value="<?php echo esc_attr( $pv('score_amber') ); ?>"
                       min="0" max="100"
                       aria-label="Amber threshold value for Avg Score"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="score_green"
                       value="<?php echo esc_attr( $pv('score_green') ); ?>"
                       min="0" max="100"
                       aria-label="Green threshold value for Avg Score"></div>
                </div>
                <div class="pbsg-slider-scale"><span>0%</span><span>100%</span></div>
                <div class="pbsg-slider-legend">
                  <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Students understand well<br>
                  <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Some questions need clarity<br>
                  <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Students are struggling
                </div>
              </div>

              <!-- 3. Correct Rate -->
              <div class="pbsg-bench-group">
                <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Correct Rate Badges</label>
                <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                  Badge colours for per-question correct rate.
                </div>
                <div class="pbsg-slider-wrap" data-min="0" data-max="100" data-inverse="0"
                     data-key-low="correct_rate_amber" data-key-high="correct_rate_green"
                     data-default-low="<?php echo esc_attr( $site_benchmarks['correct_rate_amber'] ); ?>"
                     data-default-high="<?php echo esc_attr( $site_benchmarks['correct_rate_green'] ); ?>">
                  <div class="pbsg-slider-track">
                    <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                  </div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Amber threshold for Correct Rate"
                       aria-valuemin="0" aria-valuemax="100"
                       aria-valuenow="<?php echo esc_attr( $pv('correct_rate_amber') ); ?>"></div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Green threshold for Correct Rate"
                       aria-valuemin="0" aria-valuemax="100"
                       aria-valuenow="<?php echo esc_attr( $pv('correct_rate_green') ); ?>"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="correct_rate_amber"
                       value="<?php echo esc_attr( $pv('correct_rate_amber') ); ?>"
                       min="0" max="100"
                       aria-label="Amber threshold value for Correct Rate"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="correct_rate_green"
                       value="<?php echo esc_attr( $pv('correct_rate_green') ); ?>"
                       min="0" max="100"
                       aria-label="Green threshold value for Correct Rate"></div>
                </div>
                <div class="pbsg-slider-scale"><span>0%</span><span>100%</span></div>
                <div class="pbsg-slider-legend">
                  <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Question well understood<br>
                  <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Could be clearer<br>
                  <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Needs rework
                </div>
              </div>

              <!-- 4. Give-up Count (inverse) -->
              <div class="pbsg-bench-group">
                <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Give-up Count Badges</label>
                <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                  Lower is better — high give-ups get flagged.
                </div>
                <div class="pbsg-slider-wrap" data-min="0" data-max="15" data-inverse="1"
                     data-key-low="giveup_low" data-key-high="giveup_high"
                     data-default-low="<?php echo esc_attr( $site_benchmarks['giveup_low'] ); ?>"
                     data-default-high="<?php echo esc_attr( $site_benchmarks['giveup_high'] ); ?>">
                  <div class="pbsg-slider-track">
                    <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                  </div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Green threshold for Give-up Count"
                       aria-valuemin="0" aria-valuemax="15"
                       aria-valuenow="<?php echo esc_attr( $pv('giveup_low') ); ?>"></div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Red threshold for Give-up Count"
                       aria-valuemin="0" aria-valuemax="15"
                       aria-valuenow="<?php echo esc_attr( $pv('giveup_high') ); ?>"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="giveup_low"
                       value="<?php echo esc_attr( $pv('giveup_low') ); ?>"
                       min="0" max="15"
                       aria-label="Green threshold value for Give-up Count"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="giveup_high"
                       value="<?php echo esc_attr( $pv('giveup_high') ); ?>"
                       min="0" max="15"
                       aria-label="Red threshold value for Give-up Count"></div>
                </div>
                <div class="pbsg-slider-scale"><span>0</span><span>15+</span></div>
                <div class="pbsg-slider-legend">
                  <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Few give-ups<br>
                  <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Review difficulty<br>
                  <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Question needs rework
                </div>
              </div>

              <!-- 5. Retries (inverse) -->
              <div class="pbsg-bench-group">
                <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Retries Badges</label>
                <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                  Lower is better — high retries get flagged.
                </div>
                <div class="pbsg-slider-wrap" data-min="0" data-max="13" data-inverse="1"
                     data-key-low="retries_low" data-key-high="retries_high"
                     data-default-low="<?php echo esc_attr( $site_benchmarks['retries_low'] ); ?>"
                     data-default-high="<?php echo esc_attr( $site_benchmarks['retries_high'] ); ?>">
                  <div class="pbsg-slider-track">
                    <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                    <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                  </div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Green threshold for Retries"
                       aria-valuemin="0" aria-valuemax="13"
                       aria-valuenow="<?php echo esc_attr( $pv('retries_low') ); ?>"></div>
                  <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                       aria-label="Red threshold for Retries"
                       aria-valuemin="0" aria-valuemax="13"
                       aria-valuenow="<?php echo esc_attr( $pv('retries_high') ); ?>"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="retries_low"
                       value="<?php echo esc_attr( $pv('retries_low') ); ?>"
                       min="0" max="13"
                       aria-label="Green threshold value for Retries"></div>
                  <div class="pbsg-slider-label"><input type="number" class="pbsg-bench-override"
                       data-key="retries_high"
                       value="<?php echo esc_attr( $pv('retries_high') ); ?>"
                       min="0" max="13"
                       aria-label="Red threshold value for Retries"></div>
                </div>
                <div class="pbsg-slider-scale"><span>0</span><span>13+</span></div>
                <div class="pbsg-slider-legend">
                  <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Students get it quickly<br>
                  <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; May be tricky<br>
                  <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Excessive retries
                </div>
              </div>

            </div>

            <p class="description" style="margin-top:12px; font-size:11px; color:#646970;">
              Sliders show the resolved value (per-tutorial override or site default). Adjust to override for this tutorial only.
            </p>
          </div>

          <!-- Hidden field carries the per-tutorial benchmark JSON -->
          <input type="hidden" id="pbsg_benchmarks_json" name="pbsg_benchmarks"
                 value="<?php echo esc_attr($per_bench_raw ?: ''); ?>" />

        </div>
      </div>

      <!-- ══════════ Close Tutorial Behavior Section ══════════ -->
      <div id="pbsg-close-url-section" class="pbsg-intro-section">

        <button type="button" id="pbsg-close-url-toggle" class="pbsg-section-header" aria-expanded="false" aria-controls="pbsg-close-url-body">          <div class="pbsg-section-header-left">
            <span class="pbsg-section-icon"><?php echo pbsg_icon('arrow-up-right'); ?></span>
            <span class="pbsg-section-title">Close Tutorial Behaviour</span>
            <span class="pbsg-badge pbsg-badge--info">Where students go when they exit</span>
          </div>
          <span id="pbsg-close-url-chevron" class="pbsg-chevron"><?php echo pbsg_icon('chevron-right'); ?></span>
        </button>

        <div id="pbsg-close-url-body" class="pbsg-intro-body" style="display:none;">

          <div class="pbsg-field">
            <label for="pbsg_close_tutorial_url" class="pbsg-field-label">Close Tutorial URL</label>

            <input
              type="text"
              id="pbsg_close_tutorial_url"
              name="pbsg_close_tutorial_url"
              value="<?php echo esc_attr($close_tutorial_url); ?>"
              class="pbsg-input"
              placeholder="https://library.upei.ca/"
            />

            <ul class="pbsg-description pbsg-close-url-states">
              <li><?php esc_html_e('Leave empty — closes the current tab when students exit', 'pb-split-guide'); ?></li>
              <li><?php esc_html_e('Add a URL — redirects students to the provided link instead', 'pb-split-guide'); ?></li>
            </ul>
          </div>

        </div>
      </div>

      <!-- ══════════ Steps Section ══════════ -->
      <div id="pbsg-steps-container" class="pbsg-steps-container">
        <!-- Steps are rendered by JS -->
      </div>

      <div class="pbsg-add-step-area" style="margin-top:12px;">
        <button type="button" id="pbsg-add-step" class="pbsg-add-step-btn">
          <span class="pbsg-add-step-plus">+</span>
          Add Quiz Step
        </button>
      </div>

      <div class="pbsg-template-save-row" style="margin-top:24px; text-align:right;">
        <button type="button" class="button" id="pbsg-save-as-template">Save All as Template</button>
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

  /**
   * When the block/classic editor is removed, the main column looks empty; point users to the metabox.
   */
  public function render_split_guide_editor_notice($post) {
    if (!$post instanceof \WP_Post || $post->post_type !== 'page') {
      return;
    }
    global $pagenow;
    if (!in_array($pagenow, ['post.php', 'post-new.php'], true)) {
      return;
    }
    $tpl = get_post_meta($post->ID, '_wp_page_template', true);
    $show = ($pagenow === 'post-new.php') || self::is_split_guide_template($tpl);
    if (!$show) {
      return;
    }
    echo '<div class="notice notice-info inline" style="margin:12px 0;"><p>';
    echo esc_html__(
      'This tutorial does not use the standard page editor. Add the introduction, steps, and quizzes in the Split Guide Settings section below (scroll down on this screen).',
      'pb-split-guide'
    );
    echo '</p></div>';
  }

  /**
   * Render the Tutorial Owner metabox in the editor sidebar.
   * Shows current owner and a "Transfer" button if the user can transfer.
   */
  public function render_owner_metabox($post) {
    $owner        = get_userdata((int) $post->post_author);
    $owner_name   = $owner ? $owner->display_name : __('Unknown', 'pb-split-guide');
    $is_admin     = PBSG_Roles::is_admin();
    $is_owner     = (int) $post->post_author === get_current_user_id();
    $can_transfer = $is_admin || ($is_owner && self::is_transfer_enabled());

    // ── Shared identity data (used by every state) ───────────────────────
    // Human-readable role label for the owner (first role).
    $owner_role_label = '';
    if ($owner && !empty($owner->roles)) {
      global $wp_roles;
      $role_slug = reset($owner->roles);
      if (isset($wp_roles->role_names[$role_slug])) {
        $owner_role_label = translate_user_role($wp_roles->role_names[$role_slug]);
      } else {
        $owner_role_label = ucfirst(str_replace('_', ' ', $role_slug));
      }
    }

    $avatar_html = $owner
      ? get_avatar(
          $owner->ID,
          40,
          '',
          $owner_name,
          array('class' => 'pbsg-owner-card__avatar')
        )
      : '';

    // Non-owner, non-admin state shows a "Contact the owner" mailto link
    // instead of the Transfer Ownership button.
    $owner_email       = $owner && !empty($owner->user_email) ? $owner->user_email : '';
    $mailto_subject    = sprintf(
      /* translators: %s: tutorial title */
      __('Question about tutorial: %s', 'pb-split-guide'),
      get_the_title($post->ID)
    );
    $show_you_badge    = $is_owner && !$is_admin;
    $show_contact_link = (!$is_owner && !$is_admin && $owner_email);
    ?>
    <div class="pbsg-owner-metabox pbsg-owner-metabox--card">
      <div class="pbsg-owner-card">
        <?php if ($avatar_html) : ?>
          <div class="pbsg-owner-card__avatar-wrap"><?php echo $avatar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() returns escaped HTML ?></div>
        <?php else : ?>
          <div class="pbsg-owner-card__avatar-wrap pbsg-owner-card__avatar-wrap--placeholder" aria-hidden="true">
            <?php echo pbsg_icon('document-page'); ?>
          </div>
        <?php endif; ?>
        <div class="pbsg-owner-card__info">
          <div class="pbsg-owner-card__name-row">
            <span class="pbsg-owner-card__name"><?php echo esc_html($owner_name); ?></span>
            <?php if ($show_you_badge) : ?>
              <span class="pbsg-owner-badge pbsg-owner-badge--self"><?php esc_html_e('You', 'pb-split-guide'); ?></span>
            <?php endif; ?>
          </div>
          <?php if ($owner_role_label) : ?>
            <span class="pbsg-owner-card__role"><?php echo esc_html($owner_role_label); ?></span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($can_transfer) : ?>
        <button type="button"
                class="button pbsg-transfer-single pbsg-owner-metabox__action"
                data-post-id="<?php echo esc_attr($post->ID); ?>"
                data-post-title="<?php echo esc_attr(get_the_title($post->ID)); ?>">
          <?php esc_html_e('Transfer Ownership', 'pb-split-guide'); ?>
        </button>
      <?php elseif ($show_contact_link) : ?>
        <a class="pbsg-owner-contact"
           href="mailto:<?php echo esc_attr($owner_email); ?>?subject=<?php echo esc_attr(rawurlencode($mailto_subject)); ?>">
          <?php echo pbsg_icon('link'); ?>
          <span><?php esc_html_e('Contact the owner', 'pb-split-guide'); ?></span>
        </a>
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
    register_setting('pbsg_guide_settings', self::OPTION_CROSS_EDIT, [
      'type'              => 'boolean',
      'sanitize_callback' => 'rest_sanitize_boolean',
      'default'           => true,
    ]);
    register_setting('pbsg_guide_settings', self::OPTION_TRANSFER, [
      'type'              => 'boolean',
      'sanitize_callback' => 'rest_sanitize_boolean',
      'default'           => true,
    ]);
    register_setting('pbsg_guide_settings', self::OPTION_STYLE_DEFAULTS, [
      'type'              => 'string',
      'sanitize_callback' => [$this, 'sanitize_style_defaults'],
      'default'           => wp_json_encode(self::STYLE_DEFAULTS),
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
   * Sanitize style defaults — whitelist fonts/sizes, validate hex colours.
   */
  public function sanitize_style_defaults($value) {
    $decoded = is_string($value) ? json_decode($value, true) : $value;
    if (!is_array($decoded)) $decoded = [];

    $allowed_fonts = [
        'Roboto, sans-serif',
        'Lusitana, serif',
        'Georgia, serif',
        'Arial, sans-serif',
        '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
    ];
    $allowed_sizes = ['14px', '16px', '18px', '20px'];

    $clean = [];
    $clean['font_family'] = in_array($decoded['font_family'] ?? '', $allowed_fonts, true)
        ? $decoded['font_family'] : self::STYLE_DEFAULTS['font_family'];
    $clean['heading_font'] = in_array($decoded['heading_font'] ?? '', $allowed_fonts, true)
        ? $decoded['heading_font'] : self::STYLE_DEFAULTS['heading_font'];
    $clean['font_size'] = in_array($decoded['font_size'] ?? '', $allowed_sizes, true)
        ? $decoded['font_size'] : self::STYLE_DEFAULTS['font_size'];
    $clean['text_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $decoded['text_color'] ?? '')
        ? $decoded['text_color'] : self::STYLE_DEFAULTS['text_color'];
    $clean['accent_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $decoded['accent_color'] ?? '')
        ? $decoded['accent_color'] : self::STYLE_DEFAULTS['accent_color'];
    $clean['button_color'] = preg_match('/^#[0-9A-Fa-f]{6}$/', $decoded['button_color'] ?? '')
        ? $decoded['button_color'] : self::STYLE_DEFAULTS['button_color'];

    return wp_json_encode($clean);
  }

  /**
   * Resolve the effective style defaults.
   * Saved option → hardcoded fallback.
   */
  public static function resolve_style_defaults(): array {
    $raw = get_option(self::OPTION_STYLE_DEFAULTS, '');
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return self::STYLE_DEFAULTS;
    }
    return array_merge(self::STYLE_DEFAULTS, $decoded);
  }

  /**
   * Clean up plugin options on uninstall.
   * Registered via register_uninstall_hook().
   */
  public static function uninstall(): void {
    delete_option(self::OPTION_STYLE_DEFAULTS);
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

  /**
   * Check if cross-editing is enabled site-wide.
   */
  public static function is_cross_edit_enabled(): bool {
    return (bool) get_option(self::OPTION_CROSS_EDIT, true);
  }

  /**
   * Check if ownership transfer is enabled site-wide.
   */
  public static function is_transfer_enabled(): bool {
    return (bool) get_option(self::OPTION_TRANSFER, true);
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
    $style = self::resolve_style_defaults();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Guide Settings', 'pb-split-guide'); ?></h1>
      <p class="description" style="margin-bottom:20px;">
        Site-wide defaults for tutorial layout and analytics benchmarks. Librarians can override benchmarks per tutorial.
      </p>

      <form method="post" action="<?php echo esc_url( admin_url('options.php') ); ?>">
        <?php settings_fields('pbsg_guide_settings'); ?>

        <!-- ═══ Section 1: Layout ═══ -->
        <div class="pbsg-admin-settings-card" style="
          background: #fff; border: 1px solid #E0E0E0; border-radius: 8px;
          padding: 24px; max-width: 720px; margin-bottom: 24px;
        ">
          <h2 style="margin-top:0; font-size:18px; display:flex; align-items:center; gap:8px;"><?php echo pbsg_icon('arrow-horizontal'); ?> Default Panel Layout</h2>
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
          <h2 style="margin-top:0; font-size:18px; display:flex; align-items:center; gap:8px;"><?php echo pbsg_icon('chart-bar'); ?> Default Performance Benchmarks</h2>
          <p class="description" style="margin-bottom: 16px;">
            These thresholds determine badge colours on the analytics dashboard.
            Tutorials with any metric in the <strong style="color:#D93025;">red</strong> zone are automatically flagged as &ldquo;Needs Attention&rdquo; on the Overview tab.
            Librarians can override these per tutorial in the tutorial editor.
          </p>

          <!-- Hidden field carries the JSON blob -->
          <input type="hidden" id="pbsg_benchmark_defaults_json"
                 name="<?php echo esc_attr(self::OPTION_BENCHMARK_DEFAULTS); ?>"
                 value="<?php echo esc_attr(wp_json_encode($bench)); ?>" />

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

            <!-- 1. Completion Rate -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Completion Rate Badges</label>
              <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                % of students who finish all steps of a tutorial. Shown on Overview and Tutorial Detail tabs.
              </div>
              <div class="pbsg-slider-wrap" data-min="0" data-max="100" data-inverse="0"
                   data-key-low="completion_rate_amber" data-key-high="completion_rate_green">
                <div class="pbsg-slider-track">
                  <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                </div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Amber threshold for Completion Rate"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr($bench['completion_rate_amber']); ?>"></div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Green threshold for Completion Rate"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr($bench['completion_rate_green']); ?>"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['completion_rate_amber']); ?>"
                     min="0" max="100"
                     aria-label="Amber threshold value for Completion Rate"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['completion_rate_green']); ?>"
                     min="0" max="100"
                     aria-label="Green threshold value for Completion Rate"></div>
              </div>
              <div class="pbsg-slider-scale"><span>0%</span><span>100%</span></div>
              <div class="pbsg-slider-legend">
                <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; On track, no action needed<br>
                <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Worth reviewing; some students may be dropping off<br>
                <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Needs attention; tutorial may be too long or confusing
              </div>
            </div>

            <!-- 2. Avg Score -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Avg Score Badges</label>
              <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                Average % of quiz questions answered correctly across all students. Shown on Overview tab (all-time).
              </div>
              <div class="pbsg-slider-wrap" data-min="0" data-max="100" data-inverse="0"
                   data-key-low="score_amber" data-key-high="score_green">
                <div class="pbsg-slider-track">
                  <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                </div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Amber threshold for Avg Score"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr($bench['score_amber']); ?>"></div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Green threshold for Avg Score"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr($bench['score_green']); ?>"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['score_amber']); ?>"
                     min="0" max="100"
                     aria-label="Amber threshold value for Avg Score"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['score_green']); ?>"
                     min="0" max="100"
                     aria-label="Green threshold value for Avg Score"></div>
              </div>
              <div class="pbsg-slider-scale"><span>0%</span><span>100%</span></div>
              <div class="pbsg-slider-legend">
                <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Students understand the material well<br>
                <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Some questions may need clearer wording<br>
                <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Students are struggling; review quiz content
              </div>
            </div>

            <!-- 3. Correct Rate -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Correct Rate Badges</label>
              <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                % of attempts answered correctly for each individual question. Shown in Quiz Questions table on Tutorial Detail.
              </div>
              <div class="pbsg-slider-wrap" data-min="0" data-max="100" data-inverse="0"
                   data-key-low="correct_rate_amber" data-key-high="correct_rate_green">
                <div class="pbsg-slider-track">
                  <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                </div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Amber threshold for Correct Rate"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr($bench['correct_rate_amber']); ?>"></div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Green threshold for Correct Rate"
                     aria-valuemin="0" aria-valuemax="100"
                     aria-valuenow="<?php echo esc_attr($bench['correct_rate_green']); ?>"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['correct_rate_amber']); ?>"
                     min="0" max="100"
                     aria-label="Amber threshold value for Correct Rate"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['correct_rate_green']); ?>"
                     min="0" max="100"
                     aria-label="Green threshold value for Correct Rate"></div>
              </div>
              <div class="pbsg-slider-scale"><span>0%</span><span>100%</span></div>
              <div class="pbsg-slider-legend">
                <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Question is well understood by students<br>
                <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Question could be clearer or hints may help<br>
                <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Question is too hard or poorly worded; rework needed
              </div>
            </div>

            <!-- 4. Give-up Count (inverse) -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Give-up Count Badges</label>
              <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                Number of times students gave up on a question without answering. Shown in Question Drill-Down. Lower is better.
              </div>
              <div class="pbsg-slider-wrap" data-min="0" data-max="15" data-inverse="1"
                   data-key-low="giveup_low" data-key-high="giveup_high">
                <div class="pbsg-slider-track">
                  <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                </div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Green threshold for Give-up Count"
                     aria-valuemin="0" aria-valuemax="15"
                     aria-valuenow="<?php echo esc_attr($bench['giveup_low']); ?>"></div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Red threshold for Give-up Count"
                     aria-valuemin="0" aria-valuemax="15"
                     aria-valuenow="<?php echo esc_attr($bench['giveup_high']); ?>"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['giveup_low']); ?>"
                     min="0" max="15"
                     aria-label="Green threshold value for Give-up Count"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['giveup_high']); ?>"
                     min="0" max="15"
                     aria-label="Red threshold value for Give-up Count"></div>
              </div>
              <div class="pbsg-slider-scale"><span>0</span><span>15+</span></div>
              <div class="pbsg-slider-legend">
                <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Few give-ups; students are engaging with the question<br>
                <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Some students giving up; review difficulty level<br>
                <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Many students giving up; question likely needs rework
              </div>
            </div>

            <!-- 5. Retries (inverse) -->
            <div class="pbsg-bench-group">
              <label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Retries Badges</label>
              <div style="font-size:11px;color:#646970;margin-bottom:14px;line-height:1.4;">
                Highest number of retry attempts on a single question in one session. Shown in Question Drill-Down. Lower is better.
              </div>
              <div class="pbsg-slider-wrap" data-min="0" data-max="13" data-inverse="1"
                   data-key-low="retries_low" data-key-high="retries_high">
                <div class="pbsg-slider-track">
                  <div class="pbsg-slider-seg pbsg-slider-seg--green"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--amber"></div>
                  <div class="pbsg-slider-seg pbsg-slider-seg--red"></div>
                </div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Green threshold for Retries"
                     aria-valuemin="0" aria-valuemax="13"
                     aria-valuenow="<?php echo esc_attr($bench['retries_low']); ?>"></div>
                <div class="pbsg-slider-thumb" tabindex="0" role="slider"
                     aria-label="Red threshold for Retries"
                     aria-valuemin="0" aria-valuemax="13"
                     aria-valuenow="<?php echo esc_attr($bench['retries_high']); ?>"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['retries_low']); ?>"
                     min="0" max="13"
                     aria-label="Green threshold value for Retries"></div>
                <div class="pbsg-slider-label"><input type="number"
                     value="<?php echo esc_attr($bench['retries_high']); ?>"
                     min="0" max="13"
                     aria-label="Red threshold value for Retries"></div>
              </div>
              <div class="pbsg-slider-scale"><span>0</span><span>13+</span></div>
              <div class="pbsg-slider-legend">
                <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Students get it within a few tries<br>
                <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Students need several attempts; question may be tricky<br>
                <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Excessive retries; question may be unfair or confusing
              </div>
            </div>

            <!-- 6. How Benchmarks Work (summary/legend) -->
            <div class="pbsg-bench-group" style="background:#f6f7f7;border:1px solid #e0e0e0;border-radius:6px;padding:14px;">
              <label style="font-weight:600;font-size:13px;display:block;margin-bottom:8px;">How Benchmarks Work</label>
              <div style="font-size:11px;color:#646970;line-height:1.6;margin-bottom:12px;">
                Each metric on the analytics dashboard displays a coloured badge based on the thresholds you set with the sliders above.
              </div>
              <div style="font-size:11px;color:#1d2327;line-height:1.8;margin-bottom:12px;">
                <span class="pbsg-dot pbsg-dot--green"></span><strong>Green</strong> &mdash; Performing well. No action needed.<br>
                <span class="pbsg-dot pbsg-dot--amber"></span><strong>Amber</strong> &mdash; Worth a look. Not urgent, but may improve with adjustments.<br>
                <span class="pbsg-dot pbsg-dot--red"></span><strong>Red</strong> &mdash; Needs attention. Tutorial is flagged on the Overview tab for review.
              </div>
              <div style="border-top:1px solid #dcdcde;padding-top:10px;font-size:11px;color:#646970;line-height:1.5;">
                <strong>Needs Attention flag:</strong> Any tutorial with a metric in the red zone is automatically flagged on the Overview tab. No separate threshold to configure.
              </div>
              <div style="border-top:1px solid #dcdcde;padding-top:10px;margin-top:10px;font-size:11px;color:#646970;line-height:1.5;">
                <strong>Per-tutorial override:</strong> These are site-wide defaults. You can set different thresholds for individual tutorials in the tutorial editor.
              </div>
            </div>

          </div>
        </div>

        <!-- ═══ Section 3: Permissions ═══ -->
        <div class="pbsg-admin-settings-card" style="
          background: #fff; border: 1px solid #E0E0E0; border-radius: 8px;
          padding: 24px; max-width: 720px; margin-bottom: 24px;
        ">
          <h2 style="margin-top:0; font-size:18px; display:flex; align-items:center; gap:8px;"><?php echo pbsg_icon('lock-closed'); ?> Permissions</h2>
          <p class="description" style="margin-bottom: 16px;">
            Control collaboration between librarians. Administrators always have full access regardless of these settings.
          </p>

          <?php
          $cross_edit = (bool) get_option(self::OPTION_CROSS_EDIT, true);
          $transfer   = (bool) get_option(self::OPTION_TRANSFER, true);
          ?>

          <div style="margin-bottom: 16px;">
            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
              <input type="hidden" name="<?php echo esc_attr(self::OPTION_CROSS_EDIT); ?>" value="0" />
              <input type="checkbox"
                     id="pbsg_cross_edit_toggle"
                     name="<?php echo esc_attr(self::OPTION_CROSS_EDIT); ?>"
                     value="1"
                     <?php checked($cross_edit); ?>
                     data-original="<?php echo $cross_edit ? '1' : '0'; ?>"
                     style="margin-top:3px;" />
              <span>
                <strong>Allow cross-editing</strong><br>
                <span class="description">Librarians can edit tutorials created by other librarians. They cannot delete or change the publish status of tutorials they don't own.</span>
              </span>
            </label>
          </div>

          <div>
            <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer;">
              <input type="hidden" name="<?php echo esc_attr(self::OPTION_TRANSFER); ?>" value="0" />
              <input type="checkbox"
                     id="pbsg_transfer_toggle"
                     name="<?php echo esc_attr(self::OPTION_TRANSFER); ?>"
                     value="1"
                     <?php checked($transfer); ?>
                     data-original="<?php echo $transfer ? '1' : '0'; ?>"
                     style="margin-top:3px;" />
              <span>
                <strong>Allow ownership transfer</strong><br>
                <span class="description">Librarians can transfer ownership of their own tutorials to other librarians or administrators.</span>
              </span>
            </label>
          </div>
        </div>

        <!-- Section 4: Style Defaults -->
        <div class="pbsg-admin-settings-card" style="
          background: #fff; border: 1px solid #E0E0E0; border-radius: 8px;
          padding: 24px; max-width: 720px; margin-bottom: 24px;
        ">
          <h2 style="margin-top:0; font-size:18px; display:flex; align-items:center; gap:8px;"><?php echo pbsg_icon('pencil'); ?> Style Defaults</h2>
          <p class="description" style="margin-bottom: 16px;">
            Site-wide font and colour defaults for all tutorials. Per-tutorial overrides will be available in the tutorial editor.
          </p>

          <!-- Hidden input carries the JSON blob -->
          <input type="hidden" id="pbsg_style_defaults_json"
                 name="<?php echo esc_attr(self::OPTION_STYLE_DEFAULTS); ?>"
                 value="<?php echo esc_attr(wp_json_encode($style)); ?>" />

          <div style="display:flex; flex-wrap:wrap; gap:16px 20px; margin-bottom:20px;">
            <div style="flex:0 0 calc(33.33% - 14px);">
              <label for="pbsg_style_font_family" style="display:block; font-weight:600; font-size:13px; color:#666; margin-bottom:4px;">Body font</label>
              <select id="pbsg_style_font_family" class="pbsg-style-input" data-key="font_family" style="width:100%;">
                <option value="Roboto, sans-serif" <?php selected($style['font_family'], 'Roboto, sans-serif'); ?>>Roboto</option>
                <option value="Lusitana, serif" <?php selected($style['font_family'], 'Lusitana, serif'); ?>>Lusitana</option>
                <option value="Georgia, serif" <?php selected($style['font_family'], 'Georgia, serif'); ?>>Georgia</option>
                <option value="Arial, sans-serif" <?php selected($style['font_family'], 'Arial, sans-serif'); ?>>Arial</option>
                <option value="-apple-system, BlinkMacSystemFont, &quot;Segoe UI&quot;, Roboto, sans-serif" <?php selected($style['font_family'], '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'); ?>>System default</option>
              </select>
            </div>
            <div style="flex:0 0 calc(33.33% - 14px);">
              <label for="pbsg_style_font_size" style="display:block; font-weight:600; font-size:13px; color:#666; margin-bottom:4px;">Font size</label>
              <select id="pbsg_style_font_size" class="pbsg-style-input" data-key="font_size" style="width:100%;">
                <option value="14px" <?php selected($style['font_size'], '14px'); ?>>14px</option>
                <option value="16px" <?php selected($style['font_size'], '16px'); ?>>16px</option>
                <option value="18px" <?php selected($style['font_size'], '18px'); ?>>18px</option>
                <option value="20px" <?php selected($style['font_size'], '20px'); ?>>20px</option>
              </select>
            </div>
            <div style="flex:0 0 calc(33.33% - 14px);">
              <label for="pbsg_style_text_color" style="display:block; font-weight:600; font-size:13px; color:#666; margin-bottom:4px;">Text colour</label>
              <input type="text" id="pbsg_style_text_color" class="pbsg-style-input pbsg-color-field"
                     data-key="text_color"
                     value="<?php echo esc_attr($style['text_color']); ?>"
                     data-default-color="<?php echo esc_attr(self::STYLE_DEFAULTS['text_color']); ?>" />
            </div>
            <div style="flex:0 0 calc(33.33% - 14px);">
              <label for="pbsg_style_heading_font" style="display:block; font-weight:600; font-size:13px; color:#666; margin-bottom:4px;">Heading font</label>
              <select id="pbsg_style_heading_font" class="pbsg-style-input" data-key="heading_font" style="width:100%;">
                <option value="Lusitana, serif" <?php selected($style['heading_font'], 'Lusitana, serif'); ?>>Lusitana</option>
                <option value="Roboto, sans-serif" <?php selected($style['heading_font'], 'Roboto, sans-serif'); ?>>Roboto</option>
                <option value="Georgia, serif" <?php selected($style['heading_font'], 'Georgia, serif'); ?>>Georgia</option>
                <option value="Arial, sans-serif" <?php selected($style['heading_font'], 'Arial, sans-serif'); ?>>Arial</option>
                <option value="-apple-system, BlinkMacSystemFont, &quot;Segoe UI&quot;, Roboto, sans-serif" <?php selected($style['heading_font'], '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'); ?>>System default</option>
              </select>
            </div>
            <div style="flex:0 0 calc(33.33% - 14px);">
              <label for="pbsg_style_accent_color" style="display:block; font-weight:600; font-size:13px; color:#666; margin-bottom:4px;">Accent / link colour</label>
              <input type="text" id="pbsg_style_accent_color" class="pbsg-style-input pbsg-color-field"
                     data-key="accent_color"
                     value="<?php echo esc_attr($style['accent_color']); ?>"
                     data-default-color="<?php echo esc_attr(self::STYLE_DEFAULTS['accent_color']); ?>" />
            </div>
            <div style="flex:0 0 calc(33.33% - 14px);">
              <label for="pbsg_style_button_color" style="display:block; font-weight:600; font-size:13px; color:#666; margin-bottom:4px;">Button colour</label>
              <input type="text" id="pbsg_style_button_color" class="pbsg-style-input pbsg-color-field"
                     data-key="button_color"
                     value="<?php echo esc_attr($style['button_color']); ?>"
                     data-default-color="<?php echo esc_attr(self::STYLE_DEFAULTS['button_color']); ?>" />
            </div>
          </div>

          <!-- Live preview -->
          <div id="pbsg_style_preview" style="
            border: 1px solid #E0E0E0; border-radius: 6px; padding: 20px;
            background: #F8F8F8;
          ">
            <h3 id="pbsg_preview_heading" style="margin-top:0; margin-bottom:8px;">Preview Heading</h3>
            <p id="pbsg_preview_body" style="margin-bottom:8px;">
              This is sample body text showing how your tutorials will look with the selected fonts and colours.
            </p>
            <a href="#" id="pbsg_preview_link" onclick="return false;" style="margin-right:12px;">Sample Link</a>
            <button type="button" id="pbsg_preview_button" style="
              padding: 6px 16px; border: none; border-radius: 4px;
              color: #fff; cursor: pointer; font-size: 14px;
            ">Sample Button</button>
          </div>

          <div style="margin-top:12px;">
            <button type="button" class="button" id="pbsg_style_reset">Reset Style Defaults</button>
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

        /* ── Style Defaults — field sync + live preview ── */
        var styleHidden = document.getElementById('pbsg_style_defaults_json');
        var styleInputs = document.querySelectorAll('.pbsg-style-input');
        var styleDefaults = <?php echo wp_json_encode(self::STYLE_DEFAULTS); ?>;

        function getStyleValue(key) {
          var el = document.querySelector('.pbsg-style-input[data-key="' + key + '"]');
          return el ? el.value : styleDefaults[key];
        }

        function syncStyleJSON() {
          var obj = {};
          styleInputs.forEach(function(el) {
            obj[el.getAttribute('data-key')] = el.value;
          });
          styleHidden.value = JSON.stringify(obj);
        }

        function updateStylePreview() {
          var heading = document.getElementById('pbsg_preview_heading');
          var body    = document.getElementById('pbsg_preview_body');
          var link    = document.getElementById('pbsg_preview_link');
          var btn     = document.getElementById('pbsg_preview_button');

          heading.style.fontFamily = getStyleValue('heading_font');
          heading.style.color      = getStyleValue('text_color');
          body.style.fontFamily    = getStyleValue('font_family');
          body.style.fontSize      = getStyleValue('font_size');
          body.style.color         = getStyleValue('text_color');
          link.style.fontFamily    = getStyleValue('font_family');
          link.style.color         = getStyleValue('accent_color');
          btn.style.backgroundColor = getStyleValue('button_color');
          btn.style.fontFamily     = getStyleValue('font_family');
        }

        styleInputs.forEach(function(el) {
          el.addEventListener('change', function() { syncStyleJSON(); updateStylePreview(); });
          el.addEventListener('input', function()  { syncStyleJSON(); updateStylePreview(); });
        });

        // Initialize color pickers (jQuery wp-color-picker).
        // Wrapped in jQuery.ready because wp-color-picker JS loads in the
        // footer, after this inline script runs.
        if (typeof jQuery !== 'undefined') {
          jQuery(function() {
            if (jQuery.fn.wpColorPicker) {
              jQuery('.pbsg-color-field').wpColorPicker({
                change: function(event, ui) {
                  jQuery(event.target).val(ui.color.toString());
                  syncStyleJSON();
                  updateStylePreview();
                },
                clear: function(event) {
                  jQuery(event.target).val(jQuery(event.target).data('default-color'));
                  syncStyleJSON();
                  updateStylePreview();
                }
              });
            }
          });
        }

        // Style-only reset button
        document.getElementById('pbsg_style_reset').addEventListener('click', function() {
          styleInputs.forEach(function(el) {
            var key = el.getAttribute('data-key');
            if (styleDefaults[key] !== undefined) {
              el.value = styleDefaults[key];
              // Also update the color picker widget if applicable
              if (typeof jQuery !== 'undefined' && jQuery(el).hasClass('pbsg-color-field') && jQuery.fn.wpColorPicker) {
                jQuery(el).wpColorPicker('color', styleDefaults[key]);
              }
            }
          });
          syncStyleJSON();
          updateStylePreview();
        });

        // Initialize preview on page load
        updateStylePreview();

        /* ── Reset button ── */
        var defaults = <?php echo wp_json_encode(self::BENCHMARK_FALLBACKS); ?>;
        document.getElementById('pbsg_admin_reset').addEventListener('click', function(){
          if (!window.PbsgModal) {
            // Fallback if modal primitive somehow didn't load — block rather than silently reset.
            alert('Cannot reset — modal unavailable. Reload the page and try again.');
            return;
          }
          window.PbsgModal.open({
            variant: 'destructive',
            icon:    (window.pbsgModalIcons && window.pbsgModalIcons.refresh) || '',
            heading: 'Reset all Guide Settings?',
            subtitle: 'This will revert every section on this page to its factory default.',
            bodyLabel: 'The following will be reset and saved immediately',
            bullets: [
              '<strong>Panel Layout</strong> — returns to <?php echo (int) self::RATIO_DEFAULT; ?>% left / <?php echo (int) (100 - self::RATIO_DEFAULT); ?>% right.',
              '<strong>Analytics Benchmarks</strong> — all thresholds restored to factory defaults.',
              '<strong>Permissions</strong> — cross-editing <strong>on</strong>, ownership transfer <strong>on</strong>.',
              '<strong>Style Defaults</strong> — fonts, sizes, and colours restored to factory defaults.'
            ],
            caveat: "<strong>This cannot be undone.</strong> Any customizations you've made across all panels will be overwritten.",
            confirmLabel: 'Reset and save',
            onConfirm: function () {
              // 1. Ratio slider
              slider.value = <?php echo self::RATIO_DEFAULT; ?>;
              slider.dispatchEvent(new Event('input'));

              // 2. Benchmarks
              benchInputs.forEach(function(el) {
                var key = el.getAttribute('data-key');
                if (defaults[key] !== undefined) el.value = defaults[key];
              });
              syncBenchJSON();

              // 3. Permission toggles — both ON is the client default
              var cross = document.getElementById('pbsg_cross_edit_toggle');
              var xfer  = document.getElementById('pbsg_transfer_toggle');
              if (cross) cross.checked = true;
              if (xfer)  xfer.checked  = true;

              // 4. Style Defaults — reset all to factory
              styleInputs.forEach(function(el) {
                var key = el.getAttribute('data-key');
                if (styleDefaults[key] !== undefined) {
                  el.value = styleDefaults[key];
                  if (typeof jQuery !== 'undefined' && jQuery(el).hasClass('pbsg-color-field') && jQuery.fn.wpColorPicker) {
                    jQuery(el).wpColorPicker('color', styleDefaults[key]);
                  }
                }
              });
              syncStyleJSON();
              updateStylePreview();

              // 5. Submit to persist.
              //    NOTE: the form has <input name="submit"> from submit_button(),
              //    which shadows form.submit (the method) on the element. Calling
              //    the prototype method directly bypasses the shadow. This also
              //    skips the submit event (native .submit() does not fire it), so
              //    the per-toggle confirmation chain is naturally bypassed.
              var form = document.querySelector('form[action$="options.php"]');
              if (form) {
                HTMLFormElement.prototype.submit.call(form);
              }
            }
          });
        });
      })();
      </script>
    </div>
    <?php
  }

  /**
   * Translate a branch sub-question's source fields into the quiz schema
   * that PBSG_H5P_Factory::create()/update() understands.
   *
   * Returns null for unsupported types so the caller can skip H5P creation.
   *
   * @param array $bq Branch question data (from branch.questions[]).
   * @return array|null Quiz schema or null if type unsupported.
   */
  private static function branch_question_to_quiz(array $bq): ?array {
    $type = $bq['type'] ?? '';
    if (!in_array($type, ['multichoice', 'singlechoice', 'blanks'], true)) {
      return null;
    }

    if ($type === 'multichoice') {
      return [
        'type'     => 'multichoice',
        'question' => $bq['question'] ?? '',
        'answers'  => array_values(array_map(function ($a) {
          return [
            'text'    => $a['text'] ?? '',
            'correct' => !empty($a['correct']),
          ];
        }, (array) ($bq['answers'] ?? []))),
      ];
    }

    if ($type === 'singlechoice') {
      return [
        'type'           => 'singlechoice',
        'question'       => $bq['question'] ?? '',
        'correct_answer' => $bq['correct_answer'] ?? '',
        'wrong_answers'  => array_values((array) ($bq['wrong_answers'] ?? [])),
      ];
    }

    if ($type === 'blanks') {
      return [
        'type'           => 'blanks',
        'sentence'       => $bq['sentence'] ?? '',
        'case_sensitive' => !empty($bq['case_sensitive']),
        'accept_typos'   => !empty($bq['accept_typos']),
      ];
    }

    return null;
  }

  /**
   * Look up the H5P library machine name for an existing H5P content row.
   * Used to detect when a branch question's type has changed and the linked
   * H5P content row needs to be replaced rather than updated in place.
   *
   * @param int $h5p_id H5P content ID.
   * @return string|null Library machine name (e.g. 'H5P.MultiChoice'), or null if not found.
   */
  private static function get_h5p_library_name(int $h5p_id): ?string {
    if ($h5p_id <= 0) {
      return null;
    }
    global $wpdb;
    $row = $wpdb->get_var($wpdb->prepare(
      "SELECT l.name
       FROM {$wpdb->prefix}h5p_contents c
       JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id = l.id
       WHERE c.id = %d",
      $h5p_id
    ));
    return $row ?: null;
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

      // Branch question H5P creation/update — mirrors the main quiz path above.
      // Each branch sub-question becomes a real H5P content row so the student-side
      // renderer can load it as an iframe (same UX as main quiz).
      if (!empty($step['branch']['questions']) && is_array($step['branch']['questions']) && PBSG_H5P_Factory::is_h5p_available()) {
        foreach ($step['branch']['questions'] as $qIdx => &$bq) {
          $quiz = self::branch_question_to_quiz($bq);
          if (!$quiz) {
            // Unsupported type — strip the transient flag and skip
            unset($bq['_editing_h5p']);
            continue;
          }

          // Type-change defense: if H5P content already exists but its library
          // no longer matches the current quiz type, orphan it and force a fresh create.
          if (!empty($bq['h5p_id']) && $bq['h5p_id'] > 0) {
            $current_library  = self::get_h5p_library_name((int) $bq['h5p_id']);
            $expected_library = PBSG_H5P_Factory::get_library_for_type($quiz['type']);
            if ($current_library && $expected_library && $current_library !== $expected_library) {
              $bq['h5p_id'] = 0;
            }
          }

          if (!empty($bq['_editing_h5p']) && !empty($bq['h5p_id']) && $bq['h5p_id'] > 0) {
            // Update existing branch H5P content
            $result = PBSG_H5P_Factory::update($bq['h5p_id'], $quiz);
            if (is_wp_error($result)) {
              $h5p_errors[] = sprintf('Step %d Branch Q%d (update): %s', $idx + 1, $qIdx + 1, $result->get_error_message());
            }
          } elseif (empty($bq['h5p_id']) || $bq['h5p_id'] === 0) {
            // Create new branch H5P content
            $branch_title = sprintf('%s - Step %d - Branch Q%d', $post_title, $idx + 1, $qIdx + 1);
            $new_id = PBSG_H5P_Factory::create($quiz, $post_title, $idx + 1, $branch_title);
            if (is_wp_error($new_id)) {
              $h5p_errors[] = sprintf('Step %d Branch Q%d: %s', $idx + 1, $qIdx + 1, $new_id->get_error_message());
            } else {
              $bq['h5p_id'] = $new_id;
            }
          }

          // Strip transient editing flag (keep h5p_id and source fields for re-editing)
          unset($bq['_editing_h5p']);
        }
        unset($bq);
      }

      // ── Embeddability check for URL-type tutorial resources ──
      // Main step, branch-level tutorial, AND each branch question. Without
      // the branch/per-question passes, the student-side renderer has no
      // `embeddable` flag for branch tutorials and always takes Tier 1
      // (iframe) — meaning the popup/viewer fallbacks and the host deny-list
      // only protect the main step.
      // Use check_cached() (not check()) at save-time so the transient is
      // warm for the first student view. resolve_flags() at view-time hits
      // check_cached() as its single source of truth, so warming the cache
      // here avoids a 5-8s synchronous HEAD+GET on the first page load.
      if (!empty($step['tutorial_type']) && $step['tutorial_type'] === 'url' && !empty($step['tutorial_url'])) {
        $embed_result        = PBSG_Embed_Check::check_cached($step['tutorial_url']);
        $step['embeddable']      = $embed_result['embeddable'];
        $step['is_document_url'] = $embed_result['is_document_url'];
      }

      if (!empty($step['branch']) && is_array($step['branch'])) {
        if (!empty($step['branch']['tutorial_type']) && $step['branch']['tutorial_type'] === 'url' && !empty($step['branch']['tutorial_url'])) {
          $b_embed = PBSG_Embed_Check::check_cached($step['branch']['tutorial_url']);
          $step['branch']['tutorial_embeddable']      = $b_embed['embeddable'];
          $step['branch']['tutorial_is_document_url'] = $b_embed['is_document_url'];
        }

        if (!empty($step['branch']['questions']) && is_array($step['branch']['questions'])) {
          foreach ($step['branch']['questions'] as &$bq_ref) {
            if (is_array($bq_ref)
                && !empty($bq_ref['tutorial_type'])
                && $bq_ref['tutorial_type'] === 'url'
                && !empty($bq_ref['tutorial_url'])) {
              $q_embed = PBSG_Embed_Check::check_cached($bq_ref['tutorial_url']);
              $bq_ref['tutorial_embeddable']      = $q_embed['embeddable'];
              $bq_ref['tutorial_is_document_url'] = $q_embed['is_document_url'];
            }
          }
          unset($bq_ref);
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

    // Invalidate H5P usage map — links may have changed
    PBSG_H5P_Usage_Map::invalidate();

    // Track editors who have touched this tutorial (for "Recently Worked On" tab)
    $editors = get_post_meta($post_id, '_pbsg_editors', true);
    if (!is_array($editors)) {
      $editors = [];
    }
    $current_user_id = get_current_user_id();
    $editors[$current_user_id] = [
      'user_id'     => $current_user_id,
      'last_edited' => current_time('mysql'),
    ];
    update_post_meta($post_id, '_pbsg_editors', $editors);

    $note = isset($_POST['pbsg_header_note']) ? sanitize_text_field($_POST['pbsg_header_note']) : '';
    update_post_meta($post_id, self::META_NOTE, $note);

    $close_tutorial_url = isset($_POST['pbsg_close_tutorial_url'])
      ? trim(wp_unslash($_POST['pbsg_close_tutorial_url']))
      : '';

    if ($close_tutorial_url !== '') {
      update_post_meta($post_id, self::META_CLOSE_URL, $close_tutorial_url);
    } else {
      delete_post_meta($post_id, self::META_CLOSE_URL);
    }

    $cover_image_id = isset($_POST['pbsg_cover_image_id']) ? absint($_POST['pbsg_cover_image_id']) : 0;

    if ($cover_image_id > 0) {
      update_post_meta($post_id, self::META_COVER_ID, $cover_image_id);
    } else {
      delete_post_meta($post_id, self::META_COVER_ID);
    }

    // Save structured intro fields (Phase 7)
    $intro_desc = isset($_POST['pbsg_intro_description'])
      ? wp_kses_post(wp_unslash($_POST['pbsg_intro_description'])) : '';
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
    $left_ratio_raw = isset($_POST['pbsg_left_ratio']) ? wp_unslash($_POST['pbsg_left_ratio']) : '';
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

  /**
   * Resolve a cache-bust version string for a plugin-relative asset path.
   *
   * Prefers `filemtime()` (best cache-bust granularity) but gracefully falls
   * back to {@see PB_Split_Guide_Plugin::VERSION} when the file cannot be
   * stat'd — unreadable permissions, broken symlinks, iCloud-sync conflict
   * files, etc. Previously a failed `filemtime()` returned `false` which
   * WordPress passes through to the browser as no version, allowing stale
   * cached assets to persist.
   *
   * @param string $relative Path relative to the plugin directory (e.g. "assets/split-guide.js").
   * @return string Non-empty version string safe for wp_enqueue_*.
   */
  private static function asset_version(string $relative): string {
    $abs = plugin_dir_path(__FILE__) . ltrim($relative, '/');
    $mtime = @filemtime($abs);
    if ($mtime !== false && $mtime > 0) {
      return (string) $mtime;
    }
    return self::VERSION;
  }

  /**
   * Front-end asset loading for tutorial pages.
   *
   * Defense in depth: the template file (split-guide-template.php) also
   * enqueues the same handles. WordPress deduplicates by handle, so the two
   * paths are redundant. This ensures that if the template is bypassed by a
   * theme filter, child-theme override, or a multisite subsite where the
   * plugin's template_include filter doesn't fire, the assets still ship.
   */
  public function enqueue_assets() {
    if (!is_page()) return;

    $page_id = get_queried_object_id();
    $selected = get_post_meta($page_id, '_wp_page_template', true);
    if (!self::is_split_guide_template($selected)) return;

    $base_url = plugin_dir_url(__FILE__);

    wp_enqueue_style(
      'pbsg_split_guide_css',
      $base_url . 'assets/split-guide.css',
      [],
      self::asset_version('assets/split-guide.css')
    );

    // Inject site-level style defaults as CSS custom properties
    $style_defaults = self::resolve_style_defaults();
    // Values are allow-listed by sanitize_style_defaults() — safe for direct
    // insertion. Do NOT use esc_attr() on font stacks — it encodes quotes
    // (e.g. "Segoe UI" → &quot;Segoe UI&quot;) which breaks CSS parsing.
    $inline_css = sprintf(
        ':root{--pbsg-font-family:%s;--pbsg-heading-font:%s;--pbsg-font-size:%s;--pbsg-text-color:%s;--pbsg-accent-color:%s;--pbsg-button-color:%s;}',
        $style_defaults['font_family'],
        $style_defaults['heading_font'],
        $style_defaults['font_size'],
        $style_defaults['text_color'],
        $style_defaults['accent_color'],
        $style_defaults['button_color']
    );
    wp_add_inline_style('pbsg_split_guide_css', $inline_css);

    // Icon set — must load before split-guide.js so PBSG_ICONS.render() is available.
    wp_enqueue_script(
      'pbsg_icons_js',
      $base_url . 'assets/pbsg-icons.js',
      [],
      self::asset_version('assets/pbsg-icons.js'),
      true
    );

    wp_enqueue_script(
      'pbsg-split-guide',
      $base_url . 'assets/split-guide.js',
      ['pbsg_icons_js'],
      self::asset_version('assets/split-guide.js'),
      true
    );

    // Only load analytics tracker on published tutorials — prevents draft/preview pollution
    if ( get_post_status( $page_id ) === 'publish' ) {
        wp_enqueue_script(
            'pbsg-tracker',
            $base_url . 'assets/split-guide-tracker.js',
            [],
            self::asset_version('assets/split-guide-tracker.js'),
            true
        );

        $steps_json = get_post_meta( $page_id, '_pbsg_steps_json', true );
        $steps_data = json_decode( $steps_json, true );
        $total_steps = is_array( $steps_data ) ? count( $steps_data ) : 1;

        // Must come AFTER wp_enqueue_script — localize attaches to a registered handle.
        wp_localize_script( 'pbsg-tracker', 'pbsgTracker', array(
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'tutorialPageId' => $page_id,
            'totalSteps'     => $total_steps,
        ) );
    }
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
    if (self::is_split_guide_template($template)) {
      remove_post_type_support('page', 'editor');
    }
  }

  /**
   * Disable the block editor for tutorial pages where the classic editor is removed.
   * Without this, Gutenberg still mounts and the main column stays empty (editor support is off).
   */
  public function use_block_editor_for_page_tutorial($use_block_editor, $post_type) {
    if ($post_type !== 'page') {
      return $use_block_editor;
    }
    global $pagenow;
    if (!in_array($pagenow, ['post.php', 'post-new.php'], true)) {
      return $use_block_editor;
    }
    if ($pagenow === 'post-new.php') {
      return false;
    }
    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    $tpl     = $post_id > 0 ? get_post_meta($post_id, '_wp_page_template', true) : '';
    if ($post_id > 0 && self::is_split_guide_template($tpl)) {
      return false;
    }
    return $use_block_editor;
  }

  /**
   * @param bool    $use_block_editor
   * @param WP_Post $post
   */
  public function use_block_editor_for_split_guide_post($use_block_editor, $post) {
    if (!$post instanceof \WP_Post || $post->post_type !== 'page') {
      return $use_block_editor;
    }
    $tpl = get_post_meta($post->ID, '_wp_page_template', true);
    if (self::is_split_guide_template($tpl)) {
      return false;
    }
    return $use_block_editor;
  }

  public function enqueue_admin_assets($hook) {
  $screen = get_current_screen();

  // Post editor assets — only on page post type
  if (in_array($hook, ['post.php', 'post-new.php'], true) && $screen && $screen->post_type === 'page') {
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

    // Icon set — must load before admin-split-guide.js so PBSG_ICONS.render() is available.
    wp_enqueue_script(
      'pbsg_icons_js',
      plugin_dir_url(__FILE__) . 'assets/pbsg-icons.js',
      [],
      '1.0.0',
      true
    );

    wp_enqueue_script(
      'pbsg_admin_js',
      plugin_dir_url(__FILE__) . 'assets/admin-split-guide.js',
      ['jquery', 'thickbox', 'pbsg_icons_js'],
      '0.8.0',
      true
    );

    wp_enqueue_style(
      'pbsg-admin',
      plugin_dir_url(__FILE__) . 'assets/admin/admin-split-guide.css',
      [],
      '2.1.2'  // bumped to force cache bust
    );

    $current_template = get_post_meta(get_the_ID(), '_wp_page_template', true);
    $post_id          = get_the_ID();
    $post_title       = $post_id ? get_the_title($post_id) : '';

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
      'currentUserId'   => get_current_user_id(),
      'maxUploadSize'   => wp_max_upload_size(),
      'maxUploadLabel'  => size_format(wp_max_upload_size()),
      'postTitle'       => is_string($post_title) ? trim($post_title) : '',
      'benchmarkDefaults' => self::resolve_benchmarks(0),
      'ratioDefault'      => intval(get_option(self::OPTION_DEFAULT_RATIO, self::RATIO_DEFAULT)),
      'strings'         => [
        'confirmRemoveStep' => __(
          'Are you sure you want to remove Step %1$d: %2$s? Quiz and resource settings for this step will be lost.',
          'pb-split-guide'
        ),
        'untitledStep'      => __('(Untitled step)', 'pb-split-guide'),
        'leaveWithTitle'    => __(
          'You have unsaved changes to "%s". Leave without saving?',
          'pb-split-guide'
        ),
        'leaveGeneric'      => __(
          'You have unsaved changes to this tutorial. Leave without saving?',
          'pb-split-guide'
        ),
      ],
    ]);

    wp_localize_script('pbsg_admin_js', 'pbsgStyleDefaults', self::resolve_style_defaults());

    // Load TinyMCE editor scripts. We enqueue the 'editor' handle directly instead
    // of calling wp_enqueue_editor() because wp_enqueue_editor() in Pressbooks breaks
    // admin footer script output. The 'editor' handle pulls in TinyMCE and wp.editor.
    wp_enqueue_script('wp-tinymce');
    wp_enqueue_script('editor');
    wp_enqueue_script('quicktags');
    wp_enqueue_style('editor-buttons');

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
  } // end post editor assets

  // Slider CSS + JS on Guide Settings page (benchmark sliders + style defaults)
  if (isset($_GET['page']) && $_GET['page'] === 'pbsg-guide-settings') {
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    wp_enqueue_style(
      'pbsg-admin',
      plugin_dir_url(__FILE__) . 'assets/admin/admin-split-guide.css',
      [],
      '2.1.2'
    );
    wp_enqueue_script(
      'pbsg_icons_js',
      plugin_dir_url(__FILE__) . 'assets/pbsg-icons.js',
      [],
      '1.0.0',
      true
    );
    wp_enqueue_script(
      'pbsg_admin_js',
      plugin_dir_url(__FILE__) . 'assets/admin-split-guide.js',
      ['jquery', 'pbsg_icons_js'],
      '0.8.0',
      true
    );
    wp_localize_script('pbsg_admin_js', 'PBSG_ADMIN', [
      'benchmarkDefaults' => self::resolve_benchmarks(0),
      'ratioDefault'      => intval(get_option(self::OPTION_DEFAULT_RATIO, self::RATIO_DEFAULT)),
    ]);
  }

  // Cross-edit JS — needed on Guide Settings, My Tutorials, post editor, and Pages list
  $cross_edit_screens = ['pbsg-my-tutorials', 'pbsg-guide-settings'];
  $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
  $is_pages_list = ($hook === 'edit.php' && $screen && $screen->post_type === 'page');
  if (in_array($current_page, $cross_edit_screens, true) || $hook === 'post.php' || $hook === 'post-new.php' || $is_pages_list) {
    wp_enqueue_style(
      'pbsg-modal',
      plugin_dir_url(__FILE__) . 'assets/admin/pbsg-modal.css',
      [],
      '0.1.0'
    );
    wp_enqueue_script(
      'pbsg-modal',
      plugin_dir_url(__FILE__) . 'assets/admin/pbsg-modal.js',
      [],
      '0.1.0',
      true
    );
    wp_enqueue_style(
      'pbsg-admin-cross-edit',
      plugin_dir_url(__FILE__) . 'assets/admin/admin-cross-edit.css',
      [],
      '0.7.1'
    );
    wp_enqueue_script(
      'pbsg-admin-cross-edit',
      plugin_dir_url(__FILE__) . 'assets/admin/admin-cross-edit.js',
      ['jquery', 'pbsg-modal'],
      '0.8.0',
      true
    );
    wp_localize_script('pbsg-admin-cross-edit', 'pbsgModalIcons', [
      'lockClosed' => pbsg_icon('lock-closed'),
      'lockOpen'   => pbsg_icon('lock-open'),
      'shuffle'    => pbsg_icon('shuffle'),
      'refresh'    => pbsg_icon('refresh'),
    ]);
    // Check for pending bulk transfer from Pages list
    $bulk_transfer_ids = [];
    if ( isset($_GET['pbsg_bulk_transfer']) && $_GET['pbsg_bulk_transfer'] === '1' ) {
      $stored = get_transient( 'pbsg_bulk_transfer_' . get_current_user_id() );
      if ( is_array($stored) ) {
        $bulk_transfer_ids = $stored;
        delete_transient( 'pbsg_bulk_transfer_' . get_current_user_id() );
      }
    }

    wp_localize_script('pbsg-admin-cross-edit', 'pbsgCrossEdit', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce('pbsg_transfer_ownership'),
      'isAdmin' => PBSG_Roles::is_admin(),
      'transferEnabled' => self::is_transfer_enabled(),
      'currentUserId' => get_current_user_id(),
      'bulkTransferIds' => $bulk_transfer_ids,
    ]);
  }
}

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

  /**
   * AJAX: Transfer tutorial ownership.
   *
   * Validates nonce, ownership, toggle state, target user role.
   * Admins can always transfer; librarians only when toggle is ON and they own the tutorial.
   */
  public function ajax_transfer_ownership() {
    check_ajax_referer('pbsg_transfer_ownership', '_wpnonce');

    $post_ids     = isset($_POST['post_ids']) ? array_map('absint', (array) $_POST['post_ids']) : [];
    $new_owner_id = isset($_POST['new_owner_id']) ? absint($_POST['new_owner_id']) : 0;

    if (empty($post_ids) || !$new_owner_id) {
      wp_send_json_error(['message' => __('Invalid request parameters.', 'pb-split-guide')]);
    }

    $is_admin = PBSG_Roles::is_admin();
    $current_user_id = get_current_user_id();

    // Check transfer toggle (admins exempt)
    if (!$is_admin && !self::is_transfer_enabled()) {
      wp_send_json_error(['message' => __('Ownership transfer is disabled.', 'pb-split-guide')]);
    }

    // Validate target user has librarian or admin role on this site
    $target_user = get_userdata($new_owner_id);
    if (!$target_user) {
      wp_send_json_error(['message' => __('Target user not found.', 'pb-split-guide')]);
    }
    $valid_roles = [PBSG_Roles::LIBRARIAN_ROLE, 'administrator'];
    $has_valid_role = !empty(array_intersect($valid_roles, $target_user->roles));
    if (!$has_valid_role) {
      wp_send_json_error(['message' => __('Target user must be a librarian or administrator.', 'pb-split-guide')]);
    }

    // Validate each post
    $transferred = [];
    foreach ($post_ids as $post_id) {
      $post = get_post($post_id);
      if (!$post || $post->post_type !== 'page') {
        wp_send_json_error(['message' => sprintf(__('Post %d is not a valid page.', 'pb-split-guide'), $post_id)]);
      }

      // Must be a Split Guide tutorial
      if (!PBSG_Roles::is_tutorial($post_id)) {
        wp_send_json_error(['message' => sprintf(__('Post %d is not a tutorial.', 'pb-split-guide'), $post_id)]);
      }

      // Non-admins must own the tutorial
      if (!$is_admin && (int) $post->post_author !== $current_user_id) {
        wp_send_json_error(['message' => sprintf(__('You do not own the tutorial "%s".', 'pb-split-guide'), get_the_title($post_id))]);
      }

      // No self-transfer
      if ((int) $post->post_author === $new_owner_id) {
        wp_send_json_error(['message' => sprintf(__('"%s" is already owned by that user.', 'pb-split-guide'), get_the_title($post_id))]);
      }

      $transferred[] = $post_id;
    }

    // Execute transfer
    global $wpdb;
    foreach ($transferred as $post_id) {
      $wpdb->update(
        $wpdb->posts,
        ['post_author' => $new_owner_id],
        ['ID' => $post_id],
        ['%d'],
        ['%d']
      );
      clean_post_cache($post_id);
    }

    wp_send_json_success([
      'message'     => sprintf(
        _n(
          '%d tutorial transferred successfully.',
          '%d tutorials transferred successfully.',
          count($transferred),
          'pb-split-guide'
        ),
        count($transferred)
      ),
      'transferred' => $transferred,
    ]);
  }

  /**
   * AJAX: Get eligible transfer targets (librarians + admins).
   */
  public function ajax_get_transfer_targets() {
    check_ajax_referer('pbsg_transfer_ownership', '_wpnonce');

    $exclude_id = get_current_user_id();
    $targets = PBSG_Librarian_Manager::get_reassignment_targets($exclude_id);

    wp_send_json_success(['targets' => $targets]);
  }

  // ── Bulk Action: Transfer Ownership on Pages list ─────────────────────────

  /**
   * Add "Transfer Ownership" to the Pages (Tutorials) list table bulk actions.
   */
  public function register_transfer_bulk_action( $bulk_actions ) {
    if ( PBSG_Roles::is_admin() || self::is_transfer_enabled() ) {
      $bulk_actions['pbsg_transfer_ownership'] = __( 'Transfer Ownership', 'pb-split-guide' );
    }
    return $bulk_actions;
  }

  /**
   * Handle the "Transfer Ownership" bulk action from Pages list.
   * Redirects to My Tutorials page with a modal trigger param.
   */
  public function handle_transfer_bulk_action( $redirect_to, $doaction, $post_ids ) {
    if ( $doaction !== 'pbsg_transfer_ownership' ) {
      return $redirect_to;
    }

    // Filter to only tutorials the current user can transfer
    $is_admin = PBSG_Roles::is_admin();
    $current_user_id = get_current_user_id();
    $valid_ids = [];

    foreach ( $post_ids as $post_id ) {
      $post_id = absint( $post_id );
      if ( ! PBSG_Roles::is_tutorial( $post_id ) ) {
        continue; // Skip non-tutorial pages
      }
      $post = get_post( $post_id );
      if ( ! $post ) continue;

      // Non-admins can only transfer their own
      if ( ! $is_admin && (int) $post->post_author !== $current_user_id ) {
        continue;
      }
      $valid_ids[] = $post_id;
    }

    if ( empty( $valid_ids ) ) {
      return add_query_arg( 'pbsg_transfer_error', 'no_valid', $redirect_to );
    }

    // Store in transient for the modal to pick up
    set_transient( 'pbsg_bulk_transfer_' . $current_user_id, $valid_ids, 300 );

    return add_query_arg( [
      'page'               => 'pbsg-my-tutorials',
      'pbsg_bulk_transfer' => '1',
    ], admin_url( 'admin.php' ) );
  }

  /**
   * Show notice if transfer bulk action had no valid tutorials.
   */
  public function transfer_bulk_action_notice() {
    if ( ! isset( $_GET['pbsg_transfer_error'] ) ) return;

    $error = sanitize_text_field( $_GET['pbsg_transfer_error'] );
    if ( $error === 'no_valid' ) {
      echo '<div class="notice notice-warning is-dismissible"><p>';
      esc_html_e( 'No eligible tutorials found for transfer. You can only transfer tutorials you own.', 'pb-split-guide' );
      echo '</p></div>';
    }
  }

  // ── Template Picker ────────────────────────────────────────────────────────

  /**
   * Redirect post-new.php?post_type=page → template picker page.
   */
  public function maybe_redirect_to_template_picker() {
    global $pagenow;
    if ($pagenow !== 'post-new.php') return;
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

  // ── H5P ───────────────────────────────────────────────────────────────────

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

    $rows = $wpdb->get_results(
      "SELECT c.id, c.title, c.user_id, u.display_name AS author_name
       FROM {$table} c
       LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID
       ORDER BY c.id DESC LIMIT 300",
      ARRAY_A
    );

    $current_user_id = get_current_user_id();

    $items = array_map(function ($r) use ($current_user_id) {
      $user_id = (int) $r['user_id'];
      return [
        'id'          => (int) $r['id'],
        'title'       => $r['title'] ? $r['title'] : ('H5P #' . (int) $r['id']),
        'user_id'     => $user_id,
        'author_name' => $r['author_name'] ?: 'Unknown user',
        'is_owner'    => $user_id === $current_user_id,
        'usage_count' => PBSG_H5P_Usage_Map::count((int) $r['id']),
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

  /**
   * AJAX: Rename an H5P content item. Owner-only.
   */
  public function ajax_rename_h5p() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    if (!current_user_can('edit_h5p_contents')) {
      wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $h5p_id = absint($_POST['h5p_id'] ?? 0);
    $title  = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));

    if ($h5p_id <= 0) {
      wp_send_json_error(['message' => 'Invalid H5P ID']);
    }

    if ($title === '') {
      wp_send_json_error(['message' => 'Title cannot be empty']);
    }

    // Verify ownership
    global $wpdb;
    $owner_id = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT user_id FROM {$wpdb->prefix}h5p_contents WHERE id = %d",
      $h5p_id
    ));

    if ($owner_id !== get_current_user_id()) {
      wp_send_json_error(['message' => 'Only the owner can rename this H5P content']);
    }

    $wpdb->update(
      $wpdb->prefix . 'h5p_contents',
      ['title' => $title],
      ['id' => $h5p_id],
      ['%s'],
      ['%d']
    );

    wp_send_json_success();
  }

  /**
   * AJAX: Duplicate an existing H5P content item as a new copy.
   * Current user becomes the owner. Auto-generates title from tutorial context.
   */
  public function ajax_duplicate_h5p() {
    check_ajax_referer('pbsg_h5p_picker', 'nonce');

    if (!current_user_can('edit_h5p_contents')) {
      wp_send_json_error(['message' => 'Insufficient permissions']);
    }

    $source_id  = absint($_POST['h5p_id'] ?? 0);
    $post_title = sanitize_text_field(wp_unslash($_POST['post_title'] ?? ''));
    $step_index = absint($_POST['step_index'] ?? 0);

    if ($source_id <= 0) {
      wp_send_json_error(['message' => 'Invalid source H5P ID']);
    }

    global $wpdb;
    $source = $wpdb->get_row($wpdb->prepare(
      "SELECT c.parameters, c.library_id, c.license, l.name AS library_name,
              l.major_version, l.minor_version
       FROM {$wpdb->prefix}h5p_contents c
       JOIN {$wpdb->prefix}h5p_libraries l ON c.library_id = l.id
       WHERE c.id = %d",
      $source_id
    ), ARRAY_A);

    if (!$source) {
      wp_send_json_error(['message' => 'Source H5P content not found']);
    }

    // Build the new title using the same convention as PBSG_H5P_Factory
    $new_title = PBSG_H5P_Factory::generate_title($post_title, $step_index, '');

    // Reverse the source into a quiz schema, then re-create via Factory
    $quiz = PBSG_H5P_Factory::reverse($source['library_name'], $source['parameters']);

    if (!$quiz) {
      // Unsupported content type — fall back to raw parameter copy
      $core = PBSG_H5P_Factory::get_h5p_core();
      if (is_wp_error($core)) {
        wp_send_json_error(['message' => $core->get_error_message()]);
      }

      $library = [
        'machineName'  => $source['library_name'],
        'majorVersion' => (int) $source['major_version'],
        'minorVersion' => (int) $source['minor_version'],
      ];

      $content = [
        'library'  => $library,
        'params'   => $source['parameters'],
        'metadata' => [
          'title'   => $new_title,
          'license' => $source['license'] ?: 'U',
        ],
        'disable' => 1,
      ];

      $new_id = $core->saveContent($content);

      if (!$new_id || $new_id < 1) {
        wp_send_json_error(['message' => 'Failed to duplicate H5P content']);
      }
    } else {
      // Supported type — use Factory::create for clean duplication
      $new_id = PBSG_H5P_Factory::create($quiz, $post_title, $step_index, '');

      if (is_wp_error($new_id)) {
        wp_send_json_error(['message' => $new_id->get_error_message()]);
      }
    }

    // Invalidate usage map transient
    PBSG_H5P_Usage_Map::invalidate();

    wp_send_json_success([
      'h5p_id' => (int) $new_id,
      'title'  => $new_title,
    ]);
  }

  /**
   * AJAX handler: list all Guide-on-the-Side tutorial pages.
   *
   * Returns an array of pages that use the split-guide template.
   */
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

  /**
   * AJAX: Just-in-time embeddability probe.
   *
   * Called by split-guide.js immediately before setting iframe.src for a
   * URL-type tutorial step. Runs PBSG_Embed_Check::check_cached() (HEAD+GET
   * with 1-hour transient cache) and returns the verdict so the client can
   * render the popup-fallback card when the target can't be iframed.
   *
   * This closes the gap where:
   *   • The save-time meta said `embeddable=true` (e.g. old tutorial, or
   *     HEAD succeeded at save-time but the server now 4xx's),
   *   • The browser's `load` event fires even for XFO/CSP-blocked frames
   *     (Chrome behaviour), so a purely client-side heuristic can't tell
   *     a block apart from a legitimate cross-origin render.
   *
   * Defenses:
   *   • Nonce verification (anti-CSRF).
   *   • Scheme must be http(s) — rejects javascript:, data:, file:, etc.
   *   • Hostname must not be loopback/private/link-local/.local/.test
   *     (anti-SSRF). DNS is resolved to also check the resolved IP so a
   *     public hostname that resolves to a private address is rejected.
   */
  public function ajax_probe_embed() {
    // 1. Nonce (scoped to this action).
    check_ajax_referer('pbsg_probe_embed', 'nonce');

    $raw = isset($_POST['url']) ? wp_unslash($_POST['url']) : '';
    $url = esc_url_raw(trim((string) $raw));

    if ($url === '') {
      wp_send_json_error(['message' => 'Missing url.'], 400);
    }

    // 2. Scheme guard — http(s) only.
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      wp_send_json_error(['message' => 'Invalid url.'], 400);
    }
    $scheme = strtolower((string) $parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
      wp_send_json_error(['message' => 'Unsupported scheme.'], 400);
    }

    // 3. Host + DNS guard — block loopback/private/link-local/dev-only hosts.
    $host = strtolower((string) $parts['host']);
    if ($this->pbsg_host_is_ssrf_risk($host)) {
      // Treat as non-embeddable instead of erroring — the client still
      // needs a verdict, and a dev-only host shouldn't be framed in a
      // public tutorial anyway.
      wp_send_json_success([
        'embeddable'      => false,
        'is_document_url' => false,
        'source'          => 'ssrf_guard',
      ]);
    }

    // 4. Delegate to the cached embed-check.
    $verdict = PBSG_Embed_Check::check_cached($url);

    wp_send_json_success([
      'embeddable'      => !empty($verdict['embeddable']),
      'is_document_url' => !empty($verdict['is_document_url']),
      'source'          => 'probe',
    ]);
  }

  /**
   * Return true if the hostname is a loopback, private-range, link-local,
   * or dev-only (.local/.test) address. Used by ajax_probe_embed() to
   * reject SSRF-style URLs before dispatching an outbound HTTP request.
   *
   * Resolves the host via gethostbyname()/dns_get_record() so a public
   * name that points at a private IP (DNS-rebinding style) is caught.
   * Resolution failure is treated as risky.
   *
   * @param string $host Lowercased hostname from parse_url().
   * @return bool True = reject, false = safe to probe.
   */
  private function pbsg_host_is_ssrf_risk(string $host): bool {
    if ($host === '') {
      return true;
    }

    // Dev-only TLDs and bare loopback names.
    if ($host === 'localhost' || $host === 'ip6-localhost' || $host === 'ip6-loopback') {
      return true;
    }
    if (str_ends_with($host, '.local') || str_ends_with($host, '.test') ||
        str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
      return true;
    }

    // Collect candidate IPs: the host itself if it IS an IP literal,
    // otherwise all A/AAAA records.
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
      $ips[] = $host;
    } else {
      // gethostbynamel returns IPv4 addresses; supplement with AAAA via
      // dns_get_record if available.
      if (function_exists('gethostbynamel')) {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
          $ips = array_merge($ips, $v4);
        }
      }
      if (function_exists('dns_get_record')) {
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
          foreach ($aaaa as $rec) {
            if (!empty($rec['ipv6'])) {
              $ips[] = $rec['ipv6'];
            }
          }
        }
      }
    }

    // No resolvable IPs — [Inference] could be a legitimate host whose DNS
    // is briefly unreachable from the PHP worker, or could be a dead link.
    // Either way, skip the outbound request to avoid retry storms.
    if (empty($ips)) {
      return true;
    }

    foreach ($ips as $ip) {
      // FILTER_FLAG_NO_PRIV_RANGE: reject RFC1918 + RFC4193 (ULA).
      // FILTER_FLAG_NO_RES_RANGE : reject loopback, link-local, reserved.
      $ok = filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
      );
      if ($ok === false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Render the PBSG-410 error page when a tutorial becomes unavailable mid-session.
   * Triggered by /?pbsg-error=410 redirect from split-guide-tracker.js.
   */
  public function handle_error_page() {
    if ( ! isset( $_GET['pbsg-error'] ) || $_GET['pbsg-error'] !== '410' ) {
        return;
    }

    status_header( 410 );
    get_header();
    ?>
    <style>
        .pbsg-error-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 60vh;
            padding: 2rem 1rem;
            font-family: 'Roboto', Arial, sans-serif;
        }
        .pbsg-error-card {
            background: #F8F8F8;
            border: 1px solid #E0E0E0;
            border-radius: 8px;
            max-width: 480px;
            width: 100%;
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .pbsg-error-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pbsg-error-icon svg {
            width: 48px;
            height: 48px;
        }
        .pbsg-error-card h1 {
            font-family: 'Lusitana', Georgia, serif;
            color: #333333;
            font-size: 1.5rem;
            margin: 0 0 1rem;
        }
        .pbsg-error-card p {
            color: #333333;
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0 0 1.5rem;
        }
        .pbsg-error-close {
            display: inline-block;
            background: #517E1B;
            color: #fff;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 4px;
            font-family: 'Roboto Condensed', Arial, sans-serif;
            font-size: 0.95rem;
            cursor: pointer;
            min-width: 44px;
            min-height: 44px;
        }
        .pbsg-error-close:hover {
            background: #436819;
        }
        .pbsg-error-close:focus {
            outline: 2px solid #517E1B;
            outline-offset: 2px;
        }
        .pbsg-error-fallback {
            display: none;
            color: #666;
            font-size: 0.85rem;
            margin-top: 1rem;
        }
        .pbsg-error-code {
            color: #999;
            font-family: 'Roboto Condensed', Arial, sans-serif;
            font-size: 0.8rem;
            margin-top: 1.5rem;
        }
    </style>
    <div class="pbsg-error-wrap">
        <div class="pbsg-error-card" role="alert">
            <div class="pbsg-error-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="#8C2004" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <h1><?php echo esc_html__( 'Tutorial Unavailable', 'pb-split-guide' ); ?></h1>
            <p><?php echo esc_html__( 'The tutorial you were working on is no longer available. It may have been unpublished or removed.', 'pb-split-guide' ); ?></p>
            <p><?php echo esc_html__( 'If you believe this is an error, please contact your librarian.', 'pb-split-guide' ); ?></p>
            <button type="button" class="pbsg-error-close" id="pbsg-close-btn" aria-label="<?php echo esc_attr__( 'Close this page', 'pb-split-guide' ); ?>">
                <?php echo esc_html__( 'Close Page', 'pb-split-guide' ); ?>
            </button>
            <p class="pbsg-error-fallback" id="pbsg-close-fallback">
                <?php echo esc_html__( 'If this page didn\'t close, you can safely close this tab.', 'pb-split-guide' ); ?>
            </p>
            <p class="pbsg-error-code"><?php echo esc_html__( 'Error: PBSG-410', 'pb-split-guide' ); ?></p>
        </div>
    </div>
    <script>
    document.getElementById('pbsg-close-btn').addEventListener('click', function() {
        window.close();
        setTimeout(function() {
            document.getElementById('pbsg-close-fallback').style.display = 'block';
        }, 500);
    });
    </script>
    <?php
    get_footer();
    exit;
  }
}

new PB_Split_Guide_Plugin();

register_activation_hook( __FILE__, array( 'PBSG_Roles', 'activate' ) );
register_activation_hook( __FILE__, array( 'PBSG_Analytics', 'create_tables' ) );
register_deactivation_hook( __FILE__, array( 'PBSG_Roles', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'PB_Split_Guide_Plugin', 'uninstall' ) );

PBSG_Roles::init();
PBSG_Analytics::init();
PBSG_Analytics_Dashboard::init();
PBSG_Certificate::init();
PBSG_Admin_Menu_Filter::init();
PBSG_Librarian_Manager::init();
PBSG_Export_Import::init();
