<?php
/**
 * WordPress Multisite Configuration Snippet
 *
 * These constants are REQUIRED for Pressbooks to function. Pressbooks only
 * works correctly in a WordPress Multisite environment where each "book"
 * is its own site in the network.
 *
 * ─── How This File Is Used ─────────────────────────────────────────────────
 *
 * Option A (automated):
 *   The setup.sh script injects these constants into wp-config.php
 *   automatically. You don't need to do anything manually.
 *
 * Option B (manual):
 *   If setup.sh fails or you're setting up without Docker, copy the
 *   constants below into your wp-config.php ABOVE the line:
 *     /* That's all, stop editing! Happy publishing. */
 *
 * ─── Prerequisites ─────────────────────────────────────────────────────────
 *
 * Before adding these constants, you must:
 *   1. Install WordPress (single site) and complete the setup wizard
 *   2. Log into wp-admin
 *   3. Add   define('WP_ALLOW_MULTISITE', true);   to wp-config.php
 *   4. Go to Tools → Network Setup and click "Install"
 *   5. WordPress will generate the constants below — replace the domain
 *      value with your actual domain if not localhost
 *   6. THEN add the constants below and the .htaccess rules
 *
 * ─── Important Notes ───────────────────────────────────────────────────────
 *
 * - SUBDOMAIN_INSTALL is false because sub-directory mode is simpler for
 *   local development (no wildcard DNS needed). Production may differ.
 *
 * - DOMAIN_CURRENT_SITE must match your actual hostname. For Docker local
 *   dev this is 'localhost'. For production, change to your real domain.
 *
 * - These constants must appear BEFORE the "stop editing" comment in
 *   wp-config.php but AFTER the database credentials and salts.
 *
 * @see https://developer.wordpress.org/advanced-administration/multisite/create-network/
 */

/* ─── Multisite Constants ─── paste into wp-config.php ─────────────────── */

define( 'WP_ALLOW_MULTISITE', true );

define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', false );
define( 'DOMAIN_CURRENT_SITE', 'localhost' );
define( 'PATH_CURRENT_SITE', '/' );
define( 'SITE_ID_CURRENT_SITE', 1 );
define( 'BLOG_ID_CURRENT_SITE', 1 );

/* ─── Optional: Pressbooks-specific overrides ──────────────────────────── */

/**
 * Increase memory limits. Pressbooks can be memory-hungry during
 * exports (PDF, EPUB) and when rendering large books.
 */
define( 'WP_MEMORY_LIMIT', '256M' );
define( 'WP_MAX_MEMORY_LIMIT', '512M' );

/**
 * Allow unfiltered uploads for network admins.
 * Pressbooks needs this for certain file types during import/export.
 * Only enable in trusted environments.
 */
define( 'ALLOW_UNFILTERED_UPLOADS', true );

/**
 * Disable automatic updates in local dev to avoid Docker volume conflicts.
 * Re-enable for production.
 */
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );