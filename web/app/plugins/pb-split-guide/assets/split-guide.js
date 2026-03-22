(function () {
  
const h5pFrame = document.getElementById('pbsgH5PFrame');
const tutFrame = document.getElementById('pbsgTutorialFrame');
const openLink = document.getElementById('pbsgOpenLink');
const fallback = document.getElementById('pbsgTutorialFallback');
const fallbackLink = document.getElementById('pbsgFallbackLink');

const prevBtn = document.getElementById('pbsgPrev');
const nextBtn = document.getElementById('pbsgNext');

const branchModal = document.getElementById('pbsgBranchModal');
const branchText = document.getElementById('pbsgBranchText');
const branchOpenBtn = document.getElementById('pbsgBranchOpen');
const branchReturnBtn = document.getElementById('pbsgBranchReturn');
const branchCompleteBtn = document.getElementById('pbsgBranchComplete');
const branchSkipBtn = document.getElementById('pbsgBranchSkip');
const branchCloseBtn = document.getElementById('pbsgBranchClose');

const introScreen = document.getElementById('pbsgIntroScreen');
const mainContent = document.getElementById('pbsgMainContent');
const startTutorialBtn = document.getElementById('pbsgStartTutorial');

// Add a class to the body when the tutorial is active
document.body.classList.add('tutorial-active');

document.addEventListener('DOMContentLoaded', function() {    

    /**
     * Accessibility Dashboard: Custom Shortcuts Listener
     */
    function initCustomShortcuts() {
        // Check if the user has shortcuts enabled and defined (passed from PHP)
        if (typeof window.aeShortcuts === 'undefined') {
            return; // Shortcuts not enabled for this user
        }

        const shortcuts = window.aeShortcuts;

        document.addEventListener('keydown', function(event) {
            // Ignore keypresses if the user is typing inside an input, textarea, or contenteditable
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) || event.target.isContentEditable) {
                return;
            }
            // Map the pressed key to the corresponding action
            switch (event.key) {
                case shortcuts.prev:
                    event.preventDefault(); // Prevent default browser scrolling if using arrow keys
                    triggerPreviousAction();
                    break;
                    
                case shortcuts.next:
                    event.preventDefault();
                    triggerNextAction();
                    break;
                    
                case shortcuts.focus_quiz:
                    event.preventDefault();
                    triggerFocusQuiz();
                    break;
                    
                case shortcuts.focus_tutorial:
                    event.preventDefault();
                    triggerFocusTutorial();
                    break;
            }
        });
    }

    // Helper functions to trigger the actions.
    
    function triggerPreviousAction() {
        if (prevBtn && !prevBtn.disabled) {
            prevBtn.click();
        }
    }

    function triggerNextAction() {
        if (nextBtn && !nextBtn.disabled) {
            nextBtn.click();
        }
    }

    function triggerFocusQuiz() {
        toggleFocus('quiz');        
    }

    function triggerFocusTutorial() {
        toggleFocus('tutorial');        

    }

    // Initialize the listener
    initCustomShortcuts();
});

// --------------------
// Menu (step list) in quiz pane
// --------------------
const menuBtn = document.getElementById('pbsgMenuBtn');
const menuDd  = document.getElementById('pbsgMenuDropdown');

function openMenu(){
  if (!menuDd || !menuBtn) return;
  menuDd.classList.add('is-open');
  menuBtn.setAttribute('aria-expanded','true');
}
function closeMenu(){
  if (!menuDd || !menuBtn) return;
  menuDd.classList.remove('is-open');
  menuBtn.setAttribute('aria-expanded','false');
}

function bindMenu(){
  if (!menuBtn || !menuDd) return;

  menuBtn.addEventListener('click', (e)=>{
    e.stopPropagation();
    if (menuDd.classList.contains('is-open')) closeMenu();
    else openMenu();
  });

  document.addEventListener('click', ()=>closeMenu());
  menuDd.addEventListener('click', (e)=>e.stopPropagation());

  menuDd.querySelectorAll('.pbsg-menu-item').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      if (btn.classList.contains('is-disabled')) return;
      const idx = parseInt(btn.dataset.stepIndex, 10);
      if (!Number.isFinite(idx)) return;
      window.pbsgGoToStep(idx);
      closeMenu();
    });
  });
}

