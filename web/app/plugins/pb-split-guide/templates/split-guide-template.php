<?php
if (!defined('ABSPATH')) exit;

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

wp_enqueue_script(
    'pbsg-tracker',
    plugin_dir_url( dirname( __FILE__ ) ) . 'assets/split-guide-tracker.js',
    array(),
    filemtime( plugin_dir_path( dirname( __FILE__ ) ) . 'assets/split-guide-tracker.js' ),
    true
);

get_header();

$page_id = get_the_ID();

$steps_json = get_post_meta($page_id, '_pbsg_steps_json', true);
$steps = json_decode($steps_json, true);
if (!is_array($steps)) $steps = [];

$note  = get_post_meta($page_id, '_pbsg_header_note', true);
$title = get_the_title($page_id);

$ajax_url = admin_url('admin-ajax.php');

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
  $steps_enriched[] = $s;
}

  $is_logged_in = is_user_logged_in();
  $cert_nonce = wp_create_nonce(PBSG_Certificate::NONCE_ACTION);
?>


<div class="pbsg-wrap">
  <div class="pbsg-header">
    <h1 class="pbsg-title"><?php echo esc_html($title); ?></h1>
  </div>

<?php if (empty($steps_enriched)): ?>
  <p>No steps configured.</p>
<?php else: ?>

<div class="pbsg-container">

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
        <ul class="pbsg-menu-list">
          <?php foreach ($steps_enriched as $idx => $step): ?>
            <li>
              <button
                type="button"
                class="pbsg-menu-item"
                data-step-index="<?php echo esc_attr($idx); ?>"
                role="menuitem"
              >
                <?php
                  $num = $idx + 1;
                  $label = !empty($step['title']) ? $step['title'] : "Step $num";
                  echo esc_html($num . '. ' . $label);
                ?>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

    <!-- Current step title -->
    <div id="pbsgStepTitle" class="pbsg-step-title"></div>
  </div>

  <button type="button" class="pbsg-focus-btn" id="pbsgFocusQuiz">Focus Quiz</button>
</div>

      <div class="pbsg-iframe-wrap">
        <iframe id="pbsgH5PFrame" class="pbsg-iframe"></iframe>
      </div>

      <div class="pbsg-nav">
        <button type="button" class="button" id="pbsgPrev">Prev</button>
        <span id="pbsgProgress"></span>
        <button type="button" class="button button-primary" id="pbsgNext">Next</button>
      </div>

    </div>
  </aside>

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

    <div class="pbsg-iframe-wrap">
      <iframe id="pbsgTutorialFrame" class="pbsg-iframe"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowfullscreen></iframe>
    </div>
    <div id="pbsgTutorialFallback" class="pbsg-fallback">
      <a id="pbsgFallbackLink" href="#" target="_blank">Open file in new tab</a>
    </div>

    <div class="pbsg-certificate" id="pbsgCertificate" style="display:none;">
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
    </div>

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

<?php endif; ?>
</div>

<?php get_footer(); ?>