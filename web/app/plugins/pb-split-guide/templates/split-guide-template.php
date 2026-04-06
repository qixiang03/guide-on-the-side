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

// Enqueue assets directly in template — ensures they load on Multisite subsites
wp_enqueue_style(
    'pbsg_split_guide_css',
    plugin_dir_url( dirname( __FILE__ ) ) . 'assets/split-guide.css',
    array(),
    '0.4.0'
);

wp_enqueue_script(
  'pbsg-split-guide',
  plugin_dir_url( dirname( __FILE__ ) ) . 'assets/split-guide.js',
  array(),
  filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/split-guide.js' ),
  true
);

// Only load analytics tracker on published tutorials — prevents draft/preview pollution
if ( get_post_status() === 'publish' ) {
    wp_enqueue_script(
        'pbsg-tracker',
        plugin_dir_url( dirname( __FILE__ ) ) . 'assets/split-guide-tracker.js',
        array(),
        filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/split-guide-tracker.js' ),
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

  $tutorial = [
    'type' => $tutorial_type,
    'url'  => $tutorial_url,
    'file_url' => '',
    'mime' => ''
  ];

  if ($tutorial_type === 'file' && $tutorial_attachment_id > 0) {
    $tutorial['file_url'] = wp_get_attachment_url($tutorial_attachment_id);
    $tutorial['mime'] = get_post_mime_type($tutorial_attachment_id);
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

      if ($q_tutorial_type === 'file' && $q_tutorial_attachment_id > 0) {
        $q_tutorial_file_url = wp_get_attachment_url($q_tutorial_attachment_id);
        $q_tutorial_mime = get_post_mime_type($q_tutorial_attachment_id);
      }

      $q['tutorial_type'] = $q_tutorial_type;
      $q['tutorial_url'] = $q_tutorial_url;
      $q['tutorial_attachment_id'] = $q_tutorial_attachment_id;
      $q['tutorial_file_name'] = $q_tutorial_file_name;
      $q['tutorial_file_url'] = $q_tutorial_file_url;
      $q['tutorial_mime'] = $q_tutorial_mime;

      $branch_questions[] = $q;
    }
  }

  $branch = [
    'mode' => !empty($raw_branch['mode']) ? $raw_branch['mode'] : 'optional',
    'resource_mode' => !empty($raw_branch['resource_mode']) ? $raw_branch['resource_mode'] : 'main',
    'trigger_attempts' => 1,
    'questions' => $branch_questions,
    'tutorial_type' => !empty($raw_branch['tutorial_type']) ? $raw_branch['tutorial_type'] : '',
    'tutorial_url' => !empty($raw_branch['tutorial_url']) ? $raw_branch['tutorial_url'] : '',
    'tutorial_attachment_id' => !empty($raw_branch['tutorial_attachment_id']) ? absint($raw_branch['tutorial_attachment_id']) : 0,
    'tutorial_file_name' => !empty($raw_branch['tutorial_file_name']) ? $raw_branch['tutorial_file_name'] : '',
    'tutorial_file_url' => !empty($raw_branch['tutorial_file_url']) ? $raw_branch['tutorial_file_url'] : '',
    'tutorial_mime' => '',
  ];

  if ($branch['tutorial_type'] === 'file' && $branch['tutorial_attachment_id'] > 0) {
    $branch['tutorial_file_url'] = wp_get_attachment_url($branch['tutorial_attachment_id']);
    $branch['tutorial_mime'] = get_post_mime_type($branch['tutorial_attachment_id']);
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
        <div class="pbsg-intro-card pbsg-intro-card--structured">

          <?php if ($cover_image_url): ?>
            <div class="pbsg-intro-cover">
              <img src="<?php echo esc_url($cover_image_url); ?>" alt="" />
            </div>
          <?php endif; ?>

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
                &#x23F1; <?php echo esc_html($intro_duration); ?>
              </span>
            <?php endif; ?>

            <?php if ($step_count > 0): ?>
              <span class="pbsg-intro-steps-count">
                &#x1F4CB; <?php echo $step_count; ?> Page<?php echo $step_count !== 1 ? 's' : ''; ?>
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
  <div class="pbsg-quiz-header-left">
    <!-- Menu button -->
    <div class="pbsg-menu-wrap">
      <button type="button" class="pbsg-menu-btn" id="pbsgMenuBtn" aria-haspopup="true" aria-expanded="false">
        <span class="pbsg-menu-icon">☰</span>
        <span class="pbsg-menu-arrow">▾</span>
        <span class="pbsg-menu-text">Menu</span>
      </button>

      <!-- Dropdown -->
      <div class="pbsg-menu-dropdown" id="pbsgMenuDropdown" role="menu" aria-label="Steps menu">
        <div class="pbsg-menu-list">
          <?php foreach ($steps_enriched as $idx => $step): ?>
            
              <button
                type="button"
                class="pbsg-menu-item"
                data-step-index="<?php echo esc_attr($idx); ?>"
                role="menuitem"
              >
                <?php
                  $num = $idx + 1;
                  $label = !empty($step['title']) ? $step['title'] : "Page $num";
                  echo esc_html($num . '. ' . $label);
                ?>
              </button>
            
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Current step title -->
    <div id="pbsgStepTitle" class="pbsg-step-title"></div>
  </div>

  <button type="button" class="pbsg-focus-btn" id="pbsgFocusQuiz">Focus Quiz</button>
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
        <button type="button" class="pbsg-btn-outline pbsg-nav-btn" id="pbsgPrev">Prev</button>

        <div class="pbsg-nav-center">
          <span id="pbsgProgress" class="pbsg-progress"></span>
          <span id="pbsgRunningScore" class="pbsg-running-score" aria-live="polite">Correct/Attempted 0/0 ✓</span>
        </div>

        <button type="button" class="pbsg-btn-outline pbsg-nav-btn" id="pbsgNext">Next</button>
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
        <a class="pbsg-open-btn" id="pbsgOpenLink" href="#" target="_blank">Open in new window ↗</a>
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





<script>
window.PBSG_CERT = {
  ajaxUrl: <?php echo wp_json_encode($ajax_url); ?>,
  tutorialId: <?php echo (int)$page_id; ?>,
  nonce: <?php echo wp_json_encode($cert_nonce); ?>,
  isLoggedIn: <?php echo $is_logged_in ? 'true' : 'false'; ?>,
};
</script>


<script>
  const steps = <?php echo wp_json_encode($steps_enriched); ?>;
  const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
</script>

</div> <!-- /.pbsg-main-content -->


<div id="pbsgSummaryScreen" class="pbsg-summary-screen" style="display:none;">
  <div class="pbsg-summary-card">

    <h2 class="pbsg-summary-title">Tutorial Summary</h2>

    <div class="pbsg-summary-message">
      <p>You have completed this tutorial.</p>
    </div>

    <div id="pbsgAttemptSummary" class="pbsg-attempt-summary"></div>

    <div id="pbsgFinalGrade" class="pbsg-final-grade"></div>

    <?php if ($is_logged_in): ?>
      <div class="pbsg-summary-actions">
        <button type="button" class="pbsg-btn-outline" id="pbsgSummaryCertDownload">
          Generate Certificate
        </button>

        <button type="button" class="pbsg-btn-outline" id="pbsgRetakeTutorial">
          Close Tutorial
        </button>
      </div>
    <?php else: ?>
      <div class="pbsg-summary-actions">
        <p>Please log in to generate your certificate.</p>
        <button type="button" class="button" id="pbsgRetakeTutorial">
          Close Tutorial
        </button>
      </div>
    <?php endif; ?>

  </div>
</div>

<div id="pbsgCertModal" class="pbsg-cert-modal" style="display:none;" aria-hidden="true">
  <div class="pbsg-cert-modal-backdrop"></div>

  <div class="pbsg-cert-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pbsgCertModalTitle">
    <button type="button" class="pbsg-cert-modal-close" id="pbsgCertModalClose" aria-label="Close">×</button>

    <h3 id="pbsgCertModalTitle">Generate Certificate</h3>
    <p>Please enter your name as it should appear on the certificate.</p>

    <label for="pbsgCertModalName" class="pbsg-cert-label">Student Name</label>
    <input id="pbsgCertModalName" type="text" class="pbsg-cert-input" placeholder="Enter your full name" />

    <div id="pbsgCertModalError" class="pbsg-cert-error" style="display:none;"></div>

    <div class="pbsg-cert-modal-actions">
      <button type="button" class="pbsg-btn-outline" id="pbsgCertModalCancel">Cancel</button>
      <button type="button" class="pbsg-btn-outline" id="pbsgCertModalGenerate">Generate</button>
    </div>
  </div>
</div>

<?php endif; ?>
</div>

<?php get_footer(); ?>