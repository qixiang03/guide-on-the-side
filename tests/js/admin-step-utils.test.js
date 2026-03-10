/**
 * Unit tests for admin step utilities (escapeHtml, safeParseJson, normalizeStep, tutorialSummary).
 * Implementation under test: tests/js/helpers/admin-step-utils.js (mirrors admin-split-guide.js)
 */

const { escapeHtml, safeParseJson, normalizeStep, tutorialSummary } = require('./helpers/admin-step-utils');

describe('escapeHtml', () => {
  it('escapes ampersand, angle brackets, quotes', () => {
    expect(escapeHtml('a & b')).toBe('a &amp; b');
    expect(escapeHtml('<script>')).toBe('&lt;script&gt;');
    expect(escapeHtml('"double"')).toBe('&quot;double&quot;');
    expect(escapeHtml("'single'")).toBe('&#039;single&#039;');
  });
  it('returns empty string for null/undefined', () => {
    expect(escapeHtml(null)).toBe('');
    expect(escapeHtml(undefined)).toBe('');
  });
  it('leaves safe text unchanged', () => {
    expect(escapeHtml('Hello World')).toBe('Hello World');
  });
});

describe('safeParseJson', () => {
  it('parses valid JSON array', () => {
    expect(safeParseJson('[1,2,3]')).toEqual([1, 2, 3]);
    expect(safeParseJson('[]')).toEqual([]);
  });
  it('returns fallback for non-array JSON', () => {
    expect(safeParseJson('{}', [])).toEqual([]);
    expect(safeParseJson('null', [])).toEqual([]);
  });
  it('returns fallback on parse error', () => {
    expect(safeParseJson('invalid', [])).toEqual([]);
    expect(safeParseJson('', [])).toEqual([]);
  });
  it('returns fallback for null/undefined text', () => {
    expect(safeParseJson(null, [])).toEqual([]);
    expect(safeParseJson(undefined, [])).toEqual([]);
  });
});

describe('normalizeStep', () => {
  it('migrates legacy url to tutorial_type url and tutorial_url', () => {
    const out = normalizeStep({ url: 'https://example.com' });
    expect(out.tutorial_type).toBe('url');
    expect(out.tutorial_url).toBe('https://example.com');
  });
  it('fills missing tutorial_url and attachment fields', () => {
    const out = normalizeStep({ tutorial_type: 'url' });
    expect(out.tutorial_url).toBe('');
    expect(out.tutorial_attachment_id).toBe(0);
    expect(out.tutorial_file_name).toBe('');
    expect(out.tutorial_file_url).toBe('');
  });
  it('handles null/undefined step', () => {
    const out = normalizeStep(null);
    expect(out.tutorial_url).toBe('');
    expect(out.tutorial_attachment_id).toBe(0);
    expect(out.tutorial_file_name).toBe('');
    expect(out.tutorial_file_url).toBe('');
  });
});

describe('tutorialSummary', () => {
  it('returns URL for url type', () => {
    expect(tutorialSummary({ tutorial_type: 'url', tutorial_url: 'https://a.ca' })).toBe('https://a.ca');
  });
  it('returns file label for file type with name', () => {
    expect(tutorialSummary({ tutorial_type: 'file', tutorial_file_name: 'guide.pdf' })).toBe('File: guide.pdf');
  });
  it('returns attachment id when no file name', () => {
    expect(tutorialSummary({ tutorial_type: 'file', tutorial_attachment_id: 42 })).toBe('File: Attachment #42');
  });
  it('returns "No tutorial selected" when empty or no source', () => {
    expect(tutorialSummary({})).toBe('No tutorial selected');
    expect(tutorialSummary({ tutorial_type: 'url', tutorial_url: '' })).toBe('No tutorial selected');
  });
});