// Only allow going BACK (or current). Future steps are disabled.
function updateMenuState(){
  if (!menuDd) return;

  const items = menuDd.querySelectorAll('.pbsg-menu-item');
  items.forEach(el=>{
    const idx = parseInt(el.dataset.stepIndex, 10);
    const isCurrent = idx === i;
    const isFuture = idx > i;

    el.classList.toggle('is-current', isCurrent);
    el.classList.toggle('is-disabled', isFuture);
  });
}

// Expose a jump function that uses your existing render()
window.pbsgGoToStep = function(index){
  if (!Number.isFinite(index)) return;
  if (index < 0 || index >= steps.length) return;

  // block jumping forward
  if (index > i) return;

  i = index;
  render();
};


// --------------------
// Gate NEXT by quiz correctness (H5P)
// --------------------
const passedSteps = new Set(); // remember which steps are already correct

const triggeredBranchSteps = new Set();
const completedBranchSteps = new Set();

let activeBranchStep = null;
let branchReturnTarget = null;

let h5pObs = null;
let h5pClickHandler = null;
let h5pBoundDoc = null;

function lockNext(locked){
  if (!nextBtn) return;
  nextBtn.disabled = !!locked;
  nextBtn.classList.toggle('pbsg-locked', !!locked);
}

function openBranchModal() {
  if (!branchModal) return;
  branchModal.style.display = '';
  branchModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('pbsg-branch-modal-open');
}

function closeBranchModal() {
  if (!branchModal) return;
  branchModal.style.display = 'none';
  branchModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('pbsg-branch-modal-open');
}


function hasBranch(step) {
  return !!(
    step &&
    step.branch &&
    step.branch.mode !== 'none' &&
    step.branch.tutorial &&
    (
      (step.branch.tutorial.type === 'url' && step.branch.tutorial.url) ||
      (step.branch.tutorial.type === 'file' && step.branch.tutorial.file_url)
    )
  );
}

function shouldTriggerBranch(stepIndex) {
  const step = steps[stepIndex];
  if (!hasBranch(step)) return false;

  const required = step.branch.trigger_attempts || 1;
  const attempts = attemptCounts[stepIndex] || 0;

  return attempts >= required;
}

function isMandatoryBranch(step) {
  return hasBranch(step) && step.branch.mode === 'mandatory';
}

function resetBranchUI() {
  activeBranchStep = null;
  branchReturnTarget = null;

  closeBranchModal();

  if (branchText) branchText.innerHTML = '';

  if (branchReturnBtn) branchReturnBtn.style.display = 'none';
  if (branchCompleteBtn) branchCompleteBtn.style.display = 'none';
  if (branchSkipBtn) branchSkipBtn.style.display = 'none';
  if (branchOpenBtn) {
    branchOpenBtn.style.display = 'inline-block';
    branchOpenBtn.textContent = 'Start';
  }
  if (branchCloseBtn) branchCloseBtn.style.display = 'none';
}

function showBranchPrompt(stepIndex) {
  const step = steps[stepIndex];
  if (!hasBranch(step) || !branchText) return;

  activeBranchStep = stepIndex;
  branchReturnTarget = stepIndex;

  const required = Math.max(1, parseInt(step.branch.trigger_attempts, 10) || 1);
  const attempts = Math.max(required, parseInt(attemptCounts[stepIndex], 10) || required);
  const title = step.branch.title || 'Branch Review';

  let intro = step.branch.intro || '';
  if (!intro) {
    if (step.branch.mode === 'mandatory') {
      intro = `You answered this question incorrectly ${attempts} ${attempts === 1 ? 'time' : 'times'}. You must complete this sub-tutorial before continuing.`;
    } else {
      intro = `You answered this question incorrectly ${attempts} ${attempts === 1 ? 'time' : 'times'}. Practicing this sub-tutorial may help you learn better.`;
    }
  }

  const modeText = step.branch.mode === 'mandatory'
    ? 'You can continue only after you finish this sub-tutorial and answer the main quiz correctly.'
    : 'You may start the sub-tutorial now or skip it and return to the main tutorial.';

  const modalTitle = document.getElementById('pbsgBranchModalTitle');
  if (modalTitle) modalTitle.textContent = title;

  branchText.innerHTML = `
    ${intro}<br>
    <span class="pbsg-branch-mode">${modeText}</span>
  `;

  if (branchOpenBtn) {
    branchOpenBtn.textContent = 'Start';
    branchOpenBtn.style.display = 'inline-block';
  }

  if (branchReturnBtn) branchReturnBtn.style.display = 'none';

  if (step.branch.mode === 'mandatory') {
    if (branchCompleteBtn) {
      branchCompleteBtn.textContent = 'I Finished This Sub-Tutorial';
      branchCompleteBtn.style.display = 'inline-block';
    }
    if (branchSkipBtn) branchSkipBtn.style.display = 'none';
    if (branchCloseBtn) branchCloseBtn.style.display = 'none';
  } else {
    if (branchCompleteBtn) branchCompleteBtn.style.display = 'none';
    if (branchSkipBtn) {
      branchSkipBtn.textContent = 'Skip';
      branchSkipBtn.style.display = 'inline-block';
    }
    if (branchCloseBtn) branchCloseBtn.style.display = 'inline-block';
  }

  openBranchModal();
}

