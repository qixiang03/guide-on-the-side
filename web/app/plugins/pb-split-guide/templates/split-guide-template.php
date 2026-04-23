<?php
if (!defined('ABSPATH')) exit;

if (post_password_required() ) {
    echo get_the_password_form();
    return;
}


// Block access if tutorial is private
if ( get_post_status() === 'private' && !current_user_can('read_private_pages') ) {
    wp_die(
        '<h2>This tutorial is private.</h2><p>You do not have permission to access it.</p>',
        'Access Denied',
        ['response' => 403]
    );
}

// Defense in depth: these enqueues are also performed by
// PB_Split_Guide_Plugin::enqueue_assets() on the `wp_enqueue_scripts` hook.
// WordPress deduplicates by handle, so running both paths is safe. If either
// path fails (theme override of template_include, subsite without the plugin,
// filter ordering), the other ensures the left-panel JS/CSS still ships.
$__pbsg_plugin_url = plugin_dir_url( dirname( __FILE__ ) );
$__pbsg_plugin_dir = plugin_dir_path( dirname( __FILE__ ) );
$__pbsg_ver = static function ( string $rel ) use ( $__pbsg_plugin_dir ): string {
    $mtime = @filemtime( $__pbsg_plugin_dir . $rel );
    if ( $mtime !== false && $mtime > 0 ) {
        return (string) $mtime;
    }
    return defined( 'PB_Split_Guide_Plugin::VERSION' ) ? PB_Split_Guide_Plugin::VERSION : '0.5.1';
};

wp_enqueue_style(
    'pbsg_split_guide_css',
    $__pbsg_plugin_url . 'assets/split-guide.css',
    array(),
    $__pbsg_ver( 'assets/split-guide.css' )
);

// Icon set — must load before split-guide.js so PBSG_ICONS.render() is available.
wp_enqueue_script(
  'pbsg_icons_js',
  $__pbsg_plugin_url . 'assets/pbsg-icons.js',
  array(),
  $__pbsg_ver( 'assets/pbsg-icons.js' ),
  true
);

wp_enqueue_script(
  'pbsg-split-guide',
  $__pbsg_plugin_url . 'assets/split-guide.js',
  array( 'pbsg_icons_js' ),
  $__pbsg_ver( 'assets/split-guide.js' ),
  true
);

// Only load analytics tracker on published tutorials — prevents draft/preview pollution
if ( get_post_status() === 'publish' ) {
    wp_enqueue_script(
        'pbsg-tracker',
        $__pbsg_plugin_url . 'assets/split-guide-tracker.js',
        array(),
        $__pbsg_ver( 'assets/split-guide-tracker.js' ),
        true
    );
}

get_header();

$page_id = get_the_ID();

$steps_json = get_post_meta($page_id, '_pbsg_steps_json', true);
$steps = json_decode($steps_json, true);
if (!is_array($steps)) $steps = [];

$note  = get_post_meta($page_id, '_pbsg_header_note', true);
$title = get_the_title($page_id);


$intro_raw = get_post_field('post_content', $page_id);
$intro_html = apply_filters('the_content', $intro_raw);
$has_intro_legacy = trim(wp_strip_all_tags($intro_raw)) !== '';

// Structured intro fields (Phase 7)
$intro_description  = get_post_meta($page_id, '_pbsg_intro_description', true);
$intro_obj_raw      = get_post_meta($page_id, '_pbsg_intro_objectives', true);
$intro_objectives   = is_string($intro_obj_raw) ? json_decode($intro_obj_raw, true) : [];
if (!is_array($intro_objectives)) $intro_objectives = [];
$intro_objectives   = array_filter($intro_objectives);
$intro_duration     = get_post_meta($page_id, '_pbsg_intro_duration', true);
$intro_prerequisites = get_post_meta($page_id, '_pbsg_intro_prerequisites', true);
$cover_image_id     = (int) get_post_meta($page_id, '_pbsg_cover_image_id', true);
$cover_image_url    = $cover_image_id ? wp_get_attachment_image_url($cover_image_id, 'large') : '';

$has_structured_intro = !empty($intro_description) || !empty($intro_objectives);
$has_intro = $has_structured_intro || $has_intro_legacy;
$step_count = is_array($steps) ? count($steps) : 0;





