<?php
/**
 * Plugin Name: PB Split Guide (Multi-step H5P + Tutorial)
 * Description: Adds a Tutorial Page with a split-screen Template. Supports multiple steps (each step = H5P quiz + tutorial source) with Prev/Next navigation on the same page.
 * Version: 0.5.1
 * Author: Team 8
 */

if (!defined('ABSPATH')) exit;

$autoload = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
}

require_once plugin_dir_path(__FILE__) . 'includes/steps-normalizer.php';
require_once plugin_dir_path(__FILE__) . 'class-pbsg-analytics.php';
require_once plugin_dir_path(__FILE__) . 'class-pbsg-analytics-dashboard.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pbsg-certificate.php';

class PB_Split_Guide_Plugin {
  const TEMPLATE_SLUG = 'split-guide-template.php';
  const META_STEPS     = '_pbsg_steps_json';
  const META_NOTE      = '_pbsg_header_note';
  const META_COVER_ID  = '_pbsg_cover_image_id';  
  const META_COVER_URL = '_pbsg_cover_image_url';
  const OVERVIEW_SLUG = 'my-tutorials';

  public function __construct() {
    add_filter('theme_page_templates', [$this, 'register_page_template']);
    add_filter('template_include', [$this, 'load_page_template']);

    add_action('add_meta_boxes_page', [$this, 'add_meta_boxes']);
    add_action('save_post_page', [$this, 'save_meta'], 10, 2);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('wp_ajax_pbsg_list_h5p', [$this, 'ajax_list_h5p']);

    add_filter('editable_roles', [$this, 'filter_editable_roles']);
    add_filter('login_redirect', [$this, 'redirect_after_login'], 10, 3);

    add_action('admin_menu', [$this, 'register_my_tutorials_menu'], 30);
    add_action('admin_menu', [$this, 'hide_admin_menus_for_student'], 999);
    add_action('admin_head', [$this, 'output_role_ui_css']);
    add_action('admin_footer', [$this, 'output_role_ui_js']);

    add_shortcode('pbsg_tutorial_cards', [$this, 'render_tutorial_cards_shortcode']);
    add_action('admin_head', [$this, 'pbsg_hide_editor_for_splitguide']);
  }

  private function current_roles() {
    $user = wp_get_current_user();
    return $user ? (array) $user->roles : [];
  }

  private function is_student_user() {
    return in_array('student', $this->current_roles(), true);
  }

  private function is_librarian_user() {
    return in_array('librarian', $this->current_roles(), true);
  }

  private function is_admin_user() {
    return is_super_admin() || in_array('administrator', $this->current_roles(), true);
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

    $cover_id  = (int) get_post_meta($post->ID, self::META_COVER_ID, true);
    $cover_url = get_post_meta($post->ID, self::META_COVER_URL, true);

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


       <p>
        <strong>Tutorial Cover Image (optional)</strong>
        </p>

        <div class="pbsg-cover-field" style="margin-bottom:14px;">
            <div style="margin-bottom:10px;">
                <img
                id="pbsg_cover_preview"
                src="<?php echo esc_url($cover_url); ?>"
                alt=""
                style="max-width:240px; height:auto; display:<?php echo $cover_url ? 'block' : 'none'; ?>; border:1px solid #ddd; padding:4px; background:#fff;"
                />
            </div>

            <input type="hidden" id="pbsg_cover_image_id" name="pbsg_cover_image_id" value="<?php echo esc_attr($cover_id); ?>" />
            <input type="hidden" id="pbsg_cover_image_url" name="pbsg_cover_image_url" value="<?php echo esc_attr($cover_url); ?>" />

            <button type="button" class="button" id="pbsg_pick_cover_image">Choose Cover Image</button>
            <button type="button" class="button" id="pbsg_clear_cover_image">Clear Cover</button>

            <p class="description" style="margin-top:8px;">
                This image will be used on the tutorial overview page.
            </p>
        </div>      

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

      <p><em>Tip: Click “Add H5P” to pick a quiz. Click “Set Tutorial” to choose URL or upload PDF/slides, and optionally set a cover image.</em></p>
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
    update_post_meta($post_id, self::META_STEPS, wp_json_encode($clean));

    $note = isset($_POST['pbsg_header_note']) ? sanitize_text_field($_POST['pbsg_header_note']) : '';
    update_post_meta($post_id, self::META_NOTE, $note);

    $cover_id  = isset($_POST['pbsg_cover_image_id']) ? absint($_POST['pbsg_cover_image_id']) : 0;
    $cover_url = isset($_POST['pbsg_cover_image_url']) ? esc_url_raw($_POST['pbsg_cover_image_url']) : '';

    update_post_meta($post_id, self::META_COVER_ID, $cover_id);
    update_post_meta($post_id, self::META_COVER_URL, $cover_url);
  }