function renderBranchTutorial(stepIndex) {
  const step = steps[stepIndex];
  if (!hasBranch(step)) return;

  let url = '';
  if (step.branch.tutorial.type === 'url') {
    url = step.branch.tutorial.url || '';
  } else if (step.branch.tutorial.type === 'file') {
    url = step.branch.tutorial.file_url || '';
  }

  if (!url) return;

  window.open(url, '_blank', 'noopener,noreferrer');

  if (step.branch.mode === 'mandatory') {
    if (branchOpenBtn) branchOpenBtn.style.display = 'none';
    if (branchCompleteBtn) branchCompleteBtn.style.display = 'inline-block';
    if (branchSkipBtn) branchSkipBtn.style.display = 'none';
  } else {
    closeBranchModal();
  }
}

function returnToMainTutorial() {
  if (branchReturnTarget === null || !steps[branchReturnTarget]) return;
  renderTutorial(steps[branchReturnTarget]);
}

function isCurrentStepBlockedByMandatoryBranch() {
  const step = steps[i];
  if (!step || !isMandatoryBranch(step)) return false;
  if (!triggeredBranchSteps.has(i)) return false;
  return !completedBranchSteps.has(i);
}



// Heuristics to detect "correct" in H5P iframe document.
// Works across common H5P content types.
function isH5PCorrect(doc){
  if (!doc || !doc.body) return false;

  // A) Look for "You got X out of Y"
  const txt = (doc.body.innerText || '').replace(/\s+/g,' ').trim();
  const m = txt.match(/You got\s+(\d+)\s+out of\s+(\d+)/i);
  if (m) {
    const got = Number(m[1]), total = Number(m[2]);
    return Number.isFinite(got) && Number.isFinite(total) && total > 0 && got === total;
  }

  // B) Look for "100%"
  if (/\b100\s*%\b/i.test(txt)) return true;

  // C) Look for score format like "1/1" (your screenshot)
  // Check common score elements first (more reliable than scanning whole page text)
  const scoreNodes = doc.querySelectorAll(
    '.h5p-joubelui-score-number,' +
    '.h5p-score,' +
    '.h5p-question-score,' +
    '[class*="score"]'
  );

  for (const el of scoreNodes) {
    const s = (el.textContent || '').trim();
    const mm = s.match(/^(\d+)\s*\/\s*(\d+)$/);
    if (mm) {
      const got = Number(mm[1]), total = Number(mm[2]);
      if (total > 0 && got === total) return true;
    }
  }

  // As a fallback, search the full text for the first "x/y" and evaluate it
  const any = txt.match(/\b(\d+)\s*\/\s*(\d+)\b/);
  if (any) {
    const got = Number(any[1]), total = Number(any[2]);
    if (total > 0 && got === total) return true;
  }

  // D) Common correct/incorrect classes
  if (doc.querySelector('.h5p-incorrect, .h5p-feedback-incorrect')) return false;
  if (doc.querySelector('.h5p-correct, .h5p-feedback-correct')) return true;

  if (txt.includes('✓')) return true;

  return false;
}


