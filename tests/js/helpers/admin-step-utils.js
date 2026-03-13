/**
 * Pure functions extracted from admin-split-guide.js for unit testing.
 * Logic must stay in sync with web/app/plugins/pb-split-guide/assets/admin-split-guide.js
 */

function escapeHtml(str) {
  return String(str || '').replace(/[&<>"']/g, function (m) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]);
  });
}

function safeParseJson(text, fallback) {
  try {
    const v = JSON.parse(text || '');
    return (v && Array.isArray(v)) ? v : (fallback || []);
  } catch (e) {
    return fallback || [];
  }
}

function normalizeStep(s) {
  const out = Object.assign({}, s || {});
  if (!out.tutorial_type && out.url) {
    out.tutorial_type = 'url';
    out.tutorial_url = out.url;
  }
  if (!out.tutorial_url) out.tutorial_url = '';
  if (!out.tutorial_attachment_id) out.tutorial_attachment_id = 0;
  if (!out.tutorial_file_name) out.tutorial_file_name = '';
  if (!out.tutorial_file_url) out.tutorial_file_url = '';
  return out;
}

function tutorialSummary(s) {
  s = normalizeStep(s);
  if (s.tutorial_type === 'url' && s.tutorial_url) return `${s.tutorial_url}`;
  if (s.tutorial_type === 'file' && (s.tutorial_file_name || s.tutorial_attachment_id)) {
    const name = s.tutorial_file_name ? s.tutorial_file_name : `Attachment #${s.tutorial_attachment_id}`;
    return `File: ${name}`;
  }
  return 'No tutorial selected';
}

module.exports = { escapeHtml, safeParseJson, normalizeStep, tutorialSummary };
