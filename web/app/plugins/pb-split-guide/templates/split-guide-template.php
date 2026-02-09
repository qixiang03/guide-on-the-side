<?php
if (!defined('ABSPATH')) exit;

get_header();

$page_id = get_the_ID();

$steps_json = get_post_meta($page_id, '_pbsg_steps_json', true);
$steps = json_decode($steps_json, true);
if (!is_array($steps)) $steps = [];

$note  = get_post_meta($page_id, '_pbsg_header_note', true);
$title = get_the_title($page_id);

$ajax_url = admin_url('admin-ajax.php');
?>

<div class="pbsg-wrap">
  <div class="pbsg-header">
    <h1 class="pbsg-title"><?php echo esc_html($title); ?></h1>
  </div>

  <?php if (empty($steps)): ?>
    <p>No steps configured. Edit this page and add steps in “Split Guide Settings”.</p>
  <?php else: ?>
    <div class="pbsg-container" role="main">

      <aside class="pbsg-left" aria-label="Quiz panel">
        <div class="pbsg-left-inner">
          <div class="pbsg-step-title" id="pbsgStepTitle"></div>

          <div class="pbsg-iframe-wrap">
            <iframe id="pbsgH5PFrame" class="pbsg-iframe" title="Quiz"></iframe>
          </div>

          <div class="pbsg-nav">
            <button type="button" class="button" id="pbsgPrev">Prev</button>
            <span id="pbsgProgress" class="pbsg-progress"></span>
            <button type="button" class="button button-primary" id="pbsgNext">Next</button>
          </div>
        </div>
      </aside>

      <section class="pbsg-right" aria-label="Tutorial panel">
        <div class="pbsg-banner">
          <div class="pbsg-banner-text">
            <?php echo esc_html($note ? $note : 'If the webpage is not displaying below'); ?>
            <br/>
            <a id="pbsgUrlText" class="pbsg-url" href="#" target="_blank" rel="noopener noreferrer"></a>
          </div>

          <a class="pbsg-open-btn" id="pbsgOpenLink" href="#" target="_blank" rel="noopener noreferrer">
            Open in a new window ↗
          </a>
        </div>

        <div class="pbsg-iframe-wrap">
          <iframe id="pbsgTutorialFrame" class="pbsg-iframe" title="Tutorial content"></iframe>
        </div>
      </section>

    </div>

    <script>
      window.PBSG_STEPS = <?php echo wp_json_encode($steps); ?>;
      window.PBSG_AJAX_URL = <?php echo wp_json_encode($ajax_url); ?>;
    </script>

    <script src="<?php echo esc_url( site_url('/app/plugins/h5p/h5p-php-library/js/h5p-resizer.js') ); ?>" charset="UTF-8"></script>

    <script>
    (function () {
      const steps = window.PBSG_STEPS || [];
      const ajaxUrl = window.PBSG_AJAX_URL || '';

      const h5pFrame = document.getElementById('pbsgH5PFrame');
      const tutFrame = document.getElementById('pbsgTutorialFrame');
      const openLink = document.getElementById('pbsgOpenLink');
      const urlText  = document.getElementById('pbsgUrlText');
      const prevBtn = document.getElementById('pbsgPrev');
      const nextBtn = document.getElementById('pbsgNext');
      const titleEl = document.getElementById('pbsgStepTitle');
      const progressEl = document.getElementById('pbsgProgress');

      if (!h5pFrame || !tutFrame || steps.length === 0) return;

      let i = 0;
      const m = (location.hash || '').match(/step=(\d+)/);
      if (m) {
        const idx = parseInt(m[1], 10) - 1;
        if (!isNaN(idx) && idx >= 0 && idx < steps.length) i = idx;
      }

      function toYouTubeEmbed(url) {
        if (!url) return url;
        try {
          const u = new URL(url);

          if (u.hostname.includes('youtube.com') && u.pathname === '/watch' && u.searchParams.get('v')) {
            const id = u.searchParams.get('v');
            return `https://www.youtube-nocookie.com/embed/${id}`;
          }

          if (u.hostname === 'youtu.be') {
            const id = u.pathname.replace('/', '');
            return `https://www.youtube-nocookie.com/embed/${id}`;
          }
        } catch (e) {}
        return url;
      }

      function h5pEmbedUrl(h5pId) {
        const url = new URL(ajaxUrl, window.location.origin);
        url.searchParams.set('action', 'h5p_embed');
        url.searchParams.set('id', String(h5pId));
        return url.toString();
      }

      function render() {
        const step = steps[i];
        if (!step) return;

        if (step.h5p_id && step.h5p_id > 0) {
          h5pFrame.src = h5pEmbedUrl(step.h5p_id);
        } else {
          h5pFrame.removeAttribute('src');
        }

        const tutUrl = toYouTubeEmbed(step.url || '');
        if (tutUrl) {
          tutFrame.src = tutUrl;
          openLink.href = tutUrl;
          urlText.href = tutUrl;
          urlText.textContent = tutUrl;
        } else {
          tutFrame.removeAttribute('src');
          openLink.href = '#';
          urlText.href = '#';
          urlText.textContent = '';
        }

        titleEl.textContent = step.title ? step.title : `Step ${i + 1}`;
        progressEl.textContent = `${i + 1} / ${steps.length}`;

        prevBtn.disabled = (i === 0);
        nextBtn.disabled = (i === steps.length - 1);

        location.hash = `step=${i + 1}`;
      }

      prevBtn.addEventListener('click', function () {
        if (i > 0) { i--; render(); }
      });

      nextBtn.addEventListener('click', function () {
        if (i < steps.length - 1) { i++; render(); }
      });

      render();
    })();
    </script>
  <?php endif; ?>
</div>

<?php get_footer(); ?>