function isCheckButton(el){
  if (!el) return false;

  const text = (el.innerText || el.textContent || el.value || '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();

  const cls = (el.className || '').toString().toLowerCase();

  // Match common H5P check buttons
  if (cls.includes('check-answer')) return true;
  if (cls.includes('h5p-question-check-answer')) return true;

  // Match visible button text
  if (text === 'check') return true;
  if (text === 'check answer') return true;

  return false;
}

function attachH5PWatcher(stepIndex){
  // If no quiz in this step, no gating needed
  if (!h5pFrame || !steps[stepIndex]?.h5p_id) {
    lockNext(false);
    return;
  }

  // Disconnect old observer
  if (h5pObs) {
    try { h5pObs.disconnect(); } catch(e) {}
    h5pObs = null;
  }

  // Remove old click handler from previous iframe doc
  if (h5pBoundDoc && h5pClickHandler) {
    try {
      h5pBoundDoc.removeEventListener('click', h5pClickHandler, true);
    } catch (e) {}
    h5pBoundDoc = null;
    h5pClickHandler = null;
  }

  const tryAttach = () => {
    let doc;
    try {
      doc = h5pFrame.contentDocument || h5pFrame.contentWindow.document;
    } catch (e) {
      // Cross-origin -> can't read, fail open
      lockNext(false);
      return true;
    }

    if (!doc || !doc.body) return false;

  const updatePassState = () => {
    const correct = isH5PCorrect(doc);
    const step = steps[stepIndex];

    if (correct) {
      passedSteps.add(stepIndex);
      resetBranchUI();
      lockNext(false);
      updateCertificateGate();
      return;
    }
    
    passedSteps.delete(stepIndex);

   if (shouldTriggerBranch(stepIndex)) {
      triggeredBranchSteps.add(stepIndex);
      showBranchPrompt(stepIndex);

      if (step.branch.mode === 'mandatory' && !completedBranchSteps.has(stepIndex)) {
        lockNext(true);
      } else {
        lockNext(true);
      }
    } else {
      lockNext(true);
    }

    updateCertificateGate();
  };

    // Initial status only: DO NOT count here
    updatePassState();

    // Count only real clicks on the H5P Check button
    h5pClickHandler = (e) => {
      const btn = e.target && e.target.closest
        ? e.target.closest('button, input[type="button"], input[type="submit"]')
        : null;

      if (!btn) return;
      if (!isCheckButton(btn)) return;

      attemptCounts[stepIndex] = (attemptCounts[stepIndex] || 0) + 1;

      // Wait a moment for H5P to update the result after clicking Check
      setTimeout(() => {
        updatePassState();
      }, 250);
    };

    doc.addEventListener('click', h5pClickHandler, true);
    h5pBoundDoc = doc;

    // Keep pass/fail state updated when H5P redraws result UI
    h5pObs = new MutationObserver(() => {
      updatePassState();
    });
    h5pObs.observe(doc.body, { childList: true, subtree: true, attributes: true });

    return true;
  };

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
const summaryCertBtn = document.getElementById('pbsgSummaryCertDownload');
const summaryCertName = document.getElementById('pbsgSummaryCertName');
const certHint = document.getElementById('pbsgCertHint');
const finalGradeEl = document.getElementById('pbsgFinalGrade');
const retakeBtn = document.getElementById('pbsgRetakeTutorial');

function lockCert(locked, msg){
  if (!certBtn) return;
  certBtn.classList.toggle('pbsg-locked', !!locked);
  certBtn.disabled = !!locked;
  if (certHint && msg !== undefined) certHint.textContent = msg;
}

function requiredQuizStepsCount(){
  return steps.filter(s => !!s.h5p_id).length;
}

function passedQuizStepsCount(){
  // only count steps that actually have quizzes
  let n = 0;
  steps.forEach((s, idx) => {
    if (s.h5p_id && passedSteps.has(idx)) n++;
  });
  return n;
}

function allQuizzesPassed(){
  return passedQuizStepsCount() === requiredQuizStepsCount();
}

function getFinalGradePercent(){
  const total = requiredQuizStepsCount();
  const passed = passedQuizStepsCount();

  if (total === 0) return 100;

  return ((passed / total) * 100).toFixed(2);
}

function resetTutorialToStart(){
  const summaryScreen = document.getElementById('pbsgSummaryScreen');

  if (summaryScreen) summaryScreen.style.display = 'none';

  i = 0;

  certMarked = false;

  triggeredBranchSteps.clear();
  completedBranchSteps.clear();
  resetBranchUI();

  steps.forEach((step, idx) => {
    attemptCounts[idx] = 0;
    passedSteps.delete(idx);
  });

  if (hasIntroScreen()) {
    if (introScreen) introScreen.style.display = '';
    if (mainContent) mainContent.style.display = 'none';
  } else {
    if (mainContent) mainContent.style.display = '';
    render();
  }
}

function hasIntroScreen(){
  return !!introScreen;
}

function updateCertificateGate(){
  if (!certBtn) return;

  // Only show/allow certificate on last step
  if (i !== steps.length - 1) {
    lockCert(true, '');
    return;
  }

  const total = requiredQuizStepsCount();
  const passed = passedQuizStepsCount();

  if (total === 0) {
    lockCert(false, ''); // no quizzes => allow
    return;
  }

  if (allQuizzesPassed()) {
    lockCert(false, 'All steps passed. You can download your certificate.');
  } else {
    lockCert(true, `Complete all quiz steps correctly first (${passed}/${total} passed).`);
  }

  finalizeCompletionIfReady();
}


let certMarked = false;

let i = 0;

let attemptCounts = {};
steps.forEach((_, idx) => {
  attemptCounts[idx] = 0;
});

async function markCompletedOnce() {
  if (!window.PBSG_CERT?.isLoggedIn) return false;
  if (certMarked) return true;

  const form = new FormData();
  form.append('action', 'pbsg_mark_completed');
  form.append('tutorial_id', String(window.PBSG_CERT.tutorialId));
  form.append('nonce', window.PBSG_CERT.nonce);

  try {
    const res = await fetch(window.PBSG_CERT.ajaxUrl, {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
    });

    const json = await res.json();

    if (!json?.success) {
      if (certHint) certHint.textContent = json?.data?.message || 'Unable to mark completed.';
      return false;
    }

    certMarked = true;

    if (certHint) {
      certHint.textContent = 'Completion recorded. You can download your certificate.';
    }

    return true;
  } catch (e) {
    if (certHint) certHint.textContent = 'Network error while saving completion.';
    return false;
  }
}


async function finalizeCompletionIfReady() {
  if (!window.PBSG_CERT?.isLoggedIn) return false;
  if (i !== steps.length - 1) return false;
  if (!allQuizzesPassed()) return false;

  return await markCompletedOnce();
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


    // vimeo.com/<id> or player.vimeo.com/video/<id>
  if (host === 'vimeo.com' || host === 'player.vimeo.com') {
    let id = '';

    // Normal Vimeo URL: /123456789
    const parts = u.pathname.split('/').filter(Boolean);

    if (host === 'vimeo.com') {
      // Examples:
      // /123456789
      // /channels/staffpicks/123456789
      // /ondemand/xyz/123456789
      for (let j = parts.length - 1; j >= 0; j--) {
        if (/^\d+$/.test(parts[j])) {
          id = parts[j];
          break;
        }
      }
    }

    if (host === 'player.vimeo.com') {
      // Example: /video/123456789
      const videoIndex = parts.indexOf('video');
      if (videoIndex !== -1 && parts[videoIndex + 1] && /^\d+$/.test(parts[videoIndex + 1])) {
        id = parts[videoIndex + 1];
      }
    }

    if (!id) return rawUrl;

    const embed = new URL(`https://player.vimeo.com/video/${id}`);

    // Keep optional timestamp if present
    const t = u.searchParams.get('t') || u.searchParams.get('#t');
    if (t) {
      embed.searchParams.set('t', t);
    }

    return embed.toString();
  }

    // dailymotion.com/video/<id> or dai.ly/<id>
  if (host === 'dailymotion.com' || host === 'www.dailymotion.com' || host === 'dai.ly') {
    let id = '';

    if (host === 'dai.ly') {
      // Short link: dai.ly/x8abcde
      id = u.pathname.replace(/^\//, '').split('/')[0];
    }

    if (host.includes('dailymotion.com')) {
      // Example: /video/x8abcde
      const parts = u.pathname.split('/').filter(Boolean);
      const videoIndex = parts.indexOf('video');
      if (videoIndex !== -1 && parts[videoIndex + 1]) {
        id = parts[videoIndex + 1];
      }
    }

    if (!id) return rawUrl;

    const embed = new URL(`https://www.dailymotion.com/embed/video/${id}`);

    // Optional: start time support
    const start = u.searchParams.get('start');
    if (start) embed.searchParams.set('start', start);

    return embed.toString();
  }


    // TED talk URLs -> embed.ted.com
  if (host === 'ted.com' || host === 'www.ted.com' || host === 'embed.ted.com') {
    const parts = u.pathname.split('/').filter(Boolean);

    // Already embed URL
    if (host === 'embed.ted.com') {
      return rawUrl;
    }

    // Example: /talks/sir_ken_robinson_do_schools_kill_creativity
    if (parts[0] === 'talks' && parts[1]) {
      let path = `/talks/${parts[1]}`;

      // Preserve language when present
      const lang = u.searchParams.get('language');
      if (lang) {
        path = `/talks/lang/${lang}/${parts[1]}`;
      }

      return `https://embed.ted.com${path}`;
    }
  }

  return rawUrl;
}

function renderTutorial(step){
  const t = step.tutorial || {};
  const tutorialStage = document.getElementById('pbsgTutorialStage');

  if (!tutorialStage) return;

  // Reset stage to iframe by default
  tutorialStage.innerHTML = `
    <iframe id="pbsgTutorialFrame" class="pbsg-iframe"
      allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
      allowfullscreen></iframe>
  `;

  const freshFrame = document.getElementById('pbsgTutorialFrame');

  if (t.type === 'file' && t.file_url) {
    const mime = (t.mime || '').toLowerCase();

    // PDF inline
    if (mime.includes('pdf')) {
      freshFrame.src = t.file_url;
      fallback.style.display = 'none';
      openLink.href = t.file_url;
      return;
    }

    // Video inline
    if (mime.startsWith('video/')) {
      tutorialStage.innerHTML = `
        <video class="pbsg-inline-video" controls preload="metadata">
          <source src="${t.file_url}" type="${mime}">
          Your browser does not support video playback.
        </video>
      `;
      fallback.style.display = 'none';
      openLink.href = t.file_url;
      return;
    }

    // Audio inline
    if (mime.startsWith('audio/')) {
      tutorialStage.innerHTML = `
        <div class="pbsg-inline-audio-wrap">
          <audio class="pbsg-inline-audio" controls preload="metadata">
            <source src="${t.file_url}" type="${mime}">
            Your browser does not support audio playback.
          </audio>
        </div>
      `;
      fallback.style.display = 'none';
      openLink.href = t.file_url;
      return;
    }

    // Other files fallback
    fallback.style.display = 'block';
    fallbackLink.href = t.file_url;
    freshFrame.src = '';
    openLink.href = t.file_url;
    return;
  }

  if (t.url) {
    freshFrame.src = toEmbeddableUrl(t.url);
    openLink.href = t.url;
    fallback.style.display = 'none';
  } else {
    freshFrame.src = '';
    fallback.style.display = 'none';
    openLink.href = '#';
  }
}

function render(){

  resetBranchUI();

  const step = steps[i];
  if (!step) return;

  if (step.h5p_id) h5pFrame.src = h5pUrl(step.h5p_id);
  else h5pFrame.src='';

  renderTutorial(step);

  //titleEl.textContent = step.title || `Step ${i+1}`;
  if (titleEl) titleEl.textContent = '';
  // Inline (left pane) progress
  progressEl.textContent = `Page: ${i+1} of ${steps.length}`;

  

  // Bottom progress bar
  const pct = steps.length ? ((i + 1) / steps.length) * 100 : 0;
  if (progressFillEl) progressFillEl.style.width = pct.toFixed(2) + '%';
  if (progressLabelEl) progressLabelEl.textContent = `Page: ${i+1} of ${steps.length}`;

  prevBtn.disabled = i === 0;

  if (step.h5p_id) {
    if (isCurrentStepBlockedByMandatoryBranch()) {
      lockNext(true);
    } else {
      lockNext(!passedSteps.has(i));
    }
  } else {
    lockNext(false);
  }

  // IMPORTANT: if this step has a quiz, attach watcher even on last page
  if (step.h5p_id) {
    attachH5PWatcher(i);
  }

  // Certificate: show only on final step
  if (certBox) {
    if (i === steps.length - 1) {
      certBox.style.display = 'block';
    } else {
      certBox.style.display = 'none';
    }
  }

  updateCertificateGate();
  updateMenuState();
}

prevBtn.onclick = ()=>{ if(i>0){i--;render();} };

nextBtn.onclick = async () => {
  if (i < steps.length - 1) {
    i++;
    render();
  } else {
    await finalizeCompletionIfReady();
    showSummaryScreen();
  }
};


  if (branchOpenBtn) {
    branchOpenBtn.onclick = () => {
      if (activeBranchStep === null) return;
      renderBranchTutorial(activeBranchStep);
    };
  }

  if (branchReturnBtn) {
    branchReturnBtn.onclick = () => {
      returnToMainTutorial();
    };
  }

  if (branchSkipBtn) {
    branchSkipBtn.onclick = () => {
      if (activeBranchStep === null) return;

      const step = steps[activeBranchStep];
      if (step.branch.mode === 'mandatory') return;

      resetBranchUI();

      if (step.h5p_id) {
        lockNext(true);
      } else {
        lockNext(false);
      }
    };
  }

  if (branchCompleteBtn) {
    branchCompleteBtn.onclick = () => {
      if (activeBranchStep === null) return;

      completedBranchSteps.add(activeBranchStep);
      const completedStep = activeBranchStep;
      const step = steps[completedStep];

      resetBranchUI();

      if (completedStep === i) {
        if (step.branch.mode === 'mandatory') {
          if (passedSteps.has(i)) {
            lockNext(false);
          } else {
            lockNext(true);
          }
        } else {
          if (passedSteps.has(i)) {
            lockNext(false);
          } else {
            lockNext(true);
          }
        }
      }
    };
  }

if (certBtn) {
  certBtn.onclick = () => {
    // extra safety check in UI
    if (!allQuizzesPassed() || i !== steps.length - 1) {
      updateCertificateGate();
      return;
    }

    const name = (certNameInput?.value || '').trim();
    const u = new URL(window.PBSG_CERT.ajaxUrl, location.origin);
    u.searchParams.set('action', 'pbsg_download_certificate');
    u.searchParams.set('tutorial_id', String(window.PBSG_CERT.tutorialId));
    u.searchParams.set('nonce', window.PBSG_CERT.nonce);
    if (name) u.searchParams.set('name', name);

    window.location.href = u.toString();
  };
}

if (summaryCertBtn) {
  summaryCertBtn.onclick = async () => {
    const ok = await finalizeCompletionIfReady();

    if (!ok) {
      alert('Tutorial completion has not been recorded yet. Please make sure all quiz steps are passed.');
      return;
    }

    const name = (summaryCertName?.value || '').trim();

    const u = new URL(window.PBSG_CERT.ajaxUrl, location.origin);
    u.searchParams.set('action', 'pbsg_download_certificate');
    u.searchParams.set('tutorial_id', String(window.PBSG_CERT.tutorialId));
    u.searchParams.set('nonce', window.PBSG_CERT.nonce);

    if (name) u.searchParams.set('name', name);

    window.location.href = u.toString();
  };
}

if (branchCloseBtn) {
  branchCloseBtn.onclick = () => {
    if (activeBranchStep === null) return;

    const step = steps[activeBranchStep];
    if (step.branch.mode === 'mandatory') return;

    resetBranchUI();

    if (step.h5p_id) {
      lockNext(true);
    } else {
      lockNext(false);
    }
  };
}


if (retakeBtn) {
  retakeBtn.onclick = () => {
    resetTutorialToStart();
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

if (startTutorialBtn && introScreen && mainContent) {
  startTutorialBtn.onclick = () => {
    i = 0;
    clearFocus();

    introScreen.style.display = 'none';
    mainContent.style.display = '';

    render();
  };
}

function getFinalGradePercent(){
  const total = requiredQuizStepsCount();
  const passed = passedQuizStepsCount();

  if (total === 0) return 100;

  return ((passed / total) * 100).toFixed(2);
}

function hasIntroScreen(){
  return !!introScreen;
}

function showSummaryScreen(){

  const mainContent = document.getElementById('pbsgMainContent');
  const summaryScreen = document.getElementById('pbsgSummaryScreen');
  const attemptBox = document.getElementById('pbsgAttemptSummary');

  if(mainContent) mainContent.style.display = 'none';
  if(summaryScreen) summaryScreen.style.display = '';

  if(attemptBox){
    let html = '<ul>';

    steps.forEach((step, idx)=>{
      const tries = attemptCounts[idx] || 0;
      const label = step.title || `Question ${idx+1}`;
      html += `<li><strong>${label}</strong>: tried ${tries} time${tries===1?'':'s'}</li>`;
    });

    html += '</ul>';
    attemptBox.innerHTML = html;
  }

  if (finalGradeEl) {
    const grade = getFinalGradePercent();
    finalGradeEl.innerHTML = `<p><strong>Final Grade:</strong> ${grade}%</p>`;
  }
}

bindMenu();
render();

})();