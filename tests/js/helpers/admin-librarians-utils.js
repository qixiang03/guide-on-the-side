/**
 * Email validation helper for tests. Logic must match
 * web/app/plugins/pb-split-guide/assets/admin/admin-librarians.js (isValidEmail).
 *
 * @param {string} email
 * @returns {boolean}
 */
function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

module.exports = { isValidEmail };