$ajax_url = admin_url('admin-ajax.php');

// Resolve effective layout ratio (Stretch Goal 5a)
$site_default_ratio = (int) get_option(PB_Split_Guide_Plugin::OPTION_DEFAULT_RATIO, PB_Split_Guide_Plugin::RATIO_DEFAULT);
$site_default_ratio = max(PB_Split_Guide_Plugin::RATIO_MIN, min(PB_Split_Guide_Plugin::RATIO_MAX, $site_default_ratio));
$per_guide_ratio    = get_post_meta($page_id, PB_Split_Guide_Plugin::META_LEFT_RATIO, true);
$left_ratio  = ($per_guide_ratio !== '' && $per_guide_ratio !== false)
               ? max(PB_Split_Guide_Plugin::RATIO_MIN, min(PB_Split_Guide_Plugin::RATIO_MAX, (int) $per_guide_ratio))
               : $site_default_ratio;
$right_ratio = 100 - $left_ratio;

// Check if user-resizable is enabled (Stretch Goal 5b)
$user_resizable = get_post_meta($page_id, PB_Split_Guide_Plugin::META_USER_RESIZABLE, true) === '1';

// Enrich tutorial data
$steps_enriched = [];
foreach ($steps as $s) {
  $s = is_array($s) ? $s : [];

  $tutorial_type = isset($s['tutorial_type']) ? $s['tutorial_type'] : '';
  $tutorial_url  = isset($s['tutorial_url']) ? $s['tutorial_url'] : '';
  $tutorial_attachment_id = isset($s['tutorial_attachment_id']) ? absint($s['tutorial_attachment_id']) : 0;

  if (!$tutorial_type && !empty($s['url'])) {
    $tutorial_type = 'url';
    $tutorial_url = $s['url'];
  }

  // View-time resolution: if save-time flags are absent (old tutorial,
  // edited before the embed-check feature shipped), run the cached check
  // so deny-listed hosts (libraryupei.ca etc.) get the popup fallback
  // without requiring a re-save.
  $__main_saved_embeddable = array_key_exists('embeddable', $s) ? (bool) $s['embeddable'] : null;
  $__main_saved_is_doc     = array_key_exists('is_document_url', $s) ? (bool) $s['is_document_url'] : null;
  $__main_resolved = ($tutorial_type === 'url' && !empty($tutorial_url))
    ? PBSG_Embed_Check::resolve_flags($tutorial_url, $__main_saved_embeddable, $__main_saved_is_doc)
    : ['embeddable' => $__main_saved_embeddable ?? true, 'is_document_url' => $__main_saved_is_doc ?? false];

  $tutorial = [
    'type' => $tutorial_type,
    'url'  => $tutorial_url,
    'file_url' => '',
    'mime' => '',
    'embeddable' => $__main_resolved['embeddable'],
    'is_document_url' => $__main_resolved['is_document_url'],
    'viewer_url' => '',
  ];

  if ($tutorial_type === 'file' && $tutorial_attachment_id > 0) {
    $tutorial['file_url'] = wp_get_attachment_url($tutorial_attachment_id);
    $tutorial['mime'] = get_post_mime_type($tutorial_attachment_id) ?: '';
    $tutorial['viewer_url'] = PBSG_Embed_Check::viewer_url($tutorial['file_url'] ?: '', $tutorial['mime']);
  }

  // For non-embeddable document URLs, generate Google Viewer URL
  if ($tutorial_type === 'url' && !empty($tutorial['url']) && !$tutorial['embeddable'] && $tutorial['is_document_url']) {
    $tutorial['viewer_url'] = 'https://docs.google.com/viewerng/viewer?url=' . rawurlencode($tutorial['url']) . '&embedded=true';
  }

  $s['tutorial'] = $tutorial;

  $branch = null;

if (!empty($s['branch']) && is_array($s['branch'])) {
  $raw_branch = $s['branch'];


  $branch_questions = [];

  if (!empty($raw_branch['questions']) && is_array($raw_branch['questions'])) {
    foreach ($raw_branch['questions'] as $q) {
      if (!is_array($q)) continue;

      $q_tutorial_type = !empty($q['tutorial_type']) ? $q['tutorial_type'] : '';
      $q_tutorial_url = !empty($q['tutorial_url']) ? $q['tutorial_url'] : '';
      $q_tutorial_attachment_id = !empty($q['tutorial_attachment_id']) ? absint($q['tutorial_attachment_id']) : 0;
      $q_tutorial_file_name = !empty($q['tutorial_file_name']) ? $q['tutorial_file_name'] : '';
      $q_tutorial_file_url = !empty($q['tutorial_file_url']) ? $q['tutorial_file_url'] : '';
      $q_tutorial_mime = '';
      $q_tutorial_viewer_url = '';
      // View-time resolution on branch questions (see main-step comment above):
      // saved meta from old tutorials lacks the embed flags, so the default
      // was `true` → Tier 1 iframe even for deny-listed hosts. Re-resolve here.
      $__q_saved_embed = array_key_exists('tutorial_embeddable', $q) ? (bool) $q['tutorial_embeddable'] : null;
      $__q_saved_doc   = array_key_exists('tutorial_is_document_url', $q) ? (bool) $q['tutorial_is_document_url'] : null;
      $__q_resolved    = ($q_tutorial_type === 'url' && !empty($q_tutorial_url))
        ? PBSG_Embed_Check::resolve_flags($q_tutorial_url, $__q_saved_embed, $__q_saved_doc)
        : ['embeddable' => $__q_saved_embed ?? true, 'is_document_url' => $__q_saved_doc ?? false];
      $q_tutorial_embeddable      = $__q_resolved['embeddable'];
      $q_tutorial_is_document_url = $__q_resolved['is_document_url'];

      if ($q_tutorial_type === 'file' && $q_tutorial_attachment_id > 0) {
        $q_tutorial_file_url = wp_get_attachment_url($q_tutorial_attachment_id);
        $q_tutorial_mime = get_post_mime_type($q_tutorial_attachment_id);
        $q_tutorial_viewer_url = PBSG_Embed_Check::viewer_url($q_tutorial_file_url ?: '', $q_tutorial_mime ?: '');
      }

      // For non-embeddable document URLs on branch questions, resolve Google Viewer fallback.
      if ($q_tutorial_type === 'url' && !empty($q_tutorial_url) && !$q_tutorial_embeddable && $q_tutorial_is_document_url) {
        $q_tutorial_viewer_url = 'https://docs.google.com/viewerng/viewer?url=' . rawurlencode($q_tutorial_url) . '&embedded=true';
      }

      $q['tutorial_type'] = $q_tutorial_type;
      $q['tutorial_url'] = $q_tutorial_url;
      $q['tutorial_attachment_id'] = $q_tutorial_attachment_id;
      $q['tutorial_file_name'] = $q_tutorial_file_name;
      $q['tutorial_file_url'] = $q_tutorial_file_url;
      $q['tutorial_mime'] = $q_tutorial_mime;
      $q['tutorial_embeddable']      = $q_tutorial_embeddable;
      $q['tutorial_is_document_url'] = $q_tutorial_is_document_url;
      $q['tutorial_viewer_url']      = $q_tutorial_viewer_url;

      $branch_questions[] = $q;
    }
  }

  // View-time resolution on branch-level tutorial (see main-step comment above).
  $__b_type       = !empty($raw_branch['tutorial_type']) ? $raw_branch['tutorial_type'] : '';
  $__b_url        = !empty($raw_branch['tutorial_url'])  ? $raw_branch['tutorial_url']  : '';
  $__b_saved_embed = array_key_exists('tutorial_embeddable', $raw_branch) ? (bool) $raw_branch['tutorial_embeddable'] : null;
  $__b_saved_doc   = array_key_exists('tutorial_is_document_url', $raw_branch) ? (bool) $raw_branch['tutorial_is_document_url'] : null;
  $__b_resolved    = ($__b_type === 'url' && !empty($__b_url))
    ? PBSG_Embed_Check::resolve_flags($__b_url, $__b_saved_embed, $__b_saved_doc)
    : ['embeddable' => $__b_saved_embed ?? true, 'is_document_url' => $__b_saved_doc ?? false];

  $branch = [
    'mode' => !empty($raw_branch['mode']) ? $raw_branch['mode'] : 'optional',
    'resource_mode' => !empty($raw_branch['resource_mode']) ? $raw_branch['resource_mode'] : 'main',
    'trigger_attempts' => 1,
    'questions' => $branch_questions,
    'tutorial_type' => $__b_type,
    'tutorial_url' => $__b_url,
    'tutorial_attachment_id' => !empty($raw_branch['tutorial_attachment_id']) ? absint($raw_branch['tutorial_attachment_id']) : 0,
    'tutorial_file_name' => !empty($raw_branch['tutorial_file_name']) ? $raw_branch['tutorial_file_name'] : '',
    'tutorial_file_url' => !empty($raw_branch['tutorial_file_url']) ? $raw_branch['tutorial_file_url'] : '',
    'tutorial_mime' => '',
    'tutorial_embeddable'      => $__b_resolved['embeddable'],
    'tutorial_is_document_url' => $__b_resolved['is_document_url'],
    'tutorial_viewer_url'      => '',
  ];

  if ($branch['tutorial_type'] === 'file' && $branch['tutorial_attachment_id'] > 0) {
    $branch['tutorial_file_url'] = wp_get_attachment_url($branch['tutorial_attachment_id']);
    $branch['tutorial_mime'] = get_post_mime_type($branch['tutorial_attachment_id']);
    $branch['tutorial_viewer_url'] = PBSG_Embed_Check::viewer_url($branch['tutorial_file_url'] ?: '', $branch['tutorial_mime'] ?: '');
  }

  // For non-embeddable document URLs on the branch-level tutorial, resolve Google Viewer fallback.
  if (
    $branch['tutorial_type'] === 'url' &&
    !empty($branch['tutorial_url']) &&
    !$branch['tutorial_embeddable'] &&
    $branch['tutorial_is_document_url']
  ) {
    $branch['tutorial_viewer_url'] = 'https://docs.google.com/viewerng/viewer?url=' . rawurlencode($branch['tutorial_url']) . '&embedded=true';
  }

  if (
    empty($branch['questions']) &&
    empty($branch['tutorial_url']) &&
    empty($branch['tutorial_file_url'])
  ) {
    $branch = null;
  }
}

$s['branch'] = $branch;

  $steps_enriched[] = $s;
}

  $is_logged_in = is_user_logged_in();
  $cert_nonce = wp_create_nonce(PBSG_Certificate::NONCE_ACTION);
