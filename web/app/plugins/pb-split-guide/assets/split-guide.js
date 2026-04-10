(function () {

document.body.classList.add('tutorial-active');

const h5pFrame = document.getElementById('pbsgH5PFrame');
const tutFrame = document.getElementById('pbsgTutorialFrame');
const openLink = document.getElementById('pbsgOpenLink');
const fallback = document.getElementById('pbsgTutorialFallback');
const fallbackLink = document.getElementById('pbsgFallbackLink');

const prevBtn = document.getElementById('pbsgPrev');
const nextBtn = document.getElementById('pbsgNext');
const learnMoreWrap = document.getElementById('pbsgLearnMoreWrap');
const learnMoreBtn = document.getElementById('pbsgLearnMore');
const branchQuizHost = document.getElementById('pbsgBranchQuizHost');

const introScreen = document.getElementById('pbsgIntroScreen');
const mainContent = document.getElementById('pbsgMainContent');
const startTutorialBtn = document.getElementById('pbsgStartTutorial');


// --------------------
// Menu (step list) in quiz pane
// --------------------
const menuBtn = document.getElementById('pbsgMenuBtn');
const menuDd  = document.getElementById('pbsgMenuDropdown');


const h5pFrameHost = h5pFrame ? h5pFrame.parentElement : null;
const h5pFrameCache = new Map();
let activeH5PFrame = h5pFrame || null;

function getActiveH5PFrame() {
  return activeH5PFrame;
}

function hideAllH5PFrames() {
  h5pFrameCache.forEach((frame) => {
    frame.style.display = 'none';
    frame.style.opacity = '0';
  });

  if (h5pFrame && !h5pFrameCache.size) {
    h5pFrame.style.display = 'none';
    h5pFrame.style.opacity = '0';
  }
}

function getOrCreateH5PFrameForStep(stepIndex) {
  if (!h5pFrameHost || !h5pFrame) return null;

  if (h5pFrameCache.has(stepIndex)) {
    return h5pFrameCache.get(stepIndex);
  }

  let frame;

  if (h5pFrameCache.size === 0 && !h5pFrame.dataset.pbsgOwned) {
    frame = h5pFrame;
    frame.dataset.pbsgOwned = '1';
  } else {
    frame = document.createElement('iframe');
    frame.className = h5pFrame.className;
    frame.setAttribute('aria-label', 'Quiz Frame');
    frame.setAttribute('allowfullscreen', '');
    frame.style.display = 'none';
    frame.style.opacity = '0';
    h5pFrameHost.appendChild(frame);
  }

  frame.dataset.stepIndex = String(stepIndex);
  h5pFrameCache.set(stepIndex, frame);
  return frame;
}

function showStepH5PFrame(stepIndex, h5pId) {
  hideAllH5PFrames();

  if (!h5pId) {
    activeH5PFrame = null;
    return null;
  }

  const frame = getOrCreateH5PFrameForStep(stepIndex);
  if (!frame) {
    activeH5PFrame = null;
    return null;
  }

  const wantedId = String(h5pId);

  if (frame.dataset.loadedH5pId !== wantedId) {
    frame.src = h5pUrl(h5pId);
    frame.dataset.loadedH5pId = wantedId;
  }

  frame.style.display = '';
  frame.style.opacity = '1';
  activeH5PFrame = frame;

  return frame;
}

function resetH5PFrameCache() {
  h5pFrameCache.forEach((frame) => {
    if (frame !== h5pFrame && frame.parentNode) {
      frame.parentNode.removeChild(frame);
    }
  });

  h5pFrameCache.clear();

  if (h5pFrame) {
    h5pFrame.removeAttribute('data-step-index');
    h5pFrame.removeAttribute('data-loaded-h5p-id');
    h5pFrame.removeAttribute('data-pbsg-owned');
    h5pFrame.src = '';
    h5pFrame.style.display = '';
    h5pFrame.style.opacity = '0';
  }

  activeH5PFrame = h5pFrame || null;
}


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


function updateMenuState(){
  if (!menuDd) return;

  const items = menuDd.querySelectorAll('.pbsg-menu-item');
  items.forEach(el=>{
    const idx = parseInt(el.dataset.stepIndex, 10);
    const isCurrent = idx === i;
    const isFuture = idx > i;

    el.classList.toggle('is-current', isCurrent);
    el.classList.toggle('is-disabled', inBranch || isFuture);
  });
}

// Expose a jump function that uses your existing render()
window.pbsgGoToStep = function(index){
  if (!Number.isFinite(index)) return;
  if (index < 0 || index >= steps.length) return;

  // block jumping forward
  if (index > i) return;

  if (!inBranch) {
    saveH5PAnswerState(i);
  }

  i = index;
  render();
};


// --------------------
// Gate NEXT by quiz correctness (H5P)
// --------------------
const passedSteps = new Set(); // remember which steps are already correct
const h5pAnswerState = {}; // remember current student answers per step

const triggeredBranchSteps = new Set();
const completedBranchSteps = new Set();


let h5pObs = null;
let h5pClickHandler = null;
let h5pBoundDoc = null;
let h5pInputHandler = null;
let h5pChangeHandler = null;


function buildH5PFieldKey(el, index) {
  return [
    el.name || '',
    el.id || '',
    el.type || '',
    index
  ].join('::');
}

function saveH5PAnswerState(stepIndex) {
  const frame = getActiveH5PFrame();
  if (!Number.isFinite(stepIndex) || !frame) return;

  let doc;
  try {
    doc = frame.contentDocument || frame.contentWindow.document;
  } catch (e) {
    return;
  }

  if (!doc || !doc.body) return;

  const state = {
    choiceSelections: [],
    textValues: []
  };

  // Save visible H5P choice selections by position
  const choiceInputs = Array.from(
    doc.querySelectorAll(
      '.h5p-alternative-container input[type="radio"], ' +
      '.h5p-alternative-container input[type="checkbox"], ' +
      '.h5p-answer input[type="radio"], ' +
      '.h5p-answer input[type="checkbox"]'
    )
  );

  choiceInputs.forEach((el, index) => {
    state.choiceSelections.push({
      index,
      type: (el.type || '').toLowerCase(),
      checked: !!el.checked
    });
  });

  // Save visible text fields by position
  const textFields = Array.from(
    doc.querySelectorAll(
      'textarea, input[type="text"], input[type="search"], input:not([type])'
    )
  ).filter(el => {
    const type = (el.type || '').toLowerCase();
    return !['hidden', 'radio', 'checkbox', 'button', 'submit'].includes(type);
  });

  textFields.forEach((el, index) => {
    state.textValues.push({
      index,
      value: el.value != null ? el.value : ''
    });
  });

  h5pAnswerState[stepIndex] = state;
}

function restoreH5PAnswerState(stepIndex, doc) {
  const state = h5pAnswerState[stepIndex];
  if (!state || !doc || !doc.body) return false;

  let restoredAny = false;

  // Restore visible H5P choices by position
  const choiceInputs = Array.from(
    doc.querySelectorAll(
      '.h5p-alternative-container input[type="radio"], ' +
      '.h5p-alternative-container input[type="checkbox"], ' +
      '.h5p-answer input[type="radio"], ' +
      '.h5p-answer input[type="checkbox"]'
    )
  );

  if (Array.isArray(state.choiceSelections) && choiceInputs.length) {
    state.choiceSelections.forEach(saved => {
      const el = choiceInputs[saved.index];
      if (!el) return;

      const wantChecked = !!saved.checked;
      const isChecked = !!el.checked;

      if (wantChecked !== isChecked) {
        const clickable =
          el.closest('.h5p-alternative-container') ||
          el.closest('.h5p-answer') ||
          el.closest('label') ||
          el;

        try {
          clickable.click();
        } catch (e) {
          el.checked = wantChecked;
          try {
            el.dispatchEvent(new Event('input', { bubbles: true }));
            el.dispatchEvent(new Event('change', { bubbles: true }));
            el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
          } catch (err) {}
        }
      }

      restoredAny = true;
    });
  }

  // Restore visible text fields by position
  const textFields = Array.from(
    doc.querySelectorAll(
      'textarea, input[type="text"], input[type="search"], input:not([type])'
    )
  ).filter(el => {
    const type = (el.type || '').toLowerCase();
    return !['hidden', 'radio', 'checkbox', 'button', 'submit'].includes(type);
  });

  if (Array.isArray(state.textValues) && textFields.length) {
    state.textValues.forEach(saved => {
      const el = textFields[saved.index];
      if (!el) return;

      el.value = saved.value != null ? saved.value : '';

      try {
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (e) {}

      restoredAny = true;
    });
  }

  return restoredAny;
}

function cleanupH5PWatcher() {
  if (h5pObs) {
    try { h5pObs.disconnect(); } catch (e) {}
    h5pObs = null;
  }

  if (h5pBoundDoc) {
  try {
    if (h5pClickHandler) {
      h5pBoundDoc.removeEventListener('click', h5pClickHandler, true);
    }
    if (h5pInputHandler) {
      h5pBoundDoc.removeEventListener('input', h5pInputHandler, true);
    }
    if (h5pChangeHandler) {
      h5pBoundDoc.removeEventListener('change', h5pChangeHandler, true);
    }
  } catch (e) {}
}

  h5pBoundDoc = null;
  h5pClickHandler = null;
  h5pInputHandler = null;
  h5pChangeHandler = null;
}

function lockNext(locked){
  if (!nextBtn) return;
  nextBtn.disabled = !!locked;
  nextBtn.classList.toggle('pbsg-locked', !!locked);
}

function hasBranch(step) {
  return !!(
    step &&
    step.branch &&
    (step.branch.mode === 'optional' || step.branch.mode === 'mandatory') &&
    (
      (Array.isArray(step.branch.questions) && step.branch.questions.length > 0) ||
      (step.branch.tutorial_type === 'url' && step.branch.tutorial_url) ||
      (step.branch.tutorial_type === 'file' && step.branch.tutorial_file_url)
    )
  );
}

function shouldTriggerBranch(stepIndex) {
  const step = steps[stepIndex];
  if (!hasBranch(step)) return false;

  const attempts = attemptCounts[stepIndex] || 0;
  return attempts >= 1;
}

function isMandatoryBranch(step) {
  return hasBranch(step) && step.branch.mode === 'mandatory';
}

function isCurrentStepBlockedByMandatoryBranch() {
  const step = steps[i];
  if (!step || !isMandatoryBranch(step)) return false;
  if (!triggeredBranchSteps.has(i)) return false;
  return !completedBranchSteps.has(i);
}

function isCurrentStepSkippableByOptionalBranch() {
  const step = steps[i];
  if (!step || !hasBranch(step)) return false;
  if (step.branch.mode !== 'optional') return false;
  if (!triggeredBranchSteps.has(i)) return false;
  if (completedBranchSteps.has(i)) return false;
  return true;
}

window.addEventListener('message', (event) => {
  if (event.origin !== window.location.origin) return;

  const data = event.data || {};
  if (data.type !== 'pbsg_branch_completed') return;

  const stepIndex = parseInt(data.stepIndex, 10);
  if (!Number.isFinite(stepIndex)) return;

  completedBranchSteps.add(stepIndex);
});


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

  return (
    cls.includes('check') ||
    cls.includes('submit') ||
    cls.includes('answer') ||
    text.includes('check') ||
    text.includes('submit')
  );

}

function attachH5PWatcher(stepIndex){
  const frame = getActiveH5PFrame();

  // If no quiz in this step, no gating needed
  if (!frame || !steps[stepIndex]?.h5p_id) {
    lockNext(false);
    return;
  }

  cleanupH5PWatcher();

  const tryAttach = () => {
    let doc;
    try {
      doc = frame.contentDocument || frame.contentWindow.document;
    } catch (e) {
      // Cross-origin -> can't read, fail open
      lockNext(false);
      return true;
    }

    if (!doc || !doc.body) return false;


    // Inject consistent styling into H5P iframe
    try {
      if (!doc.getElementById('pbsg-h5p-style')) {
        const style = doc.createElement('style');
        style.id = 'pbsg-h5p-style';

        style.textContent = `
          body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            font-size: 14px !important;
            color: #1d2327 !important;
            background: #fff !important;
            margin: 0 !important;
            box-sizing: border-box !important;
          }

          .h5p-container,
          .h5p-content,
          .h5p-question {
            font-family: inherit !important;
            box-sizing: border-box !important;
          }

          .h5p-question:first-child,
          .h5p-content > .h5p-question:first-child,
          .h5p-multichoice,
          .h5p-single-choice {
            margin-top: 10px !important;
            margin-left: 5px !important;
          }

          .h5p-content,
          .h5p-question {
            margin-top: 0px !important;
            margin-left: 0px !important;
          }

          .h5p-question-text,
          .h5p-question-introduction {
            font-size: 15px !important;
            font-weight: 500 !important;
            color: #6b7280 !important; 
          }

          .h5p-content,
          .h5p-question,
          .h5p-question-text,
          .h5p-question-introduction {
            color: #6b7280 !important;
            margin-top: 0 !important;
            padding-top: 0 !important;
          }

          .h5p-alternative-container {
            position: relative !important;
            font-size: 14px !important;
            border-radius: 4px !important;
            padding: 8px 12px 8px 42px !important;
            margin-bottom: 6px !important;
            box-sizing: border-box !important;
          }

          .h5p-alternative-container .h5p-alternative-inner,
          .h5p-alternative-container label,
          .h5p-answer,
          .h5p-answer-text {
            position: relative !important;
            z-index: 2 !important;
          }

          .h5p-alternative-container input[type="radio"],
          .h5p-alternative-container input[type="checkbox"] {
            position: absolute !important;
            left: 12px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            margin: 0 !important;
            z-index: 3 !important;
          }

          .h5p-joubelui-button,
          .h5p-question-check-answer,
          .h5p-question-try-again,
          .h5p-question-show-solution {
            min-height: 34px !important;
            padding: 6px 12px !important;
            font-size: 13px !important;
            border-radius: 4px !important;
            background: #f6f7f7 !important;
            color: #1d2327 !important;
            border: 1px solid #ccd0d4 !important;
            box-shadow: none !important;
          }

          .h5p-joubelui-button:hover {
            background: #f0f0f1 !important;
            border-color: #999 !important;
          }

          .h5p-feedback {
            font-size: 13px !important;
          }

          /* Move Check button to the right */
          .h5p-question .h5p-question-buttons,
          .h5p-actions,
          .h5p-joubelui-button-container {
            display: flex !important;
            justify-content: flex-end !important;
          }
        `;

        doc.head.appendChild(style);
      }
    } catch (e) {
      console.warn('H5P style injection failed:', e);
    }

    frame.style.transition = 'opacity 0.12s ease';
    frame.style.opacity = '1';

    let restoreDone = false;

    const tryRestoreAnswers = () => {
      if (restoreDone) return;
      const ok = restoreH5PAnswerState(stepIndex, doc);
      if (ok) {
        restoreDone = true;
      }
    };

    tryRestoreAnswers();
    setTimeout(tryRestoreAnswers, 100);
    setTimeout(tryRestoreAnswers, 300);
    setTimeout(tryRestoreAnswers, 600);

    const updatePassState = ({ allowBranchPrompt = false } = {}) => {
    const correct = isH5PCorrect(doc);
    const step = steps[stepIndex];
    const hasChecked = (attemptCounts[stepIndex] || 0) > 0;

    // Global rule: until Check is clicked, Next must stay disabled
    if (!hasChecked) {
      lockNext(true);
      updateRunningScore();
      updateCertificateGate();
      return;
    }

    if (correct) {
      passedSteps.add(stepIndex);
      lockNext(false);
      updateRunningScore();
      updateCertificateGate();
      return;
    }

    // Only remove passed state after a real Check action
    // Only remove passed state after a real Check action
    if (allowBranchPrompt) {
      passedSteps.delete(stepIndex);
    }

    // A wrong checked answer may trigger branch state
    if (allowBranchPrompt && shouldTriggerBranch(stepIndex)) {
      triggeredBranchSteps.add(stepIndex);
    }

    // Show the Learn More button whenever this step already has a triggered branch
    if (
      triggeredBranchSteps.has(stepIndex) &&
      !completedBranchSteps.has(stepIndex) &&
      !inBranch &&
      stepIndex === i
    ) {
      showLearnMoreButton();
    }

    // For mandatory branch: keep Next locked until branch is completed
    const mustStayLocked =
      isMandatoryBranch(step) &&
      triggeredBranchSteps.has(stepIndex) &&
      !completedBranchSteps.has(stepIndex);

    // Optional branch or no branch: can continue after Check
    lockNext(mustStayLocked);

    updateRunningScore();
    updateCertificateGate();
  };


    // Initial status only: DO NOT count and DO NOT trigger branch popup here
    updatePassState({ allowBranchPrompt: false });

    // Count only real clicks on the H5P Check button
    h5pClickHandler = (e) => {
      const btn = e.target && e.target.closest
        ? e.target.closest('button, input[type="button"], input[type="submit"]')
        : null;

      if (!btn) return;
      if (!isCheckButton(btn)) return;

      attemptCounts[stepIndex] = (attemptCounts[stepIndex] || 0) + 1;

      // Wait for H5P to finish drawing the result, then evaluate once
      setTimeout(() => {
        updatePassState({ allowBranchPrompt: true });
      }, 250);
    };

    doc.addEventListener('click', h5pClickHandler, true);
    h5pBoundDoc = doc;

    h5pInputHandler = (e) => {
      const el = e.target;
      if (!el) return;
      if (!el.matches('input, textarea, select')) return;
      saveH5PAnswerState(stepIndex);
    };

    h5pChangeHandler = (e) => {
      const el = e.target;
      if (!el) return;
      if (!el.matches('input, textarea, select')) return;
      saveH5PAnswerState(stepIndex);
    };

    doc.addEventListener('input', h5pInputHandler, true);
    doc.addEventListener('change', h5pChangeHandler, true);

    // Keep pass/fail state updated when H5P redraws result UI
    h5pObs = new MutationObserver(() => {
      tryRestoreAnswers();
      updatePassState({ allowBranchPrompt: false });
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
const runningScoreEl = document.getElementById('pbsgRunningScore');
const progressFillEl = document.getElementById('pbsgProgressFill');
const progressLabelEl = document.getElementById('pbsgProgressLabel');

const certBox = document.getElementById('pbsgCertificate');
const certNameInput = document.getElementById('pbsgCertName');
const certBtn = document.getElementById('pbsgCertDownload');
const summaryCertBtn = document.getElementById('pbsgSummaryCertDownload');
const certModal = document.getElementById('pbsgCertModal');
const certModalName = document.getElementById('pbsgCertModalName');
const certModalGenerate = document.getElementById('pbsgCertModalGenerate');
const certModalCancel = document.getElementById('pbsgCertModalCancel');
const certModalClose = document.getElementById('pbsgCertModalClose');
const certModalError = document.getElementById('pbsgCertModalError');
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

function attemptedQuizStepsCount(){
  let n = 0;
  steps.forEach((s, idx) => {
    if (s.h5p_id && (attemptCounts[idx] || 0) > 0) n++;
  });
  return n;
}

function passedQuizStepsCount(){
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

function updateRunningScore() {
  if (!runningScoreEl) return;

  const correct = passedQuizStepsCount();
  const attempted = attemptedQuizStepsCount();

  // Use innerHTML so the Marginalia check icon renders as inline SVG.
  // Numeric values are safe (produced by Number coercion above).
  const checkIcon = (typeof PBSG_ICONS !== 'undefined')
    ? PBSG_ICONS.render('check', 'pbsg-icon--ok')
    : '';
  runningScoreEl.innerHTML = `Correct/Attempted ${correct}/${attempted} ${checkIcon}`;
}

function resetTutorialToStart(){
  const summaryScreen = document.getElementById('pbsgSummaryScreen');

  if (summaryScreen) summaryScreen.style.display = 'none';

  i = 0;

  certMarked = false;

  triggeredBranchSteps.clear();
  completedBranchSteps.clear();
  inBranch = false;
  branchParentIndex = null;
  branchStepIndex = 0;
  currentTutorialSignature = null;
  resetH5PFrameCache();
  showH5PFrame();
  hideLearnMoreButton();

    steps.forEach((step, idx) => {
      attemptCounts[idx] = 0;
      passedSteps.delete(idx);
      delete h5pAnswerState[idx];
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

  // Only allow certificate on the summary / last step area
  if (i !== steps.length - 1) {
    lockCert(true, '');
    return;
  }

  // New rule:
  // Certificate is always allowed on the last step,
  // even if not all quizzes are passed.
  lockCert(false, '');

  finalizeCompletionIfReady();
}


let certMarked = false;

let i = 0;
let inBranch = false;
let branchParentIndex = null;
let branchStepIndex = 0;
// Expose branch state for the analytics tracker (read-only accessor)
window.pbsgInBranch = function() { return inBranch; };
let currentTutorialSignature = null;


function getCurrentBranch() {
  if (branchParentIndex === null) return null;
  const parent = steps[branchParentIndex];
  if (!parent || !parent.branch) return null;
  return parent.branch;
}

function getCurrentBranchQuestion() {
  const branch = getCurrentBranch();
  if (!branch || !Array.isArray(branch.questions)) return null;
  return branch.questions[branchStepIndex] || null;
}

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
  if (i !== steps.length - 1) return false;

  const ok = window.PBSG_CERT?.isLoggedIn
    ? await markCompletedOnce()
    : true;

  if (ok) {
    notifyParentBranchCompleted();
  }

  return ok;
}

function h5pUrl(id){
  const u = new URL(ajaxUrl, location.origin);
  u.searchParams.set('action', 'h5p_embed');
  u.searchParams.set('id', id);
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

function renderTutorial(step, options = {}){
  const t = step.tutorial || {};
  const tutorialStage = document.getElementById('pbsgTutorialStage');

  if (!tutorialStage) return;

  const force = !!options.force;
  const nextSignature = getTutorialSignature(step);

  if (!force && currentTutorialSignature === nextSignature) {
    return;
  }

  currentTutorialSignature = nextSignature;

  tutorialStage.innerHTML = `
    <iframe aria-label="Tutorial Frame" id="pbsgTutorialFrame" class="pbsg-iframe"
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

  cleanupH5PWatcher();

  if (inBranch) {
    renderBranchStep();
    return;
  }

  showH5PFrame();
  hideLearnMoreButton();

  const step = steps[i];
  if (!step) return;

  if (
    step.h5p_id &&
    !passedSteps.has(i) &&
    hasBranch(step) &&
    triggeredBranchSteps.has(i) &&
    !completedBranchSteps.has(i)
  ) {
    showLearnMoreButton();

    if (isMandatoryBranch(step)) {
      lockNext(true);
    }
  }

  showStepH5PFrame(i, step.h5p_id);

  renderTutorial(step);

  //titleEl.textContent = step.title || `Step ${i+1}`;
  
  if (titleEl) titleEl.textContent = '';
  if (progressEl) progressEl.textContent = `Page: ${i+1} of ${steps.length}`;
  updateRunningScore();

  

  // Bottom progress bar
  const pct = steps.length ? ((i + 1) / steps.length) * 100 : 0;
  if (progressFillEl) progressFillEl.style.width = pct.toFixed(2) + '%';
  if (progressLabelEl) progressLabelEl.textContent = `Page: ${i+1} of ${steps.length}`;

  prevBtn.disabled = i === 0;

  if (step.h5p_id) {
    const hasChecked = (attemptCounts[i] || 0) > 0;

    if (!hasChecked) {
      lockNext(true);
    } else if (isCurrentStepBlockedByMandatoryBranch()) {
      lockNext(true);
    } else {
      lockNext(false);
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

prevBtn.onclick = () => {
  if (inBranch) {
    if (branchStepIndex > 0) {
      branchStepIndex--;
      renderBranchStep();
    }
    return;
  }

  if (i > 0) {
    saveH5PAnswerState(i);
    i--;
    render();
  }
};

nextBtn.onclick = async () => {
  cleanupH5PWatcher();

  if (inBranch) {
    const branch = getCurrentBranch();
    if (!branch) return;

    if (branchStepIndex < branch.questions.length - 1) {
      branchStepIndex++;
      renderBranchStep();
      return;
    }

    inBranch = false;
    completedBranchSteps.add(branchParentIndex);

    const nextMainIndex = branchParentIndex + 1;

    if (nextMainIndex < steps.length) {
      i = nextMainIndex;
      branchParentIndex = null;
      branchStepIndex = 0;
      render();
    } else {
      branchParentIndex = null;
      branchStepIndex = 0;
      await finalizeCompletionIfReady();
      showSummaryScreen();
    }

    return;
  }

   if (i < steps.length - 1) {
    saveH5PAnswerState(i);
    i++;
    render();
  } else {
    saveH5PAnswerState(i);
    await finalizeCompletionIfReady();
    showSummaryScreen();
  }
};

if (learnMoreBtn) {
  learnMoreBtn.onclick = () => {
    const step = steps[i];
    if (!step || !hasBranch(step)) return;

    inBranch = true;
    branchParentIndex = i;
    branchStepIndex = 0;

    renderBranchStep();
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

function showLearnMoreButton() {
  if (learnMoreWrap) learnMoreWrap.style.display = '';
}

function hideLearnMoreButton() {
  if (learnMoreWrap) learnMoreWrap.style.display = 'none';
}

function showH5PFrame() {
  if (branchQuizHost) {
    branchQuizHost.style.display = 'none';
    branchQuizHost.innerHTML = '';
  }

  const frame = getActiveH5PFrame();
  if (frame) {
    frame.style.display = '';
  }
}

function resetLeftQuizScroll() {
  const quizWrap = document.querySelector('.pbsg-left .pbsg-iframe-wrap');

  if (quizWrap) {
    quizWrap.scrollTop = 0;
    quizWrap.scrollTo({ top: 0, left: 0, behavior: 'auto' });
  }

  if (branchQuizHost) {
    branchQuizHost.scrollTop = 0;
  }
}

function showBranchQuizHost() {
  hideAllH5PFrames();
  activeH5PFrame = null;

  if (branchQuizHost) {
    branchQuizHost.style.display = '';
    branchQuizHost.scrollTop = 0;
  }

  resetLeftQuizScroll();

  requestAnimationFrame(() => {
    resetLeftQuizScroll();
  });

  setTimeout(() => {
    resetLeftQuizScroll();
  }, 0);
}

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (m) {
    return {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    }[m];
  });
}

function renderInlineBranchQuestion(q) {
  if (!branchQuizHost) return;

  showBranchQuizHost();

  const type = q.type || '';
  let html = '';

  if (type === 'multichoice') {
    const answers = Array.isArray(q.answers) ? q.answers : [];
    html = `
      <div class="pbsg-branch-inline-question">
        <h3>${escapeHtml(q.question || '')}</h3>
        <div class="pbsg-branch-inline-answers">
          ${answers.map((a, idx) => `
            <label class="pbsg-branch-inline-answer">
              <input type="checkbox" name="pbsgBranchAnswer" value="${idx}">
              <span>${escapeHtml(a.text || '')}</span>
            </label>
          `).join('')}
        </div>
        <div class="pbsg-branch-inline-actions">
          <button type="button" id="pbsgBranchCheck">Check</button>
        </div>
        <div id="pbsgBranchFeedback" class="pbsg-branch-feedback"></div>
      </div>
    `;
  } else if (type === 'singlechoice') {
    const wrongs = Array.isArray(q.wrong_answers) ? q.wrong_answers : [];
    const choices = [q.correct_answer || '', ...wrongs].filter(Boolean);

    html = `
      <div class="pbsg-branch-inline-question">
        <h3>${escapeHtml(q.question || '')}</h3>
        <div class="pbsg-branch-inline-answers">
          ${choices.map((choice, idx) => `
            <label class="pbsg-branch-inline-answer">
              <input type="radio" name="pbsgBranchAnswer" value="${idx}">
              <span>${escapeHtml(choice)}</span>
            </label>
          `).join('')}
        </div>
        <div class="pbsg-branch-inline-actions">
          <button type="button" id="pbsgBranchCheck">Check</button>
        </div>
        <div id="pbsgBranchFeedback" class="pbsg-branch-feedback"></div>
      </div>
    `;

    branchQuizHost.innerHTML = html;
    bindInlineBranchCheck(q, choices);
    return;
  } else if (type === 'blanks') {
    html = `
      <div class="pbsg-branch-inline-question">
        <h3>Fill in the blank</h3>
        <div class="pbsg-branch-inline-blanks">
          <textarea id="pbsgBranchBlanksInput" rows="4">${escapeHtml(q.sentence || '')}</textarea>
        </div>
        <div class="pbsg-branch-inline-actions">
          <button type="button" id="pbsgBranchCheck">Check</button>
        </div>
        <div id="pbsgBranchFeedback" class="pbsg-branch-feedback"></div>
      </div>
    `;
  }

  branchQuizHost.innerHTML = html;
  bindInlineBranchCheck(q);
}

function bindInlineBranchCheck(q, singleChoices = []) {
  const checkBtn = document.getElementById('pbsgBranchCheck');
  const feedback = document.getElementById('pbsgBranchFeedback');

  if (!checkBtn || !feedback) return;

  checkBtn.onclick = () => {
    let correct = false;

    if (q.type === 'multichoice') {
      const selected = Array.from(document.querySelectorAll('input[name="pbsgBranchAnswer"]:checked'))
        .map(el => parseInt(el.value, 10))
        .sort();

      const correctIdx = (q.answers || [])
        .map((a, idx) => a.correct ? idx : -1)
        .filter(idx => idx >= 0)
        .sort();

      correct = JSON.stringify(selected) === JSON.stringify(correctIdx);
    } else if (q.type === 'singlechoice') {
      const selected = document.querySelector('input[name="pbsgBranchAnswer"]:checked');
      if (selected) {
        const idx = parseInt(selected.value, 10);
        correct = singleChoices[idx] === (q.correct_answer || '');
      }
    } else if (q.type === 'blanks') {
      const input = document.getElementById('pbsgBranchBlanksInput');
      const value = (input?.value || '').trim();
      correct = value.length > 0;
    }

    if (correct) {
      feedback.textContent = 'Correct.';
      feedback.className = 'pbsg-branch-feedback is-correct';
      lockNext(false);
    } else {
      feedback.textContent = 'Try again.';
      feedback.className = 'pbsg-branch-feedback is-wrong';
      lockNext(true);
    }
  };
}

function buildBranchTutorialStep(branch) {
  return {
    tutorial: {
      type: branch.tutorial_type || '',
      url: branch.tutorial_url || '',
      file_url: branch.tutorial_file_url || '',
      mime: branch.tutorial_mime || ''
    }
  };
}

function getTutorialSignature(step) {
  const t = step?.tutorial || {};
  return JSON.stringify({
    type: t.type || '',
    url: t.url || '',
    file_url: t.file_url || '',
    mime: t.mime || ''
  });
}

function buildMainTutorialStepForBranch(parentStep) {
  return {
    tutorial: {
      type: parentStep?.tutorial?.type || '',
      url: parentStep?.tutorial?.url || '',
      file_url: parentStep?.tutorial?.file_url || '',
      mime: parentStep?.tutorial?.mime || ''
    }
  };
}

function buildBranchQuestionTutorialStep(q) {
  return {
    tutorial: {
      type: q?.tutorial_type || '',
      url: q?.tutorial_url || '',
      file_url: q?.tutorial_file_url || '',
      mime: q?.tutorial_mime || ''
    }
  };
}

function getEffectiveBranchTutorialStep(parentStep, branch, q) {
  const mode = branch?.resource_mode || 'main';

  if (mode === 'main') {
    return buildMainTutorialStepForBranch(parentStep);
  }

  if (mode === 'per_question') {
    return buildBranchQuestionTutorialStep(q);
  }

  return buildBranchTutorialStep(branch);
}

function renderBranchStep() {
  cleanupH5PWatcher();
  hideLearnMoreButton();

  const parentStep = steps[branchParentIndex];
  const branch = getCurrentBranch();
  const q = getCurrentBranchQuestion();

  if (!branch || !q || !parentStep) return;

  renderInlineBranchQuestion(q);

  resetLeftQuizScroll();

  requestAnimationFrame(() => {
    resetLeftQuizScroll();
  });

  setTimeout(() => {
    resetLeftQuizScroll();
  }, 0);

  const effectiveTutorialStep = getEffectiveBranchTutorialStep(parentStep, branch, q);
  renderTutorial(effectiveTutorialStep);

  
  const branchTotal = Array.isArray(branch.questions) ? branch.questions.length : 0;
  const letter = String.fromCharCode(65 + branchStepIndex); // A, B, C...
  const mainNumber = branchParentIndex + 1;
  const branchCurrent = branchStepIndex + 1;

  const pageText = `Page: ${mainNumber}${letter} of ${branchTotal}`;

  if (progressEl) progressEl.textContent = pageText;
  updateRunningScore();
  if (progressLabelEl) progressLabelEl.textContent = pageText;

  const pct = branchTotal ? (branchCurrent / branchTotal) * 100 : 0;
  if (progressFillEl) progressFillEl.style.width = pct.toFixed(2) + '%';


  prevBtn.disabled = branchStepIndex === 0;
  lockNext(true);

  updateMenuStateForBranch();
}

function updateMenuStateForBranch() {
  if (!menuDd) return;

  const items = menuDd.querySelectorAll('.pbsg-menu-item');
  items.forEach(el => {
    el.classList.remove('is-current');
    el.classList.add('is-disabled');
  });
}


function openCertModal() {
  if (!certModal) return;
  certModal.style.display = '';
  certModal.setAttribute('aria-hidden', 'false');

  if (certModalError) {
    certModalError.style.display = 'none';
    certModalError.textContent = '';
  }

  if (certModalName) {
    certModalName.value = '';
    setTimeout(() => certModalName.focus(), 50);
  }
}

function closeCertModal() {
  if (!certModal) return;
  certModal.style.display = 'none';
  certModal.setAttribute('aria-hidden', 'true');

  if (certModalError) {
    certModalError.style.display = 'none';
    certModalError.textContent = '';
  }
}

function buildCertificateUrl(studentName) {
  const u = new URL(window.PBSG_CERT.ajaxUrl, location.origin);
  u.searchParams.set('action', 'pbsg_download_certificate');
  u.searchParams.set('tutorial_id', String(window.PBSG_CERT.tutorialId));
  u.searchParams.set('nonce', window.PBSG_CERT.nonce);
  u.searchParams.set('name', studentName);
  u.searchParams.set('final_score', String(getFinalGradePercent()));
  return u.toString();
}

if (summaryCertBtn) {
  summaryCertBtn.onclick = async () => {
    await finalizeCompletionIfReady();
    openCertModal();
  };
}

if (certModalGenerate) {
  certModalGenerate.onclick = async () => {
    const name = (certModalName?.value || '').trim();

    if (!name) {
      if (certModalError) {
        certModalError.textContent = 'Student name is required.';
        certModalError.style.display = 'block';
      }
      if (certModalName) certModalName.focus();
      return;
    }

    await finalizeCompletionIfReady();

    const previewUrl = buildCertificateUrl(name);

    // Disable both buttons so certificate cannot be generated again
    certModalGenerate.disabled = true;
    if (summaryCertBtn) {
      summaryCertBtn.disabled = true;
      summaryCertBtn.classList.add('pbsg-locked');
    }

    window.open(previewUrl, '_blank');

    closeCertModal();
  };
}

if (certModalCancel) {
  certModalCancel.onclick = () => {
    closeCertModal();
  };
}

if (certModalClose) {
  certModalClose.onclick = () => {
    closeCertModal();
  };
}

if (certModal) {
  certModal.addEventListener('click', (e) => {
    if (e.target.classList.contains('pbsg-cert-modal-backdrop')) {
      closeCertModal();
    }
  });
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && certModal && certModal.style.display !== 'none') {
    closeCertModal();
  }
});


function handleCloseTutorialAction() {
  const closeUrl = (window.PBSG_CERT?.closeTutorialUrl || '').trim();

  if (closeUrl) {
    window.location.href = closeUrl;
    return;
  }

  // Try to close the current tab
  window.close();
}

retakeBtn.onclick = () => {
  handleCloseTutorialAction();
};

// ===== Focus System =====
const focusTutBtn = document.getElementById('pbsgFocusTutorial');
const focusQuizBtn = document.getElementById('pbsgFocusQuiz');

function clearFocus(){
  document.body.classList.remove('pbsg-focus-tutorial','pbsg-focus-quiz');
  focusTutBtn.textContent='Focus Tutorial';
  focusQuizBtn.textContent='Focus Quiz';
  
  // Remove the inert attribute from both panes when exiting focus mode
  const leftPane = document.querySelector('.pbsg-left');
  const rightPane = document.querySelector('.pbsg-right');
  if (leftPane) leftPane.removeAttribute('inert');
  if (rightPane) rightPane.removeAttribute('inert');
}

function toggleFocus(mode){
  const cls = mode==='tutorial'?'pbsg-focus-tutorial':'pbsg-focus-quiz';
  if(document.body.classList.contains(cls)){ 
    clearFocus(); 
  } else {
    clearFocus();
    document.body.classList.add(cls);
    
    const leftPane = document.querySelector('.pbsg-left');
    const rightPane = document.querySelector('.pbsg-right');
    
    if(mode==='tutorial') {
      focusTutBtn.textContent='Exit Focus';
      // Tutorial is active (right pane), make quiz pane (left pane) inert
      if (leftPane) leftPane.setAttribute('inert', '');
    } else {
      focusQuizBtn.textContent='Exit Focus';
      // Quiz is active (left pane), make tutorial pane (right pane) inert
      if (rightPane) rightPane.setAttribute('inert', '');
    }
  }
}

focusTutBtn.onclick = ()=>toggleFocus('tutorial');
focusQuizBtn.onclick = ()=>toggleFocus('quiz');

document.addEventListener('keydown',e=>{
  if(e.key==='Escape') clearFocus();
});

// ===== Accessibility: Custom Keyboard Shortcuts =====
(function initCustomShortcuts() {
  if (typeof window.aeShortcuts === 'undefined') return;
  const shortcuts = window.aeShortcuts;
  document.addEventListener('keydown', function(event) {
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)
        || event.target.isContentEditable) return;
    switch (event.key) {
      case shortcuts.prev:
        event.preventDefault();
        if (prevBtn && !prevBtn.disabled) prevBtn.click();
        break;
      case shortcuts.next:
        event.preventDefault();
        if (nextBtn && !nextBtn.disabled) nextBtn.click();
        break;
      case shortcuts.focus_quiz:
        event.preventDefault();
        toggleFocus('quiz');
        break;
      case shortcuts.focus_tutorial:
        event.preventDefault();
        toggleFocus('tutorial');
        break;
    }
  });
})();

if (startTutorialBtn && introScreen && mainContent) {
  startTutorialBtn.onclick = () => {
    i = 0;
    clearFocus();

    introScreen.style.display = 'none';
    mainContent.style.display = '';

    render();
  };
}


function notifyParentBranchCompleted() {
  const params = new URLSearchParams(window.location.search);
  const parentStep = params.get('pbsg_branch_parent_step');

  if (!parentStep) return;

  if (window.opener && !window.opener.closed) {
    window.opener.postMessage(
      {
        type: 'pbsg_branch_completed',
        stepIndex: parentStep
      },
      window.location.origin
    );
  }
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
    finalGradeEl.innerHTML = `<p><strong>Final Score:</strong> ${grade}%</p>`;
  }
}

// ===== Resize Handle (Stretch Goal 5b) =====
function initResizer() {
  const handle = document.getElementById('pbsgResizeHandle');
  if (!handle) return;  // not enabled for this guide

  const container = document.querySelector('.pbsg-container');
  if (!container) return;

  const minPct = parseInt(handle.getAttribute('aria-valuemin'), 10) || 10;
  const maxPct = parseInt(handle.getAttribute('aria-valuemax'), 10) || 50;

  let dragging = false;

  handle.addEventListener('pointerdown', function(e) {
    dragging = true;
    handle.setPointerCapture(e.pointerId);
    document.body.style.userSelect = 'none';
    document.body.style.cursor = 'col-resize';
    // Disable iframe pointer events during drag (iframes swallow pointer events)
    container.querySelectorAll('iframe').forEach(function(f) {
      f.style.pointerEvents = 'none';
    });
  });

  document.addEventListener('pointermove', function(e) {
    if (!dragging) return;
    const rect = container.getBoundingClientRect();
    if (rect.width === 0) return;  // guard against hidden/zero-width container
    let pct = ((e.clientX - rect.left) / rect.width) * 100;
    pct = Math.max(minPct, Math.min(maxPct, pct));

    container.style.setProperty('--pbsg-left-ratio', pct.toFixed(1));
    container.style.setProperty('--pbsg-right-ratio', (100 - pct).toFixed(1));
    handle.setAttribute('aria-valuenow', Math.round(pct));
  });

  document.addEventListener('pointerup', function() {
    if (!dragging) return;
    dragging = false;
    document.body.style.userSelect = '';
    document.body.style.cursor = '';
    container.querySelectorAll('iframe').forEach(function(f) {
      f.style.pointerEvents = '';
    });
  });

  // Keyboard accessibility: left/right arrow keys
  handle.addEventListener('keydown', function(e) {
    var currentStr = container.style.getPropertyValue('--pbsg-left-ratio');
    var parsed = parseFloat(currentStr);
    var current = isNaN(parsed)
      ? (parseInt(handle.getAttribute('aria-valuenow'), 10) || 40)
      : parsed;
    var next = current;

    if (e.key === 'ArrowLeft' || e.key === 'Left') {
      next = Math.max(minPct, current - 1);
    } else if (e.key === 'ArrowRight' || e.key === 'Right') {
      next = Math.min(maxPct, current + 1);
    } else {
      return;  // not an arrow key, don't intercept
    }

    if (next !== current) {
      container.style.setProperty('--pbsg-left-ratio', next);
      container.style.setProperty('--pbsg-right-ratio', 100 - next);
      handle.setAttribute('aria-valuenow', Math.round(next));
      e.preventDefault();
    }
  });
}

initResizer();
bindMenu();
render();

})();