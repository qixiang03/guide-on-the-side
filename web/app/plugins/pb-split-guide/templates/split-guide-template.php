<?php
if (!defined('ABSPATH')) exit;

get_header();

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

  </section>

</div>

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
const titleEl = document.getElementById('pbsgStepTitle');
const progressEl = document.getElementById('pbsgProgress');

let i = 0;

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
  progressEl.textContent = `${i+1} / ${steps.length}`;

  prevBtn.disabled = i===0;
  nextBtn.disabled = i===steps.length-1;
}

prevBtn.onclick = ()=>{ if(i>0){i--;render();} };
nextBtn.onclick = ()=>{ if(i<steps.length-1){i++;render();} };


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