?>


<div class="pbsg-wrap">
  <div class="pbsg-header">
    <h1 class="pbsg-title"><?php echo esc_html($title); ?></h1>
  </div>

<?php if (empty($steps_enriched) && !$has_intro): ?>
  <p>No pages configured.</p>
<?php else: ?>

  <?php if ($has_intro): ?>
    <div id="pbsgIntroScreen" class="pbsg-intro-screen">
      <?php if ($has_structured_intro): ?>
        <div class="pbsg-intro-card pbsg-intro-card--structured<?php echo $cover_image_url ? '' : ' pbsg-intro-card--no-cover'; ?>">

          <?php if ($cover_image_url): ?>
            <div class="pbsg-intro-cover">
              <img src="<?php echo esc_url($cover_image_url); ?>" alt="" />
            </div>
          <?php endif; ?>

          <div class="pbsg-intro-info">
            <div class="pbsg-intro-eyebrow">Tutorial</div>
            <h2 class="pbsg-intro-title"><?php echo esc_html(get_the_title($page_id)); ?></h2>

            <?php if ($intro_description): ?>
              <p class="pbsg-intro-description"><?php echo esc_html($intro_description); ?></p>
            <?php endif; ?>

            <?php if (!empty($intro_objectives)): ?>
              <div class="pbsg-intro-objectives">
                <h3>What You'll Learn</h3>
                <ul>
                  <?php foreach ($intro_objectives as $obj): ?>
                    <li><?php echo esc_html($obj); ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div class="pbsg-intro-meta">
              <?php if ($intro_duration): ?>
                <span class="pbsg-intro-duration">
                  <?php echo pbsg_icon('stopwatch'); ?> <?php echo esc_html($intro_duration); ?>
                </span>
              <?php endif; ?>

              <?php if ($step_count > 0): ?>
                <span class="pbsg-intro-steps-count">
                  <?php echo pbsg_icon('clipboard'); ?> <?php echo $step_count; ?> Page<?php echo $step_count !== 1 ? 's' : ''; ?>
                </span>
              <?php endif; ?>
            </div>

            <?php if ($intro_prerequisites): ?>
              <div class="pbsg-intro-prereqs">
                <h4>Prerequisites</h4>
                <p><?php echo esc_html($intro_prerequisites); ?></p>
              </div>
            <?php endif; ?>

            <div class="pbsg-intro-actions">
              <button type="button" id="pbsgStartTutorial" class="pbsg-start-btn">
                Start Tutorial
              </button>
            </div>
          </div>

        </div>
      <?php else: ?>
        <div class="pbsg-intro-card">
          <div class="pbsg-intro-content">
            <?php echo $intro_html; ?>
          </div>

          <div class="pbsg-intro-actions">
            <button type="button" id="pbsgStartTutorial" class="pbsg-start-btn">
              Start Tutorial
            </button>
          </div>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