  public function render_tutorial_cards_shortcode($atts = []) {
    $query = new WP_Query([
        'post_type'      => 'page',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'meta_key'       => '_wp_page_template',
        'meta_value'     => self::TEMPLATE_SLUG,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ]);

    ob_start();
    ?>
    <div class="pbsg-course-overview-wrap">
        <h1 class="pbsg-course-overview-title">My courses</h1>
        <h2 class="pbsg-course-overview-subtitle">Course overview</h2>

        <div class="pbsg-course-grid">
        <?php if ($query->have_posts()) : ?>
            <?php while ($query->have_posts()) : $query->the_post(); ?>
            <?php
                $page_id    = get_the_ID();
                $title      = get_the_title($page_id);
                $link       = get_permalink($page_id);
                $cover_url  = get_post_meta($page_id, self::META_COVER_URL, true);

                if (!$cover_url) {
                $cover_url = plugin_dir_url(__FILE__) . 'assets/default-course-cover.jpg';
                }
            ?>
            <article class="pbsg-course-card">
                <div class="pbsg-course-card-image-wrap">
                <a href="<?php echo esc_url($link); ?>">
                    <img
                    class="pbsg-course-card-image"
                    src="<?php echo esc_url($cover_url); ?>"
                    alt="<?php echo esc_attr($title); ?>"
                    />
                </a>
                </div>

                <div class="pbsg-course-card-body">
                <h3 class="pbsg-course-card-title">
                    <a href="<?php echo esc_url($link); ?>">
                    <?php echo esc_html($title); ?>
                    </a>
                </h3>

                <div class="pbsg-course-card-meta">
                    Tutorial
                </div>
                </div>
            </article>
            <?php endwhile; ?>
            <?php wp_reset_postdata(); ?>
        <?php else : ?>
            <p>No tutorials found.</p>
        <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
    }

  public function enqueue_assets() {
    if (!is_page()) return;

    $page_id = get_queried_object_id();
    $selected = get_post_meta($page_id, '_wp_page_template', true);

    wp_enqueue_style(
    'pbsg_split_guide_css',
    plugin_dir_url(__FILE__) . 'assets/split-guide.css',
    [],
    '0.5.1'
    );

    if ($selected !== self::TEMPLATE_SLUG) {
    return;
    }

    $steps_json = get_post_meta($page_id, '_pbsg_steps_json', true);
    $steps_data = json_decode($steps_json, true);
    $total_steps = is_array($steps_data) ? count($steps_data) : 1;

    wp_localize_script('pbsg-tracker', 'pbsgTracker', array(
      'ajaxUrl'        => admin_url('admin-ajax.php'),
      'tutorialPageId' => $page_id,
      'totalSteps'     => $total_steps,
    ));
  }

  public function enqueue_admin_assets($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;

    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'page') return;

    add_thickbox();
    wp_enqueue_media();

    wp_enqueue_script(
      'pbsg_admin_js',
      plugin_dir_url(__FILE__) . 'assets/admin-split-guide.js',
      ['jquery', 'thickbox'],
      '0.5.1',
      true
    );

    wp_enqueue_style(
      'pbsg-admin',
      plugin_dir_url(__FILE__) . 'assets/admin/admin-split-guide.css',
      [],
      '1.0.2'
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

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
      wp_send_json_error(['message' => 'H5P table not found. Are you using the standard H5P plugin?']);
    }

    $rows = $wpdb->get_results("SELECT id, title FROM {$table} ORDER BY id DESC LIMIT 300", ARRAY_A);

    $items = array_map(function ($r) {
      return [
        'id' => (int) $r['id'],
        'title' => $r['title'] ? $r['title'] : ('H5P #' . (int) $r['id']),
      ];
    }, $rows ?: []);

    wp_send_json_success(['items' => $items]);
  }

  public static function add_custom_roles() {
    add_role('student', 'Student', array(
      'read' => true,
      'level_0' => true,
    ));

    add_role('librarian', 'Librarian', array(
      'read' => true,
      'edit_posts' => true,
      'edit_pages' => true,
      'publish_pages' => true,
      'upload_files' => true,
      'delete_pages' => true,
      'delete_posts' => true,
    ));
  }

  public function filter_editable_roles($roles) {
    return array_intersect_key($roles, array(
      'student'       => true,
      'librarian'     => true,
      'administrator' => true,
    ));
  }

  public function hide_admin_menus_for_student() {
  if ($this->is_student_user() || $this->is_librarian_user()) {
    remove_menu_page('index.php'); // Dashboard
  }

  if ($this->is_student_user()) {
    remove_menu_page('h5p');
    remove_menu_page('upload.php');      // Media
    remove_menu_page('edit.php?post_type=page'); // Tutorials/Pages
    remove_menu_page('pbsg-analytics');  // Tutorial Analytics, if registered
  }
}

