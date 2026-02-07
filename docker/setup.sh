#!/usr/bin/env bash
###############################################################################
# Guide on the Side — First-Time Pressbooks Setup
#
# Run once after `docker compose up -d`:
#   docker compose exec wordpress bash /var/www/html/setup.sh
#
# What it does:
#   1. Installs WP-CLI inside the container
#   2. Installs WordPress as a single site
#   3. Converts to Multisite + injects constants into wp-config.php
#   4. Installs Pressbooks core plugin + Book theme
#   5. Activates the Guide on the Side plugin
###############################################################################
set -euo pipefail

WP="/usr/local/bin/wp --allow-root"
SITE_URL="http://localhost:${WP_PORT:-8080}"
WP_CONFIG="/var/www/html/wp-config.php"

echo "============================================"
echo "  Guide on the Side — Pressbooks Setup"
echo "============================================"
echo ""

# ── Step 1: Install WP-CLI ──────────────────────────────────────────────
if ! command -v wp &>/dev/null; then
  echo "[1/6] Installing WP-CLI..."
  curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
  chmod +x wp-cli.phar
  mv wp-cli.phar /usr/local/bin/wp
else
  echo "[1/6] WP-CLI already installed."
fi

# ── Step 2: Install WordPress (single site first) ───────────────────────
if ! $WP core is-installed 2>/dev/null; then
  echo "[2/6] Installing WordPress..."
  $WP core install \
    --url="$SITE_URL" \
    --title="Guide on the Side — Dev" \
    --admin_user="${WP_ADMIN_USER:-admin}" \
    --admin_password="${WP_ADMIN_PASSWORD:-admin}" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@pressbooks.test}" \
    --skip-email
  echo "       ✓ WordPress installed."
else
  echo "[2/6] WordPress already installed."
fi

# ── Step 3: Convert to Multisite ────────────────────────────────────────
#    We check for the MULTISITE constant in wp-config.php to know if
#    the conversion + constant injection has already happened.
if ! grep -q "define.*'MULTISITE'.*true" "$WP_CONFIG" 2>/dev/null; then
  echo "[3/6] Converting to Multisite..."

  # Run the conversion (creates wp_blogs, wp_site, wp_sitemeta tables)
  $WP core multisite-convert --title="Pressbooks Network" 2>/dev/null || true

  # Now inject the Multisite constants into wp-config.php.
  # We insert them right BEFORE the "That's all, stop editing!" line
  # that WordPress puts near the bottom of wp-config.php.
  MARKER="/* That's all, stop editing! Happy publishing. */"

  # Check if the marker exists
  if grep -qF "$MARKER" "$WP_CONFIG"; then
    sed -i "/$MARKER/i\\
/* ----- Multisite constants (added by setup.sh) ----- */\\
define('MULTISITE', true);\\
define('SUBDOMAIN_INSTALL', false);\\
define('DOMAIN_CURRENT_SITE', 'localhost');\\
define('PATH_CURRENT_SITE', '/');\\
define('SITE_ID_CURRENT_SITE', 1);\\
define('BLOG_ID_CURRENT_SITE', 1);\\
/* ----- End Multisite constants ----- */" "$WP_CONFIG"
    echo "       ✓ Multisite constants added to wp-config.php."
  else
    # Fallback: append to the end of the extra config section
    echo "" >> "$WP_CONFIG"
    echo "/* ----- Multisite constants (added by setup.sh) ----- */" >> "$WP_CONFIG"
    echo "define('MULTISITE', true);" >> "$WP_CONFIG"
    echo "define('SUBDOMAIN_INSTALL', false);" >> "$WP_CONFIG"
    echo "define('DOMAIN_CURRENT_SITE', 'localhost');" >> "$WP_CONFIG"
    echo "define('PATH_CURRENT_SITE', '/');" >> "$WP_CONFIG"
    echo "define('SITE_ID_CURRENT_SITE', 1);" >> "$WP_CONFIG"
    echo "define('BLOG_ID_CURRENT_SITE', 1);" >> "$WP_CONFIG"
    echo "/* ----- End Multisite constants ----- */" >> "$WP_CONFIG"
    echo "       ✓ Multisite constants appended to wp-config.php (fallback)."
  fi

  echo "       ✓ Multisite enabled."
else
  echo "[3/6] Multisite already configured."
fi

# Quick sanity check — can WP-CLI see the network now?
if ! $WP core is-installed --network 2>/dev/null; then
  echo ""
  echo "  ⚠  Multisite tables exist but WP-CLI can't see the network."
  echo "     This is usually fine — continue and it should resolve."
  echo ""
fi

# ── Step 4: Install Pressbooks plugin ───────────────────────────────────
if ! $WP plugin is-installed pressbooks 2>/dev/null; then
  echo "[4/6] Installing Pressbooks plugin..."
  $WP plugin install pressbooks --activate-network 2>/dev/null || \
  $WP plugin install pressbooks --activate 2>/dev/null || {
    echo "       ⚠  Could not auto-install Pressbooks."
    echo "       Install manually: WP Admin → Plugins → Add New → search 'Pressbooks'"
  }
else
  echo "[4/6] Pressbooks already installed."
  $WP plugin activate pressbooks --network 2>/dev/null || \
  $WP plugin activate pressbooks 2>/dev/null || true
fi

# ── Step 5: Install Pressbooks Book theme ───────────────────────────────
if ! $WP theme is-installed pressbooks-book 2>/dev/null; then
  echo "[5/6] Installing Pressbooks Book theme..."
  $WP theme install pressbooks-book --activate 2>/dev/null || {
    echo "       ⚠  Could not auto-install Pressbooks Book theme."
    echo "       Install manually: WP Admin → Appearance → Themes → Add New"
  }
else
  echo "[5/6] Pressbooks Book theme already installed."
fi

# ── Step 6: Activate Guide on the Side plugin ───────────────────────────
if $WP plugin is-installed pb-split-guide 2>/dev/null; then
  echo "[6/6] Activating Guide on the Side plugin..."
  $WP plugin activate pb-split-guide --network 2>/dev/null || \
  $WP plugin activate pb-split-guide 2>/dev/null || true
  echo "       ✓ Plugin activated."
else
  echo "[6/6] ⚠  pb-split-guide not found in /wp-content/plugins/."
  echo "       Make sure plugin/pb-split-guide/ exists in your repo."
fi

# ── Flush rewrite rules ─────────────────────────────────────────────────
$WP rewrite flush --hard 2>/dev/null || true

echo ""
echo "============================================"
echo "  ✅  Setup complete!"
echo ""
echo "  WordPress:  $SITE_URL"
echo "  Admin:      $SITE_URL/wp-admin/"
echo "  phpMyAdmin: http://localhost:${PMA_PORT:-8081}"
echo ""
echo "  Login:  ${WP_ADMIN_USER:-admin} / ${WP_ADMIN_PASSWORD:-admin}"
echo "============================================"
echo ""
echo "  If this is your first run, refresh localhost:${WP_PORT:-8080}"
echo "  and you should see the WordPress login page."
echo ""