<noscript>
  <div class="pbsg-noscript-fallback" style="padding:1rem;border:1px solid #8C2004;background:#fff;margin:1rem;">
    <h2 style="margin-top:0"><?php esc_html_e('JavaScript is required for the interactive tutorial', 'pb-split-guide'); ?></h2>
    <p><?php esc_html_e('Enable JavaScript in your browser to use the full tutorial. While JavaScript is off, you can still open each tutorial resource directly:', 'pb-split-guide'); ?></p>
    <ol>
      <?php foreach ($steps_enriched as $__idx => $__step): ?>
        <?php
          $__num   = $__idx + 1;
          $__label = !empty($__step['title']) ? $__step['title'] : sprintf(__('Step %d', 'pb-split-guide'), $__num);
          $__t     = isset($__step['tutorial']) && is_array($__step['tutorial']) ? $__step['tutorial'] : [];
          $__href  = '';
          if (!empty($__t['url'])) {
            $__href = $__t['url'];
          } elseif (!empty($__t['file_url'])) {
            $__href = $__t['file_url'];
          }
        ?>
        <li>
          <strong><?php echo esc_html($__label); ?></strong>
          <?php if ($__href): ?>
            — <a href="<?php echo esc_url($__href); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open resource in new tab', 'pb-split-guide'); ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</noscript>

