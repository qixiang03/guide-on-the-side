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
    'pbsg-tracker',
    plugin_dir_url( dirname( __FILE__ ) ) . 'assets/split-guide-tracker.js',
    array(),
    '1.0.0',
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

  $is_logged_in = is_user_logged_in();
  $cert_nonce = wp_create_nonce(PBSG_Certificate::NONCE_ACTION);
}
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
        <div id="pbsgStepTitle" class="pbsg-step-title"></div>
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
(function () {

const steps = <?php echo wp_json_encode($steps_enriched); ?>;
const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;

const h5pFrame = document.getElementById('pbsgH5PFrame');
const tutFrame = document.getElementById('pbsgTutorialFrame');
const openLink = document.getElementById('pbsgOpenLink');
const fallback = document.getElementById('pbsgTutorialFallback');
const fallbackLink = document.getElementById('pbsgFallbackLink');

const prevBtn = document.getElementById('pbsgPrev');
const nextBtn = document.getElementById('pbsgNext');


// --------------------
// Gate NEXT by quiz correctness (H5P)
// --------------------
const passedSteps = new Set(); // remember which steps are already correct
let h5pObs = null;

function lockNext(locked){
  if (!nextBtn) return;
  nextBtn.disabled = !!locked;
  nextBtn.classList.toggle('pbsg-locked', !!locked);
}

// Heuristics to detect "correct" in H5P iframe document.
// Works across common H5P content types.
function isH5PCorrect(doc){
  if (!doc || !doc.body) return false;

  // 1) Look for text like "You got X out of Y"
  const txt = (doc.body.innerText || '').replace(/\s+/g,' ').trim();
  const m = txt.match(/You got\s+(\d+)\s+out of\s+(\d+)/i);
  if (m) {
    const got = Number(m[1]), total = Number(m[2]);
    if (Number.isFinite(got) && Number.isFinite(total) && total > 0) {
      return got === total;
    }
  }

  // 2) Some H5P types show "100%"
  if (/100%/i.test(txt)) return true;

  // 3) Common correct/incorrect classes
  if (doc.querySelector('.h5p-incorrect, .h5p-feedback-incorrect')) return false;
  if (doc.querySelector('.h5p-correct, .h5p-feedback-correct')) return true;

  return false;
}

function attachH5PWatcher(stepIndex){
  // If this step already passed, unlock
  if (passedSteps.has(stepIndex)) {
    lockNext(false);
    return;
  }

  // If no quiz iframe or no quiz in this step, don't gate
  if (!h5pFrame || !steps[stepIndex]?.h5p_id) {
    lockNext(false);
    return;
  }

  // Disconnect old observer
  if (h5pObs) {
    try { h5pObs.disconnect(); } catch(e) {}
    h5pObs = null;
  }

  const tryAttach = () => {
    let doc;
    try {
      doc = h5pFrame.contentDocument || h5pFrame.contentWindow.document;
    } catch (e) {
      // Cross-origin (shouldn't happen in your case) -> fail open
      lockNext(false);
      return true;
    }

    if (!doc || !doc.body) return false;

    const check = () => {
      if (isH5PCorrect(doc)) {
        passedSteps.add(stepIndex);
        lockNext(false);
      } else {
        lockNext(true);
      }
    };

    // initial check
    check();

    h5pObs = new MutationObserver(check);
    h5pObs.observe(doc.body, { childList: true, subtree: true, attributes: true });

    return true;
  };

  // H5P iframe loads async: retry a few times
  let tries = 0;
  const timer = setInterval(() => {
    tries++;
    if (tryAttach() || tries > 30) clearInterval(timer);
  }, 300);
}



const titleEl = document.getElementById('pbsgStepTitle');
const progressEl = document.getElementById('pbsgProgress');
const progressFillEl = document.getElementById('pbsgProgressFill');
const progressLabelEl = document.getElementById('pbsgProgressLabel');

const certBox = document.getElementById('pbsgCertificate');
const certNameInput = document.getElementById('pbsgCertName');
const certBtn = document.getElementById('pbsgCertDownload');
const certHint = document.getElementById('pbsgCertHint');

let certMarked = false;

let i = 0;

async function markCompletedOnce(){
  if (!window.PBSG_CERT?.isLoggedIn) return;
  if (certMarked) return;
  certMarked = true;

  const form = new FormData();
  form.append('action', 'pbsg_mark_completed');
  form.append('tutorial_id', String(window.PBSG_CERT.tutorialId));
  form.append('nonce', window.PBSG_CERT.nonce);

  try{
    const res = await fetch(window.PBSG_CERT.ajaxUrl, {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
    });
    const json = await res.json();
    if (!json?.success) {
      if (certHint) certHint.textContent = json?.data?.message || 'Unable to mark completed.';
      return;
    }
    if (certHint) certHint.textContent = 'Completion recorded. You can download your certificate.';
  } catch(e){
    if (certHint) certHint.textContent = 'Network error while saving completion.';
  }
}


function h5pUrl(id){
  const u = new URL(ajaxUrl, location.origin);
  u.searchParams.set('action','h5p_embed');
  u.searchParams.set('id',id);
  return u.toString();
}

function toEmbeddableUrl(rawUrl){
  if (!rawUrl) return '';

  let u;
  try { u = new URL(rawUrl); } catch (e) { return rawUrl; }

  const host = (u.hostname || '').replace(/^www\./, '').toLowerCase();

  // youtu.be/<id>
  if (host === 'youtu.be') {
    const id = u.pathname.replace(/^\//, '').split('/')[0];
    if (!id) return rawUrl;
    const embed = new URL(`https://www.youtube.com/embed/${id}`);
    const t = u.searchParams.get('t') || u.searchParams.get('start');
    if (t) embed.searchParams.set('start', String(t).replace(/s$/, ''));
    return embed.toString();
  }

  // youtube.com/watch?v=<id>
  if (host === 'youtube.com' || host === 'm.youtube.com' || host === 'music.youtube.com') {
    const isWatch = u.pathname === '/watch';
    const isEmbed = u.pathname.startsWith('/embed/');
    const isShorts = u.pathname.startsWith('/shorts/');

    if (isEmbed) return rawUrl;

    let id = '';
    if (isWatch) id = u.searchParams.get('v') || '';
    if (isShorts) id = u.pathname.split('/')[2] || '';
    if (!id) return rawUrl;

    const embed = new URL(`https://www.youtube.com/embed/${id}`);

    const t = u.searchParams.get('t') || u.searchParams.get('start');
    if (t) embed.searchParams.set('start', String(t).replace(/s$/, ''));

    const list = u.searchParams.get('list');
    if (list) embed.searchParams.set('list', list);

    return embed.toString();
  }

  return rawUrl;
}

function renderTutorial(step){
  const t = step.tutorial;

  if (t.type === 'file' && t.file_url){
    if ((t.mime || '').includes('pdf')){
      tutFrame.src = t.file_url;
      fallback.style.display='none';
    } else {
      fallback.style.display='block';
      fallbackLink.href = t.file_url;
      tutFrame.src='';
    }
    openLink.href = t.file_url;
    return;
  }

  if (t.url){
    tutFrame.src = toEmbeddableUrl(t.url); 
    openLink.href = t.url;
    fallback.style.display='none';
  } else {
    tutFrame.src='';
  }
}

function render(){
  const step = steps[i];
  if (!step) return;

  if (step.h5p_id) h5pFrame.src = h5pUrl(step.h5p_id);
  else h5pFrame.src='';

  renderTutorial(step);

  titleEl.textContent = step.title || `Step ${i+1}`;
  // Inline (left pane) progress
  progressEl.textContent = `Page: ${i+1} of ${steps.length}`;

  // Bottom progress bar
  const pct = steps.length ? ((i + 1) / steps.length) * 100 : 0;
  if (progressFillEl) progressFillEl.style.width = pct.toFixed(2) + '%';
  if (progressLabelEl) progressLabelEl.textContent = `Page: ${i+1} of ${steps.length}`;

  prevBtn.disabled = i === 0;

  // Default rule: last step has no NEXT
  if (i === steps.length - 1) {
    lockNext(true);
  } else {
    // Gate NEXT only if this step has a quiz (h5p_id)
    if (step.h5p_id) {
      lockNext(true);          // locked until correct
      attachH5PWatcher(i);     // unlock when correct
    } else {
      lockNext(false);         // no quiz -> allow next
    }
  }


  // Certificate: show only on final step
  if (certBox) {
    if (i === steps.length - 1) {
      certBox.style.display = 'block';
      markCompletedOnce();
    } else {
      certBox.style.display = 'none';
    }
  }
}

prevBtn.onclick = ()=>{ if(i>0){i--;render();} };
nextBtn.onclick = ()=>{ if(i<steps.length-1){i++;render();} };

if (certBtn) {
  certBtn.onclick = () => {
    const name = (certNameInput?.value || '').trim();

    const u = new URL(window.PBSG_CERT.ajaxUrl, location.origin);
    u.searchParams.set('action', 'pbsg_download_certificate');
    u.searchParams.set('tutorial_id', String(window.PBSG_CERT.tutorialId));
    u.searchParams.set('nonce', window.PBSG_CERT.nonce);
    if (name) u.searchParams.set('name', name);

    // Navigate to trigger browser download
    window.location.href = u.toString();
  };
}

// ===== Focus System =====
const focusTutBtn = document.getElementById('pbsgFocusTutorial');
const focusQuizBtn = document.getElementById('pbsgFocusQuiz');

function clearFocus(){
  document.body.classList.remove('pbsg-focus-tutorial','pbsg-focus-quiz');
  focusTutBtn.textContent='Focus Tutorial';
  focusQuizBtn.textContent='Focus Quiz';
}

function toggleFocus(mode){
  const cls = mode==='tutorial'?'pbsg-focus-tutorial':'pbsg-focus-quiz';
  if(document.body.classList.contains(cls)){ clearFocus(); }
  else{
    clearFocus();
    document.body.classList.add(cls);
    if(mode==='tutorial') focusTutBtn.textContent='Exit Focus';
    else focusQuizBtn.textContent='Exit Focus';
  }
}

focusTutBtn.onclick = ()=>toggleFocus('tutorial');
focusQuizBtn.onclick = ()=>toggleFocus('quiz');

document.addEventListener('keydown',e=>{
  if(e.key==='Escape') clearFocus();
});

render();

})();
</script>

<?php endif; ?>
</div>

<?php get_footer(); ?>