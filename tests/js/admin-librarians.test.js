/**
 * Unit tests for Manage Librarians admin JS behavior.
 * Email validation logic under test mirrors admin-librarians.js (isValidEmail).
 */

const { isValidEmail } = require('./helpers/admin-librarians-utils');

describe('admin-librarians email validation', () => {
  it('accepts valid email addresses', () => {
    expect(isValidEmail('user@example.com')).toBe(true);
    expect(isValidEmail('librarian@upei.ca')).toBe(true);
    expect(isValidEmail('a+b@sub.domain.org')).toBe(true);
  });

  it('rejects empty or missing @', () => {
    expect(isValidEmail('')).toBe(false);
    expect(isValidEmail('userexample.com')).toBe(false);
  });

  it('rejects missing domain or TLD', () => {
    expect(isValidEmail('user@')).toBe(false);
    expect(isValidEmail('user@domain')).toBe(false);
    expect(isValidEmail('@domain.com')).toBe(false);
  });

  it('rejects invalid formats', () => {
    expect(isValidEmail('user @example.com')).toBe(false);
    expect(isValidEmail('user@example .com')).toBe(false);
    expect(isValidEmail('javascript:alert(1)')).toBe(false);
  });
});