<div
  class="pbsg-main-content"
  id="pbsgMainContent"
  <?php if ($has_intro) echo 'style="display:none;"'; ?>
>
<div class="pbsg-container" style="--pbsg-left-ratio:<?php echo esc_attr($left_ratio); ?>;--pbsg-right-ratio:<?php echo esc_attr($right_ratio); ?>">

  <!-- LEFT: QUIZ -->
  <aside class="pbsg-left">
    <div class="pbsg-left-inner">

      <div class="pbsg-quiz-header">

  <!-- Menu button + dropdown -->
  <div class="pbsg-menu-wrap">
    <button type="button" class="pbsg-menu-btn" id="pbsgMenuBtn" aria-haspopup="true" aria-expanded="false">
      <span class="pbsg-menu-icon"><?php echo pbsg_icon('menu'); ?></span>
      <span class="pbsg-menu-arrow"><?php echo pbsg_icon('chevron-down'); ?></span>
      <span class="pbsg-menu-label">Menu</span>
    </button>

    <!-- Dropdown -->
    <div class="pbsg-menu-dropdown" id="pbsgMenuDropdown" role="menu" aria-label="Steps menu">
      <div class="pbsg-menu-head">
        <span class="pbsg-menu-head-position">
          Steps &middot; <span class="pbsg-menu-head-current">1</span> of <span class="pbsg-menu-head-total"><?php echo (int) count($steps_enriched); ?></span>
        </span>
        <span class="pbsg-menu-head-done">
          <span class="pbsg-menu-head-done-count">0</span>
          <?php echo pbsg_icon('check', 'pbsg-icon--ok'); ?>
        </span>
      </div>
      <div class="pbsg-menu-list" id="pbsgMenuList">
        <?php foreach ($steps_enriched as $idx => $step): ?>
          <?php
            $num = $idx + 1;
            $label = !empty($step['title']) ? $step['title'] : "Step $num";
          ?>
          <button
            type="button"
            class="pbsg-menu-item"
            data-step-index="<?php echo esc_attr($idx); ?>"
            role="menuitem"
          >
            <span class="pbsg-menu-item-label">
              <span class="pbsg-menu-item-num"><?php echo esc_html($num . '.'); ?></span>
              <?php echo esc_html($label); ?>
            </span>
            <span class="pbsg-menu-item-check" aria-hidden="true">
              <?php echo pbsg_icon('check', 'pbsg-icon--ok'); ?>
            </span>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Title zone (eyebrow + title) -->
  <div class="pbsg-step-title-zone">
    <span class="pbsg-step-eyebrow" id="pbsgStepEyebrow" aria-hidden="true"></span>
    <span class="pbsg-step-title" id="pbsgStepTitle"></span>
  </div>

  <!-- Focus Quiz button -->
  <button type="button" class="pbsg-focus-btn" id="pbsgFocusQuiz" aria-label="Focus Quiz">
    <span class="pbsg-focus-icon"><?php echo pbsg_icon('maximize'); ?></span>
    <span class="pbsg-focus-label">Focus Quiz</span>
  </button>