public function register_my_tutorials_menu() {
  if (!$this->is_student_user() && !$this->is_librarian_user()) {
    return;
  }

  add_menu_page(
    'My Tutorials',
    'My Tutorials',
    'read',
    'pbsg-my-tutorials',
    [$this, 'render_my_tutorials_admin_page'],
    'dashicons-welcome-learn-more',
    3
  );
}

public function render_my_tutorials_admin_page() {
  $query = new WP_Query([
    'post_type'      => 'page',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'meta_key'       => '_wp_page_template',
    'meta_value'     => self::TEMPLATE_SLUG,
    'orderby'        => 'title',
    'order'          => 'ASC',
  ]);

  $tutorials = [];

  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();

      $page_id   = get_the_ID();
      $title     = get_the_title($page_id);
      $link      = get_permalink($page_id);
      $cover_url = get_post_meta($page_id, self::META_COVER_URL, true);

      if (!$cover_url) {
        $cover_url = plugin_dir_url(__FILE__) . 'assets/default-course-cover.jpg';
      }

      $tutorials[] = [
        'id'        => $page_id,
        'title'     => $title,
        'link'      => $link,
        'cover'     => $cover_url,
        'edit_link' => current_user_can('edit_page', $page_id) ? get_edit_post_link($page_id, '') : '',
      ];
    }
    wp_reset_postdata();
  }

  $template = plugin_dir_path(__FILE__) . 'templates/admin-my-tutorials.php';

  if (file_exists($template)) {
    include $template;
  } else {
    echo '<div class="wrap"><h1>My Tutorials</h1><p>Template file not found: <code>templates/admin-my-tutorials.php</code></p></div>';
  }
}

public function redirect_after_login($redirect_to, $request, $user) {
  if (!is_object($user) || empty($user->roles)) {
    return $redirect_to;
  }

  $roles = (array) $user->roles;

  if (in_array('administrator', $roles, true)) {
    return admin_url('index.php?page=pb_home_page');
  }

  if (in_array('librarian', $roles, true) || in_array('student', $roles, true)) {
    return admin_url('admin.php?page=pbsg-my-tutorials');
  }

  return $redirect_to;
}

  public function pbsg_hide_editor_for_splitguide() {
    global $post;

    if (!$post) return;

    $template = get_post_meta($post->ID, '_wp_page_template', true);

    if ($template === self::TEMPLATE_SLUG) {
        remove_post_type_support('page', 'editor');
    }
  }

  public function output_role_ui_css() {
    if (!$this->is_student_user() && !$this->is_librarian_user() && !$this->is_admin_user()) {
      return;
    }
    ?>
    <style>
      a[href*="clone"],
      .pressbooks-clone-book,
      .clone-book,
      .pressbooks-create-book,
      .create-book,
      a[href*="site-new.php"],
      a[href*="book-new"],
      a[href*="book_create"],
      a[href*="create-book"],
      .page-title-action[href*="post-new.php?post_type=book"] {
        display: none !important;
      }
      <?php if ($this->is_student_user() || $this->is_librarian_user()) : ?>
        #adminmenu #menu-dashboard,
        #adminmenu a[href="index.php"],
        #adminmenu a[href="index.php?page=pb_home_page"] {
          display: none !important;
        }
