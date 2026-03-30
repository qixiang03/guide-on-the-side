/**
 * Menu open/close helpers — keep aligned with split-guide.js (quiz pane step menu).
 */
function openMenu(menuDd, menuBtn) {
  if (!menuDd || !menuBtn) return;
  menuDd.classList.add('is-open');
  menuBtn.setAttribute('aria-expanded', 'true');
}

function closeMenu(menuDd, menuBtn) {
  if (!menuDd || !menuBtn) return;
  menuDd.classList.remove('is-open');
  menuBtn.setAttribute('aria-expanded', 'false');
}

describe('split-guide menu a11y state', () => {
  test('openMenu toggles class and aria-expanded', () => {
    document.body.innerHTML =
      '<div id="dd"></div><button id="btn" aria-expanded="false"></button>';
    const menuDd = document.getElementById('dd');
    const menuBtn = document.getElementById('btn');

    openMenu(menuDd, menuBtn);

    expect(menuDd.classList.contains('is-open')).toBe(true);
    expect(menuBtn.getAttribute('aria-expanded')).toBe('true');
  });

  test('closeMenu clears open state', () => {
    document.body.innerHTML =
      '<div id="dd" class="is-open"></div><button id="btn" aria-expanded="true"></button>';
    const menuDd = document.getElementById('dd');
    const menuBtn = document.getElementById('btn');

    closeMenu(menuDd, menuBtn);

    expect(menuDd.classList.contains('is-open')).toBe(false);
    expect(menuBtn.getAttribute('aria-expanded')).toBe('false');
  });

  test('no-ops when nodes missing', () => {
    expect(() => openMenu(null, null)).not.toThrow();
    expect(() => closeMenu(null, null)).not.toThrow();
  });
});