</div>

      <div class="pbsg-iframe-wrap">
        <iframe aria-label="H5P Frame" id="pbsgH5PFrame" class="pbsg-iframe" tabindex="0"></iframe>
        <div id="pbsgBranchQuizHost" class="pbsg-branch-quiz-host" style="display:none;"></div>
      </div>

      <div id="pbsgLearnMoreWrap" class="pbsg-learn-more-wrap" style="display:none;">
        <button type="button" id="pbsgLearnMore" class="pbsg-learn-more-btn">
          Learn more about this
        </button>
      </div>

      <div class="pbsg-nav">
        <button type="button" class="pbsg-btn-outline pbsg-nav-btn" id="pbsgPrev"><?php esc_html_e('Prev', 'pb-split-guide'); ?></button>
        <div class="pbsg-nav-center">
          <span id="pbsgProgress" class="pbsg-progress">
            <span class="pbsg-progress-long"><?php esc_html_e('Page:', 'pb-split-guide'); ?> <span class="pbsg-progress-current">1</span> <?php esc_html_e('of', 'pb-split-guide'); ?> <span class="pbsg-progress-total">1</span></span>
            <span class="pbsg-progress-short"><span class="pbsg-progress-current">1</span>/<span class="pbsg-progress-total">1</span></span>
          </span>
          <span id="pbsgRunningScore" class="pbsg-running-score" aria-live="polite">
            <span class="pbsg-score-long"><?php esc_html_e('Correct/Attempted', 'pb-split-guide'); ?> <span class="pbsg-score-value">0/0</span> <?php echo pbsg_icon('check', 'pbsg-icon--ok'); ?></span>
            <span class="pbsg-score-short"><span class="pbsg-score-value">0/0</span> <?php echo pbsg_icon('check', 'pbsg-icon--ok'); ?></span>
          </span>
        </div>
        <button type="button" class="pbsg-btn-outline pbsg-nav-btn" id="pbsgNext"><?php esc_html_e('Next', 'pb-split-guide'); ?></button>
      </div>

    </div>
  </aside>

  <?php if ($user_resizable): ?>
  <!-- RESIZE HANDLE (Stretch Goal 5b) -->
  <div class="pbsg-resize-handle" id="pbsgResizeHandle"
       role="separator" aria-orientation="vertical"
       aria-label="Resize panel divider"
       aria-valuenow="<?php echo esc_attr($left_ratio); ?>"
       aria-valuemin="<?php echo esc_attr(PB_Split_Guide_Plugin::RATIO_MIN); ?>"
       aria-valuemax="<?php echo esc_attr(PB_Split_Guide_Plugin::RATIO_MAX); ?>"
       tabindex="0">
    <div class="pbsg-resize-grip"></div>
  </div>
  <?php endif; ?>

  <!-- RIGHT: TUTORIAL -->
  <section class="pbsg-right">

    <div class="pbsg-banner">
      <div class="pbsg-banner-text">
        <?php echo esc_html($note ? $note : 'If the webpage is not displaying below'); ?>
        <a class="pbsg-open-btn" id="pbsgOpenLink" href="#" target="_blank">Open in new tab <?php echo pbsg_icon('arrow-up-right'); ?></a>
      </div>
      <div class="pbsg-banner-actions">
        <button type="button" class="pbsg-focus-btn" id="pbsgFocusTutorial">Focus Tutorial</button>  
      </div>
    </div>

   <div class="pbsg-iframe-wrap" id="pbsgTutorialStage">
    <iframe aria-label="Tutorial Frame" id="pbsgTutorialFrame" class="pbsg-iframe"
      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
      allowfullscreen></iframe>
  </div>
    <div id="pbsgTutorialFallback" class="pbsg-fallback">
      <a id="pbsgFallbackLink" href="#" target="_blank">Open file in new tab</a>
    </div>

    <!-- <div class="pbsg-certificate" id="pbsgCertificate" style="display:none;">
      <?php if ($is_logged_in): ?>
        <div class="pbsg-certificate-inner">
          <div class="pbsg-certificate-title"><strong>Certificate</strong></div>
          <div class="pbsg-certificate-row">
            <input id="pbsgCertName" type="text" placeholder="Name on certificate (optional)" />
            <button type="button" class="button button-primary" id="pbsgCertDownload">
              Download Certificate (PDF)
            </button>
          </div>
          <div class="pbsg-certificate-hint" id="pbsgCertHint"></div>
        </div>
      <?php else: ?>
        <div class="pbsg-certificate-inner">
          <strong>Certificate</strong> — Please log in to download your certificate.
        </div>
      <?php endif; ?>
    </div> -->

  </section>