<?php endif; ?>
    </style>
    <?php
  }

  public function output_role_ui_js() {
    if (!$this->is_student_user() && !$this->is_librarian_user() && !$this->is_admin_user()) {
      return;
    }

    $role = $this->is_student_user() ? 'student' : ($this->is_librarian_user() ? 'librarian' : 'administrator');
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      const role = <?php echo wp_json_encode($role); ?>;

      const normalize = (text) => (text || '').replace(/\s+/g, ' ').trim();

      const renameMenuLabel = (menuId, newText) => {
        document.querySelectorAll('#adminmenu #' + menuId + ' .wp-menu-name').forEach((el) => {
          el.textContent = newText;
        });
      };

      const renameSubmenuLabel = (menuId, hrefPart, newText) => {
        document.querySelectorAll('#adminmenu #' + menuId + ' .wp-submenu a').forEach((el) => {
          const href = el.getAttribute('href') || '';
          if (href.indexOf(hrefPart) !== -1) {
            el.textContent = newText;
          }
        });
      };

      const hideMenuLiByExactLabel = (label) => {
        document.querySelectorAll('#adminmenu li').forEach((li) => {
          const menuName = li.querySelector('.wp-menu-name');
          const txt = normalize(menuName ? menuName.textContent : li.textContent);
          if (txt === label) {
            li.style.display = 'none';
          }
        });
      };

      const hideSubmenuByText = (label) => {
        document.querySelectorAll('#adminmenu .wp-submenu li').forEach((li) => {
          const txt = normalize(li.textContent);
          if (txt === label) {
            li.style.display = 'none';
          }
        });
      };

      const renameTopBarLabel = (fromText, toText) => {
        document.querySelectorAll('#wpadminbar a, #wpadminbar .ab-item').forEach((el) => {
          const txt = normalize(el.textContent);
          if (txt === fromText) {
            el.textContent = toText;
          }
        });
      };

      const hideTopButtons = () => {
        document.querySelectorAll('a, button').forEach((el) => {
          const txt = normalize(el.textContent);
          if (txt === 'Create Book' || txt === 'Clone Book') {
            el.style.display = 'none';
            const wrapper = el.closest('.wrap, .button, .button-primary, .button-secondary, li');
            if (wrapper && /Create Book|Clone Book/.test(normalize(wrapper.textContent))) {
              wrapper.style.display = 'none';
            }
          }
        });
      };

      const renameHeadingAndButtons = () => {
        document.querySelectorAll('h1.wp-heading-inline').forEach((el) => {
          if (normalize(el.textContent) === 'Pages') {
            el.textContent = 'Tutorials';
          }
        });

        document.querySelectorAll('.page-title-action').forEach((el) => {
          if (normalize(el.textContent) === 'Add Page') {
            el.textContent = 'Add Tutorial';
          }
        });

        document.querySelectorAll('#adminmenu #menu-pages .wp-submenu a').forEach((el) => {
          const txt = normalize(el.textContent);
          if (txt === 'All Pages') {
            el.textContent = 'All Tutorials';
          } else if (txt === 'Add Page') {
            el.textContent = 'Add Tutorial';
          }
        });
      };

      hideTopButtons();
      renameHeadingAndButtons();

      if (role === 'student') {
        renameTopBarLabel('My Books', 'My Tutorials');

        hideMenuLiByExactLabel('Dashboard');
        hideSubmenuByText('Home');
        hideSubmenuByText('My Books');
        hideSubmenuByText('My Catalogue');

        hideMenuLiByExactLabel('Books');
        hideMenuLiByExactLabel('Pages');
        hideMenuLiByExactLabel('Tools');
        hideMenuLiByExactLabel('Media');
        hideMenuLiByExactLabel('H5P Content');
        hideMenuLiByExactLabel('Tutorial Analytics');
      }

      if (role === 'librarian') {
        renameTopBarLabel('My Books', 'My Tutorials');

        hideMenuLiByExactLabel('Dashboard');
        hideSubmenuByText('Home');
        hideSubmenuByText('My Books');
        hideSubmenuByText('My Catalogue');

        renameMenuLabel('menu-pages', 'Tutorials');
        renameSubmenuLabel('menu-pages', 'edit.php?post_type=page', 'All Tutorials');
        renameSubmenuLabel('menu-pages', 'post-new.php?post_type=page', 'Add Tutorial');

        hideMenuLiByExactLabel('Tools');
      }

      if (role === 'administrator') {
        renameTopBarLabel('My Books', 'My Site');
        renameMenuLabel('menu-pages', 'Tutorials');
        renameSubmenuLabel('menu-pages', 'edit.php?post_type=page', 'All Tutorials');
        renameSubmenuLabel('menu-pages', 'post-new.php?post_type=page', 'Add Tutorial');
        hideMenuLiByExactLabel('Books');
        hideMenuLiByExactLabel('Integrations');
      }
    });
    </script>
    <?php
  }
}

register_activation_hook(__FILE__, array('PBSG_Analytics', 'create_tables'));

new PB_Split_Guide_Plugin();

function pbsg_activate_plugin() {
  PBSG_Analytics::create_tables();
  PB_Split_Guide_Plugin::add_custom_roles();
}
register_activation_hook(__FILE__, 'pbsg_activate_plugin');

PBSG_Analytics::init();
PBSG_Analytics_Dashboard::init();
PBSG_Certificate::init();
