(function () {

document.body.classList.add('tutorial-active');

const h5pFrame = document.getElementById('pbsgH5PFrame');
const tutFrame = document.getElementById('pbsgTutorialFrame');
const openLink = document.getElementById('pbsgOpenLink');
const fallback = document.getElementById('pbsgTutorialFallback');
const fallbackLink = document.getElementById('pbsgFallbackLink');

// ── Dual-label nav updaters ──
//
// The nav template renders both a long label ("Page: 2 of 10",
// "Correct/Attempted 3/5") and a short label ("2/10", "3/5") inside the
// progress and running-score elements. CSS container queries decide which
// variant is visible at any given width. Because both copies live in the
// DOM simultaneously, the helpers below MUST use querySelectorAll + forEach
// so the long and short variants stay in sync. Do not swap to querySelector
// on a single span — one of the two copies will go stale.
function pbsgSetProgress(current, total) {
  document.querySelectorAll('#pbsgProgress .pbsg-progress-current').forEach(function (el) {
    el.textContent = current;
  });
  document.querySelectorAll('#pbsgProgress .pbsg-progress-total').forEach(function (el) {
    el.textContent = total;
  });
}

function pbsgSetScore(correct, attempted) {
  var value = correct + '/' + attempted;
  document.querySelectorAll('#pbsgRunningScore .pbsg-score-value').forEach(function (el) {
    el.textContent = value;
  });
}

let tutorialPopup;
let popupPollInterval = null;

// ── Popup window fallback system ──

let popupUrl = '';
let popupFeatures = '';

function isSafari() {
  return /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
}

function bringPopupToFront() {
  if (!popupUrl) return;

  // No stored reference — open fresh
  if (!tutorialPopup) {
    openTutorialPopup(popupUrl);
    return;
  }

  // ── Bring popup to front ─────────────────────────────────────────────
  //
  // Two scenarios based on the popup's .closed property:
  //
  // 1. closed === false  →  opener relationship intact (no COOP barrier).
  //    .focus() works — brings the popup to front. Same technique that
  //    Springshare/LibWizard uses.
  //
  // 2. closed === true   →  either the user genuinely closed the popup,
  //    or the site sent Cross-Origin-Opener-Policy: same-origin which
  //    severs the opener relationship (Chrome reports .closed = true and
  //    silently ignores .focus()). In both cases, reopen.

  var isClosed = false;
  try { isClosed = tutorialPopup.closed; } catch (e) { isClosed = true; }

  if (!isClosed) {
    // Opener relationship intact — .focus() will bring popup to front
    try { tutorialPopup.focus(); } catch (e) { openTutorialPopup(popupUrl); }
  } else {
    // Popup closed or COOP-severed — reopen
    openTutorialPopup(popupUrl);
  }
}

function openTutorialPopup(url) {
  // Close any existing popup cleanly
  if (tutorialPopup) {
    try { tutorialPopup.close(); } catch (e) {}
  }
  tutorialPopup = null;
  clearInterval(popupPollInterval);
  popupPollInterval = null;

  const stage = document.getElementById('pbsgTutorialStage');
  const banner = document.querySelector('.pbsg-banner');
  if (!stage) { window.open(url, '_blank'); return; }

  const stageRect = stage.getBoundingClientRect();
  const bannerRect = banner ? banner.getBoundingClientRect() : stageRect;

  const chromeOffset = window.outerHeight - window.innerHeight;
  const left = Math.round(window.screenX + stageRect.left);
  const w    = Math.round(stageRect.width);
  const h    = Math.round(stageRect.height);

  // Positioning: Chrome and Safari interpret `top` differently.
  // Chrome: positions the popup's OUTER edge (title bar starts at `top`)
  // Safari: positions the popup's CONTENT area (title bar is ~70px ABOVE `top`)
  // Target: popup chrome covers the banner, content aligns with the stage.
  const bannerScreenTop = Math.round(window.screenY + chromeOffset + bannerRect.top);
  const popupChromeEstimate = 90;
  const top = isSafari() ? (bannerScreenTop + popupChromeEstimate) : bannerScreenTop;

  // Store features string ONCE — reuse for re-targeting in bringPopupToFront
  popupFeatures = 'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top +
    ',toolbar=no,menubar=no,location=yes,status=no,scrollbars=yes,resizable=yes';

  tutorialPopup = window.open(url, 'pbsgTutorialWindow', popupFeatures);

  if (!tutorialPopup) {
    window.open(url, '_blank');
    return;
  }

  popupUrl = url;

  // Switch button to "Bring to Front" using onclick (OVERWRITES the initial handler)
  const btn = document.getElementById('pbsgPopupBtn');
  if (btn) {
    btn.textContent = 'Bring Tutorial Window To Front';
    btn.classList.add('pbsg-popup-btn--front');
    btn.onclick = function() { bringPopupToFront(); };
  }

  // Hide "Reopen" link while popup is open
  const reopenLink = document.getElementById('pbsgReopenLink');
  if (reopenLink) reopenLink.style.display = 'none';

  // Poll to show "Reopen" link if popup was closed.
  // IMPORTANT: Do NOT null tutorialPopup here — .closed is unreliable for
  // cross-origin popups in Chrome (returns true even when open). Nulling
  // the reference was the root cause of "Bring to Front" doing nothing.
  // bringPopupToFront() handles the "truly closed" case via try/catch on .focus().
  clearInterval(popupPollInterval);
  popupPollInterval = setInterval(function() {
    try {
      if (tutorialPopup && tutorialPopup.closed) {
        // Only update UI — keep tutorialPopup reference intact
        clearInterval(popupPollInterval);
        popupPollInterval = null;
        const link = document.getElementById('pbsgReopenLink');
        if (link) link.style.display = 'inline';
      }
    } catch (e) {
      // Cross-origin .closed access error — stop polling, keep reference
      clearInterval(popupPollInterval);
      popupPollInterval = null;
    }
  }, 2000);
}

function closeTutorialPopup() {
  if (tutorialPopup) {
    try { tutorialPopup.close(); } catch (e) {}
  }
  tutorialPopup = null;
  // Keep popupUrl and popupFeatures — needed for re-opening if user closed accidentally
  clearInterval(popupPollInterval);
  popupPollInterval = null;
}

function renderPopupFallbackCard(container, url) {
  let domain = '';
  try { domain = new URL(url).hostname; } catch(e) { domain = url; }

  container.innerHTML = `
    <div class="pbsg-popup-fallback-card">
      <div class="pbsg-popup-fallback-icon">${typeof PBSG_ICONS !== 'undefined' && PBSG_ICONS ? PBSG_ICONS.render('arrow-up-right') : ''}</div>
      <p class="pbsg-popup-fallback-msg"><strong>${domain}</strong> cannot be embedded inline.</p>
      <p class="pbsg-popup-fallback-hint">Click below to open it alongside this guide.</p>
      <button type="button" class="pbsg-popup-btn" id="pbsgPopupBtn">Open in a new window</button>
      <a class="pbsg-popup-fallback-link" href="#" id="pbsgReopenLink" style="display:none">Reopen the window</a>
    </div>
  `;

  // "Open in a new window" button — uses onclick (not addEventListener) so
  // openTutorialPopup() can OVERWRITE it with bringPopupToFront(). No dual handlers.
  const btn = document.getElementById('pbsgPopupBtn');
  if (btn) {
    btn.onclick = function() { openTutorialPopup(url); };
  }

  // "Reopen" link — same action, only visible after popup was closed
  const reopenLink = document.getElementById('pbsgReopenLink');
  if (reopenLink) {
    reopenLink.onclick = function(e) { e.preventDefault(); openTutorialPopup(url); };
  }

  // If popup is already open (navigated back to this step), show "Bring to Front"
  if (tutorialPopup) {
    try {
      if (!tutorialPopup.closed) {
        if (btn) {
          btn.textContent = 'Bring Tutorial Window To Front';
          btn.classList.add('pbsg-popup-btn--front');
          btn.onclick = function() { bringPopupToFront(); };
        }
      }
    } catch (e) {}
  }

  // Hide the old fallback bar
  fallback.style.display = 'none';
}

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

const stepEyebrowEl = document.getElementById('pbsgStepEyebrow');
const menuListEl    = document.getElementById('pbsgMenuList');
const menuHeadCurrentEl = menuDd ? menuDd.querySelector('.pbsg-menu-head-current') : null;
const menuHeadTotalEl   = menuDd ? menuDd.querySelector('.pbsg-menu-head-total')   : null;
const menuHeadDoneEl    = menuDd ? menuDd.querySelector('.pbsg-menu-head-done-count') : null;

// Tracks which step indices the user has visited in this session.
// In-memory only — no persistence, no storage, no PII.
const visitedSteps = new Set();


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

/**
 * Returns the shared CSS string injected into every H5P iframe
 * (both main quiz and branch sub-quiz) for consistent typography,
 * button styling, and feedback callout rendering.
 */
function getH5PStyleCSS() {
  return `
          /* ── Base ─────────────────────────────────────────── */
          body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            font-size: 14px !important;
            color: #333 !important;
            background: #fff !important;
            margin: 0 !important;
            box-sizing: border-box !important;
          }

          .h5p-container,
          .h5p-content,
          .h5p-question {
            font-family: inherit !important;
            box-sizing: border-box !important;
            color: #333 !important;
          }

          .h5p-question {
            padding: 18px 20px !important;
            background: #fff !important;
          }

          /* ── "QUESTION" eyebrow + title ──────────────────── */
          .h5p-question-text::before,
          .h5p-question-introduction::before {
            content: 'Question' !important;
            display: block !important;
            font-size: 11px !important;
            font-weight: 600 !important;
            color: #999 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.8px !important;
            margin-bottom: 4px !important;
          }

          .h5p-question-text,
          .h5p-question-introduction {
            font-size: 18px !important;
            font-weight: 700 !important;
            color: #222 !important;
            line-height: 1.3 !important;
            margin: 0 0 16px 0 !important;
            padding: 0 !important;
          }

          /* ── MultiChoice options (default) ─────────────────
             H5P renders the radio/checkbox as a ::before pseudo-element
             on .h5p-alternative-container, positioned via text-indent + padding-left.
             We only override visual properties (colors, border, border-radius) and
             leave H5P's native layout (text-indent, padding, line-height) untouched
             so the radio circle stays aligned with the text. ──── */
          .h5p-alternative-container {
            background: #fff !important;
            border: 1.5px solid #d0d0d0 !important;
            border-radius: 6px !important;
            margin-bottom: 8px !important;
            font-size: 14px !important;
            color: #333 !important;
            cursor: pointer !important;
            transition: border-color 0.15s, background 0.15s !important;
            box-sizing: border-box !important;
          }

          .h5p-alternative-container:hover {
            border-color: #8C2004 !important;
          }

          .h5p-alternative-container .h5p-alternative-inner {
            color: inherit !important;
          }

          /* ── MultiChoice options (wrong state) ───────────── */
          .h5p-alternative-container.h5p-wrong,
          .h5p-alternative-container.h5p-answer-wrong {
            background: #fdeeee !important;
            border: 1.5px solid #8C2004 !important;
            color: #5c1a1a !important;
            font-weight: 500 !important;
          }

          /* ── MultiChoice options (correct state) ─────────── */
          .h5p-alternative-container.h5p-correct,
          .h5p-alternative-container.h5p-answer-correct {
            background: #e8f5e9 !important;
            border: 1.5px solid #517E1B !important;
            color: #1b5e20 !important;
            font-weight: 500 !important;
          }

          /* ── Blanks input field ──────────────────────────── */
          .h5p-blanks .h5p-input-wrapper input[type="text"],
          .h5p-blanks input.h5p-text-input {
            border: none !important;
            border-bottom: 2px solid #8C2004 !important;
            background: #f8f8f8 !important;
            border-radius: 4px 4px 0 0 !important;
            padding: 4px 8px !important;
            font-size: 16px !important;
            color: #222 !important;
            font-weight: 500 !important;
            min-width: 120px !important;
            transition: border-color 0.15s, background 0.15s !important;
          }

          .h5p-blanks .h5p-input-wrapper input[type="text"]:focus {
            outline: none !important;
            background: #fff !important;
            border-bottom-color: #517E1B !important;
          }

          .h5p-blanks .h5p-input-wrapper.h5p-wrong input[type="text"],
          .h5p-blanks .h5p-input-wrapper.h5p-not-filled-out input[type="text"] {
            background: #fdeeee !important;
            border-bottom-color: #8C2004 !important;
            color: #5c1a1a !important;
          }

          .h5p-blanks .h5p-input-wrapper.h5p-correct input[type="text"] {
            background: #e8f5e9 !important;
            border-bottom-color: #517E1B !important;
            color: #1b5e20 !important;
          }

          /* ── Buttons (shared across MultiChoice, Blanks, etc.) ── */
          .h5p-joubelui-button,
          .h5p-question-check-answer {
            min-height: 38px !important;
            padding: 10px 24px !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            background: #8C2004 !important;
            color: #fff !important;
            border: 1.5px solid #8C2004 !important;
            box-shadow: 0 1px 3px rgba(140, 32, 4, 0.3) !important;
            cursor: pointer !important;
            font-family: inherit !important;
            text-transform: none !important;
            transition: background 0.15s, box-shadow 0.15s !important;
          }

          .h5p-joubelui-button:hover,
          .h5p-question-check-answer:hover {
            background: #6f1a03 !important;
            box-shadow: 0 2px 6px rgba(140, 32, 4, 0.4) !important;
            color: #fff !important;
          }

          /* Retry is intentionally slightly smaller than Check (10px 24px) to
             visually signal its secondary/ghost-button status */
          .h5p-question-try-again,
          .h5p-joubelui-button.h5p-question-try-again {
            background: #fff !important;
            color: #8C2004 !important;
            border: 1.5px solid #8C2004 !important;
            box-shadow: none !important;
            padding: 9px 20px !important;
          }

          .h5p-question-try-again:hover,
          .h5p-joubelui-button.h5p-question-try-again:hover {
            background: #fdeeee !important;
            color: #8C2004 !important;
          }

          /* H5P's Question.js truncates the retry button to an icon-only
             2.235em square when it decides the button row is too wide
             (see question.js:674 — it calls $button.html('').addClass('truncated')
             which wipes the label from the DOM). Next to our wide "Show
             solution" button, that truncated retry looks like a broken
             square. Override the size clamp and re-inject the saved label
             via ::after + aria-label (H5P stores the original text there
             at question.js:671 before wiping). */
          .h5p-joubelui-button.truncated {
            width: auto !important;
            height: auto !important;
            border-radius: 6px !important;
          }
          .h5p-question-try-again.truncated::after {
            content: attr(aria-label);
            display: inline;
            font: inherit;
            margin-left: 0;
            vertical-align: baseline;
          }

          /* Button container — push buttons right.
             H5P toggles .h5p-question-visible, .has-scorebar, and .wrap on
             this element, each of which ships its own width/display rules
             (see H5P.Question-1.5/styles/question.css:186-206). Match all
             variants so our right-alignment wins regardless of state. */
          .h5p-question .h5p-question-buttons,
          .h5p-question-buttons.h5p-question-visible,
          .h5p-question-buttons.has-scorebar,
          .h5p-question-buttons.has-scorebar.wrap,
          .h5p-actions,
          .h5p-joubelui-button-container {
            display: flex !important;
            flex-wrap: wrap !important;
            justify-content: flex-end !important;
            align-items: center !important;
            gap: 10px !important;
            margin-top: 14px !important;
            width: auto !important;
            max-width: 100% !important;
          }

          /* When H5P inserts a scorebar sibling inside the buttons container,
             push it to the left so buttons stay right-aligned */
          .h5p-question-buttons .h5p-joubelui-score-bar {
            margin-right: auto !important;
          }

          /* ── Feedback callouts ───────────────────────────── */
          .h5p-feedback {
            font-size: 13px !important;
            font-weight: 400 !important;
            padding: 10px 14px !important;
            margin-top: 14px !important;
            border-radius: 4px !important;
            border-left: 4px solid transparent !important;
            line-height: 1.5 !important;
          }

          .h5p-feedback.h5p-feedback-correct,
          .h5p-question-feedback.h5p-correct {
            background: #e8f5e9 !important;
            border-left-color: #517E1B !important;
            color: #1b5e20 !important;
          }

          .h5p-feedback.h5p-feedback-incorrect,
          .h5p-question-feedback.h5p-wrong {
            background: #fdeeee !important;
            border-left-color: #8C2004 !important;
            color: #5c1a1a !important;
          }

          /* ── Score bar (keep H5P's default green, just resize) ── */
          .h5p-question-score,
          .h5p-joubelui-score-bar {
            font-size: 12px !important;
            color: #666 !important;
            margin-top: 10px !important;
          }
  `;
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
  const isNewLoad = frame.dataset.loadedH5pId !== wantedId;

  if (isNewLoad) {
    frame.style.opacity = '0';
    frame.src = h5pUrl(h5pId);
    frame.dataset.loadedH5pId = wantedId;
  }

  frame.style.display = '';
  // For new loads, keep opacity 0 — attachH5PWatcher fades it in once H5P has rendered.
  // For cached frames the H5P is already rendered so show immediately.
  if (!isNewLoad) {
    frame.style.opacity = '1';
  }
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

  // Record current step as visited (current position is trivially visited).
  if (Number.isFinite(i)) visitedSteps.add(i);

  const items = menuDd.querySelectorAll('.pbsg-menu-item');
  items.forEach(el => {
    const idx = parseInt(el.dataset.stepIndex, 10);
    const isCurrent = idx === i;
    const isFuture  = idx > i;
    const isVisited = visitedSteps.has(idx) && !isCurrent;

    el.classList.toggle('is-current',  isCurrent);
    el.classList.toggle('is-visited',  isVisited);
    el.classList.toggle('is-disabled', inBranch || isFuture);
  });

  // Sticky header counters
  if (menuHeadCurrentEl) menuHeadCurrentEl.textContent = String((i | 0) + 1);
  if (menuHeadTotalEl)   menuHeadTotalEl.textContent   = String(steps.length);
  if (menuHeadDoneEl)    menuHeadDoneEl.textContent    = String(visitedSteps.size);

  // Overflow fade — toggle after layout settles
  if (menuListEl) {
    // rAF so we read scrollHeight after any new class-driven layout change.
    requestAnimationFrame(() => {
      menuListEl.classList.toggle('has-overflow', menuListEl.scrollHeight > menuListEl.clientHeight + 1);
    });
  }
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

        style.textContent = getH5PStyleCSS();
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
    let hasChecked = (attemptCounts[stepIndex] || 0) > 0;

    // Global rule: until an answer is submitted, Next must stay disabled.
    // Exception: if H5P is already showing a correct result (e.g. SingleChoiceSet
    // or TrueFalse which give immediate feedback without a separate Check button),
    // trust the DOM state and count it as one attempt so navigation unlocks.
    if (!hasChecked) {
      if (!correct) {
        lockNext(true);
        updateRunningScore();
        updateCertificateGate();
        return;
      }
      // H5P shows correct without a detectable Check click — record the attempt.
      attemptCounts[stepIndex] = 1;
      hasChecked = true;
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

function formatDurationMs(ms) {
  if (!ms || ms < 0) ms = 0;
  const totalSec = Math.floor(ms / 1000);
  const m = Math.floor(totalSec / 60);
  const s = totalSec % 60;
  return `${m}m ${s.toString().padStart(2, '0')}s`;
}

function updateRunningScore() {
  if (!runningScoreEl) return;

  const correct = passedQuizStepsCount();
  const attempted = attemptedQuizStepsCount();

  // The template pre-renders the "Correct/Attempted" label and the check
  // icon inside both long and short score spans, so we only need to update
  // the numeric value spans. pbsgSetScore keeps the long/short variants in
  // sync.
  pbsgSetScore(correct, attempted);
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

const pbsgTutorialStartedAt = Date.now();

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

  closeTutorialPopup();

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

    // Image inline
    if (mime.startsWith('image/') && !mime.includes('svg')) {
      tutorialStage.innerHTML = `<img src="${t.file_url}" class="pbsg-inline-image" alt="">`;
      fallback.style.display = 'none';
      openLink.href = t.file_url;
      return;
    }

    // Office documents via Google Docs Viewer
    if (t.viewer_url) {
      freshFrame.src = t.viewer_url;
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
    openLink.href = t.url;

    if (t.embeddable !== false) {
      // Tier 1: iframe (embeddable or unknown)
      freshFrame.src = toEmbeddableUrl(t.url);
      fallback.style.display = 'none';
    } else if (t.viewer_url) {
      // Tier 2: Google Docs Viewer (non-embeddable document URL)
      freshFrame.src = t.viewer_url;
      fallback.style.display = 'none';
    } else {
      // Tier 3: popup fallback (non-embeddable, non-document URL)
      closeTutorialPopup();
      renderPopupFallbackCard(tutorialStage, t.url);
    }
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

  if (titleEl) titleEl.textContent = step.title || `Step ${i+1}`;
  if (stepEyebrowEl) stepEyebrowEl.textContent = `Step ${i+1} of ${steps.length}`;
  updateMenuState();
  if (progressEl) pbsgSetProgress(i + 1, steps.length);
  updateRunningScore();

  

  // Bottom progress bar
  const pct = steps.length ? ((i + 1) / steps.length) * 100 : 0;
  if (progressFillEl) progressFillEl.style.width = pct.toFixed(2) + '%';
  if (progressLabelEl) progressLabelEl.textContent = `Page: ${i+1} of ${steps.length}`;

  prevBtn.disabled = i === 0;

  if (step.h5p_id) {
    const hasChecked = (attemptCounts[i] || 0) > 0;
    const alreadyPassed = passedSteps.has(i);

    if (isCurrentStepBlockedByMandatoryBranch()) {
      lockNext(true);
    } else if (!hasChecked && !alreadyPassed) {
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

  if (!q.h5p_id || q.h5p_id <= 0) {
    // Defensive — should never happen post-deployment because save_meta always creates h5p_id
    branchQuizHost.innerHTML = '<div class="pbsg-branch-error">This branch question is not configured. Please re-save the tutorial.</div>';
    lockNext(false);
    return;
  }

  const iframeSrc = h5pUrl(q.h5p_id);

  branchQuizHost.innerHTML = `
    <iframe
      id="pbsgBranchH5PFrame"
      src="${iframeSrc}"
      class="pbsg-branch-h5p-iframe"
      allowfullscreen
      frameborder="0">
    </iframe>
  `;

  // Lock Next until the student passes the H5P question
  lockNext(true);

  const iframe = document.getElementById('pbsgBranchH5PFrame');
  if (iframe) {
    iframe.addEventListener('load', () => {
      injectH5PStyle(iframe);
      attachBranchH5PxAPI(iframe);
    });
  }
}

/**
 * Inject the shared quiz typography/hierarchy CSS into any H5P iframe.
 * Used by both the main quiz path and branch sub-quiz path so both
 * render with the same visual refresh.
 */
function injectH5PStyle(frame) {
  try {
    const doc = frame.contentDocument || frame.contentWindow.document;
    if (!doc || !doc.head) return;
    if (doc.getElementById('pbsg-h5p-style')) return; // already injected

    const style = doc.createElement('style');
    style.id = 'pbsg-h5p-style';
    style.textContent = getH5PStyleCSS();
    doc.head.appendChild(style);
  } catch (e) {
    console.warn('Branch H5P style injection failed:', e);
  }
}

function attachBranchH5PxAPI(iframe) {
  try {
    const win = iframe.contentWindow;
    if (!win || !win.H5P || !win.H5P.externalDispatcher) {
      // H5P not yet ready — retry once after a short delay
      setTimeout(() => attachBranchH5PxAPI(iframe), 200);
      return;
    }

    win.H5P.externalDispatcher.on('xAPI', (event) => {
      const verb = (event && event.data && event.data.statement && event.data.statement.verb && event.data.statement.verb.id) || '';
      const result = event && event.data && event.data.statement && event.data.statement.result;

      // 'answered' verb fires when student clicks Check
      if (verb.endsWith('/answered') && result && result.score) {
        const passed = result.score.scaled >= 1.0;
        if (passed) {
          lockNext(false);
        } else {
          lockNext(true);
        }
      }
    });
  } catch (e) {
    // Cross-origin or H5P-not-ready — fall back to allowing Next so the student isn't stuck
    console.warn('Branch H5P xAPI listener failed:', e);
    lockNext(false);
  }
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

  
  const letter = String.fromCharCode(65 + branchStepIndex); // A, B, C...
  const mainNumber = branchParentIndex + 1;

  // Total tutorial pages — used as the denominator so the student sees their
  // overall position in the tutorial (e.g. "Page: 2A of 10") rather than just
  // their position within the branch detour.
  const totalPages = Array.isArray(steps) ? steps.length : 0;
  const pageText = `Page: ${mainNumber}${letter} of ${totalPages}`;

  if (progressEl) pbsgSetProgress(`${mainNumber}${letter}`, totalPages);
  updateRunningScore();
  if (progressLabelEl) progressLabelEl.textContent = pageText;

  // Progress bar fill based on parent step position in the overall tutorial,
  // not the local branch position. The bar stays at the parent step's position
  // throughout the branch detour so the student sees they haven't lost ground.
  const pct = totalPages ? ((branchParentIndex + 1) / totalPages) * 100 : 0;
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

  // Try to close the tab (only works if the tab was opened via window.open()).
  // Browsers block window.close() on directly-navigated tabs, so fall back to
  // history navigation or the site root if close is ignored.
  window.close();
  setTimeout(() => {
    if (!window.closed) {
      if (window.history.length > 1) {
        window.history.back();
      } else {
        window.location.href = window.location.origin;
      }
    }
  }, 300);
}

retakeBtn.onclick = () => {
  closeTutorialPopup();
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

  if (mainContent) mainContent.style.display = 'none';
  if (summaryScreen) summaryScreen.style.display = '';

  // Duration
  const durationEl = document.getElementById('pbsgSummaryDuration');
  if (durationEl) {
    durationEl.textContent = formatDurationMs(Date.now() - pbsgTutorialStartedAt);
  }

  const totalQ = requiredQuizStepsCount();
  const correctQ = passedQuizStepsCount();

  const wrap = document.getElementById('pbsgObjectivesWrap');
  const list = document.getElementById('pbsgSummaryQuestions');
  const correctHead = document.getElementById('pbsgSummaryCorrect');
  const totalHead = document.getElementById('pbsgSummaryTotal');
  const correctMeta = document.getElementById('pbsgSummaryCorrect2');
  const totalMeta = document.getElementById('pbsgSummaryTotal2');
  const correctItem = document.getElementById('pbsgSummaryCorrectItem');
  const scoreItem = document.getElementById('pbsgSummaryScoreItem');
  const scoreEl = document.getElementById('pbsgSummaryScore');
  const metaSeps = document.querySelectorAll('.pbsg-summary-meta [data-pbsg-meta-sep]');

  if (totalQ > 0) {
    // Build objectives list
    if (list) {
      list.innerHTML = '';
      let qIdx = 0;
      steps.forEach((step, idx) => {
        if (!step.h5p_id) return;
        qIdx += 1;
        const tries = attemptCounts[idx] || 0;
        const passed = passedSteps.has(idx);
        const li = document.createElement('li');
        if (!passed) li.classList.add('is-wrong');

        const labelSpan = document.createElement('span');
        labelSpan.className = 'pbsg-obj-label';
        const strong = document.createElement('strong');
        strong.textContent = `Question ${qIdx}`;
        labelSpan.appendChild(strong);

        const metaSpan = document.createElement('span');
        metaSpan.className = 'pbsg-obj-meta';
        metaSpan.textContent = `${tries} attempt${tries === 1 ? '' : 's'}`;

        li.appendChild(labelSpan);
        li.appendChild(metaSpan);
        list.appendChild(li);
      });
    }

    if (correctHead) correctHead.textContent = String(correctQ);
    if (totalHead) totalHead.textContent = String(totalQ);
    if (correctMeta) correctMeta.textContent = String(correctQ);
    if (totalMeta) totalMeta.textContent = String(totalQ);
    if (scoreEl) scoreEl.textContent = `${getFinalGradePercent()}%`;

    if (wrap) wrap.hidden = false;
    if (correctItem) correctItem.hidden = false;
    if (scoreItem) scoreItem.hidden = false;
    metaSeps.forEach(sep => { sep.hidden = false; });

    // Overflow indicator — measure after layout settles
    if (wrap && list) {
      requestAnimationFrame(() => {
        if (list.scrollHeight > list.clientHeight + 1) {
          wrap.classList.add('has-overflow');
        } else {
          wrap.classList.remove('has-overflow');
        }
      });
    }
  } else {
    // No quizzes — keep objectives + correct/score hidden, leave duration visible
    if (wrap) wrap.hidden = true;
    if (correctItem) correctItem.hidden = true;
    if (scoreItem) scoreItem.hidden = true;
    metaSeps.forEach(sep => { sep.hidden = true; });
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