</div>

<!-- Bottom progress indicator (fixed) -->
<div class="pbsg-progressbar" role="status" aria-live="polite">
  <div class="pbsg-progressbar-inner">
    <div class="pbsg-progressbar-track" aria-hidden="true">
      <div id="pbsgProgressFill" class="pbsg-progressbar-fill"></div>
    </div>
    <div id="pbsgProgressLabel" class="pbsg-progressbar-label"></div>
  </div>
</div>



<?php
$close_tutorial_url = get_post_meta($page_id, PB_Split_Guide_Plugin::META_CLOSE_URL, true);
?>

<script>
window.PBSG_CERT = {
  ajaxUrl: <?php echo wp_json_encode($ajax_url); ?>,
  tutorialId: <?php echo (int)$page_id; ?>,
  nonce: <?php echo wp_json_encode($cert_nonce); ?>,
  isLoggedIn: <?php echo $is_logged_in ? 'true' : 'false'; ?>,
  closeTutorialUrl: <?php echo wp_json_encode($close_tutorial_url); ?>,
};

// Just-in-time embed-probe config consumed by split-guide.js to
// re-verify URL tutorials (both main-step and branch-step resources)
// immediately before setting iframe.src. Covers the failure class
// where save-time meta said embeddable=true but the upstream now
// refuses framing, returns non-2xx, or uses JS frame-busting.
window.PBSG_PROBE = {
  ajaxUrl: <?php echo wp_json_encode($ajax_url); ?>,
  nonce:   <?php echo wp_json_encode(wp_create_nonce('pbsg_probe_embed')); ?>,
  action:  'pbsg_probe_embed',
};
</script>


<script>
  const steps = <?php echo wp_json_encode($steps_enriched); ?>;
  const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
</script>

</div> <!-- /.pbsg-main-content -->


