<?php
/**
 * Plugin Name: PB Split Guide (Multi-step H5P + Tutorial)
 * Description: Adds a Tutorial Page with a split-screen Template. Supports multiple steps (each step = H5P quiz + tutorial URL) with Prev/Next navigation on the same page.
 * Version: 0.4.0
 * Author: Team 8
 */

if (!defined('ABSPATH')) exit;

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
      <p><strong>Steps</strong> (each step = one H5P quiz + one tutorial URL)</p>

      <table class="widefat striped" id="pbsg-steps-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width: 25%;">Step title (optional)</th>
            <th style="width: 22%;">H5P</th>
            <th>Tutorial URL</th>
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

      <p><em>Tip: Click “Add H5P” in a row to pick an existing quiz.</em></p>
    </div>
    <?php
  }

  public function save_meta($post_id, $post) {
    if (!isset($_POST['pbsg_nonce']) || !wp_verify_nonce($_POST['pbsg_nonce'], 'pbsg_save_meta')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $steps_json = isset($_POST['pbsg_steps_json']) ? wp_unslash($_POST['pbsg_steps_json']) : '[]';
    $steps = json_decode($steps_json, true);
    if (!is_array($steps)) $steps = [];

    $clean = [];
    foreach ($steps as $s) {
      $h5p_id = isset($s['h5p_id']) ? (int)$s['h5p_id'] : 0;
      $url    = isset($s['url']) ? esc_url_raw($s['url']) : '';
      $title  = isset($s['title']) ? sanitize_text_field($s['title']) : '';

      if ($h5p_id <= 0 && $url === '' && $title === '') continue;

      $clean[] = [
        'title'  => $title,
        'h5p_id' => $h5p_id,
        'url'    => $url,
      ];
    }

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
      '0.4.0'
    );
  }

  public function enqueue_admin_assets($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;

    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'page') return;

    add_thickbox();

    wp_enqueue_script(
      'pbsg_admin_js',
      plugin_dir_url(__FILE__) . 'assets/admin-split-guide.js',
      ['jquery', 'thickbox'],
      '0.4.0',
      true
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