<div id="pbsgSummaryScreen" class="pbsg-summary-screen" style="display:none;">
  <div class="pbsg-summary-card<?php echo $cover_image_url ? ' pbsg-summary-card--structured' : ''; ?>">

    <?php if ($cover_image_url): ?>
      <div class="pbsg-summary-cover">
        <img src="<?php echo esc_url($cover_image_url); ?>" alt="" />
      </div>
    <?php endif; ?>

    <div class="pbsg-summary-info">

      <div class="pbsg-summary-eyebrow"><?php esc_html_e('Completed', 'pb-split-guide'); ?></div>
      <p class="pbsg-summary-desc" id="pbsgSummaryDesc">
        <?php
          /* translators: %d: number of steps */
          printf(
            esc_html__("You've completed all %d steps of this tutorial.", 'pb-split-guide'),
            (int) count($steps_enriched)
          );
        ?>
      </p>

      <div class="pbsg-objectives-wrap" id="pbsgObjectivesWrap" hidden>
        <div class="pbsg-objectives-head">
          <span><?php esc_html_e('Questions', 'pb-split-guide'); ?></span>
          <span class="pbsg-objectives-count">
            <span id="pbsgSummaryCorrect">0</span>
            /
            <span id="pbsgSummaryTotal">0</span>
            <?php esc_html_e('correct', 'pb-split-guide'); ?>
          </span>
        </div>
        <ul class="pbsg-objectives" id="pbsgSummaryQuestions" tabindex="0"></ul>
      </div>

      <div class="pbsg-summary-meta">
        <span class="pbsg-meta-item">
          <?php echo pbsg_icon('stopwatch', 'pbsg-meta-icon'); ?>
          <strong id="pbsgSummaryDuration">—</strong>
        </span>
        <span class="pbsg-meta-sep" data-pbsg-meta-sep hidden>·</span>
        <span class="pbsg-meta-item" id="pbsgSummaryCorrectItem" hidden>
          <?php echo pbsg_icon('check', 'pbsg-meta-icon'); ?>
          <strong><span id="pbsgSummaryCorrect2">0</span> / <span id="pbsgSummaryTotal2">0</span> <?php esc_html_e('correct', 'pb-split-guide'); ?></strong>
        </span>
        <span class="pbsg-meta-sep" data-pbsg-meta-sep hidden>·</span>
        <span class="pbsg-meta-item is-score" id="pbsgSummaryScoreItem" hidden>
          <?php echo pbsg_icon('chart-bar', 'pbsg-meta-icon'); ?>
          <strong id="pbsgSummaryScore">—</strong>
        </span>
      </div>

      <?php if ($is_logged_in): ?>
        <div class="pbsg-summary-actions">
          <button type="button" class="pbsg-btn-primary" id="pbsgSummaryCertDownload"><?php esc_html_e('Generate Certificate', 'pb-split-guide'); ?></button>
          <button type="button" class="pbsg-btn-outline" id="pbsgRetakeTutorial"><?php esc_html_e('Close Tutorial', 'pb-split-guide'); ?></button>
        </div>
      <?php else: ?>
        <div class="pbsg-summary-actions">
          <p><?php esc_html_e('Please log in to generate your certificate.', 'pb-split-guide'); ?></p>
          <button type="button" class="pbsg-btn-outline" id="pbsgRetakeTutorial"><?php esc_html_e('Close Tutorial', 'pb-split-guide'); ?></button>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<div id="pbsgCertModal" class="pbsg-cert-modal" style="display:none;" aria-hidden="true">
  <div class="pbsg-cert-modal-backdrop"></div>

  <div class="pbsg-cert-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pbsgCertModalTitle">
    <button type="button" class="pbsg-cert-modal-close" id="pbsgCertModalClose" aria-label="Close">×</button>

    <div class="pbsg-cert-modal-eyebrow"><?php esc_html_e('Certificate', 'pb-split-guide'); ?></div>
    <h3 id="pbsgCertModalTitle"><?php esc_html_e('Generate Certificate', 'pb-split-guide'); ?></h3>
    <p class="pbsg-cert-modal-desc"><?php esc_html_e('Please enter your name as it should appear on the certificate.', 'pb-split-guide'); ?></p>

    <label for="pbsgCertModalName" class="pbsg-cert-label">Student Name</label>
    <input id="pbsgCertModalName" type="text" class="pbsg-cert-input" placeholder="Enter your full name" />

    <div id="pbsgCertModalError" class="pbsg-cert-error" style="display:none;"></div>

    <div class="pbsg-cert-modal-actions">
      <button type="button" class="pbsg-btn-outline" id="pbsgCertModalCancel">Cancel</button>
      <button type="button" class="pbsg-btn-primary" id="pbsgCertModalGenerate">Generate</button>
    </div>
  </div>
</div>

<?php endif; ?>
</div>

<?php get_footer(); ?>