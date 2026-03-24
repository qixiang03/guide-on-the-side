# H5P Plugin Troubleshooting & Fix Documentation

**Author:** Daniel McGrath (Tech Lead)
**Sprint:** 6

> **Quick Reference (Sprint 7):** For a condensed step-by-step setup command list (including the pressbooks-book theme composer step discovered in Sprint 7), see `docs/H5P-LOCAL-DEV-SETUP.md`.

---

## Prerequisites — Install / Update H5P via Composer

H5P is managed as a Composer dependency in this project. Before any of the fixes below will work, the plugin files must actually be present.

**On the staging server (or any Lando environment):**
```bash
cd /var/www/guide-on-the-side   # or your local project root
lando composer install
```

This installs (or updates) the H5P plugin into `web/app/plugins/h5p/`. If the plugin directory is missing entirely, this is the first step to run.

After installing, activate the plugin so WordPress recognizes it:
```bash
lando wp --url=https://pressbooks.test/wp/ plugin activate h5p
```

> **Note:** If you updated H5P to a newer version via `composer update`, the DB migration runs automatically on next page load after activation.

---

## Problem Summary

H5P plugin on the staging server showed two symptoms:

1. **"Failed to load data"** — when visiting the H5P admin page (`/wp/wp-admin/admin.php?page=h5p`) or trying to add H5P content to a book
2. **"Last update: Thursday, January 1, 1970 00:00:00"** — on the H5P Content Type Cache page

---

## Root Cause Analysis

Three separate issues were found and fixed.

---

### Issue 1 — H5P Hub Disabled for Sub-site (blog_id=39)

**What happened:**
The site is a WordPress multisite. Site 39 (`/development/`) had `h5p_hub_is_enabled` set to an empty string in `wp_39_options`. H5P's `isHubOn()` function treats an empty string as false, which causes it to immediately return a `403 HUB_DISABLED` error on every admin AJAX call. jQuery receives a 403 and shows "Failed to load data."

**Code path:**
```
admin-ajax.php?action=h5p_content-type-cache
  → ajax_content_type_cache()
  → H5PEditorAjax::action(CONTENT_TYPE_CACHE)
  → isHubOn() checks get_option('h5p_hub_is_enabled')
  → returns "" (empty) → treated as false → 403 HUB_DISABLED → "Failed to load data"
```

**Fix:**
```bash
lando wp --url=https://pressbooks.test/development/ option update h5p_hub_is_enabled 1
lando wp --url=https://pressbooks.test/development/ option update h5p_send_usage_statistics 1
lando wp --url=https://pressbooks.test/development/ option update h5p_has_request_user_consent 1
```

---

### Issue 2 — Empty Content Type Cache Table

**What happened:**
The `wp_h5p_libraries_hub_cache` database table (shared across all sites via `$wpdb->base_prefix`) had 0 rows. This table stores the list of available H5P content types fetched from the H5P Hub. Without it, H5P has no content types to display even if the hub is enabled.

The "Last update: 1970" display came from `h5p_content_type_cache_updated_at` being missing from `wp_sitemeta` (the network-wide options table). It hadn't been updated since February 2020.

**Fix — manually trigger cache refresh via WP-CLI:**
```bash
lando wp --url=https://pressbooks.test/wp/ eval '
  $plugin = H5P_Plugin::get_instance();
  $core = $plugin->get_h5p_instance("core");
  $result = $core->updateContentTypeCache();
  echo (bool)$result ? "SUCCESS" : "FAILED";
'
```

This populated the table with 53 content types and updated `wp_sitemeta.h5p_content_type_cache_updated_at` to the current timestamp.

**Verify:**
```bash
lando ssh -s database -c "mysql -u pressbooks_oss_user -psecretpassword pressbooks_oss -e '
  SELECT COUNT(*) FROM wp_h5p_libraries_hub_cache;
  SELECT meta_key, meta_value FROM wp_sitemeta WHERE meta_key = \"h5p_content_type_cache_updated_at\";
'"
```

Expected: 53+ rows, timestamp = today (Unix epoch, not 0).

---

### Issue 3 — Site 39 Had No H5P UUID (Prevented Future Cache Refreshes)

**What happened:**
When H5P needs to refresh its content type cache, it first checks if the site has a UUID registered with the H5P Hub (`hub-api.h5p.org/v1/sites`). Site 39 had no UUID, so it tried to register a new one. **The H5P Hub registration endpoint currently returns a broken 302 redirect to `/` → 404.** This is a bug on H5P's side.

Without a UUID, H5P can't complete a cache refresh from site 39's context. In 1 week (when the cache expires), refreshes from site 39 would fail and the cache would show "outdated."

**Fix — copy site 1's UUID to site 39:**
```bash
lando wp --url=https://pressbooks.test/development/ option add h5p_h5p_site_uuid 575494e6-7409-47ce-a3e9-3a2279aca75e
```

After adding the UUID, re-test that the cache update works from site 39:
```bash
lando wp --url=https://pressbooks.test/development/ eval '
  $plugin = H5P_Plugin::get_instance();
  $core = $plugin->get_h5p_instance("core");
  $result = $core->updateContentTypeCache();
  echo (bool)$result ? "SUCCESS" : "FAILED";
'
```

Expected output: `SUCCESS`

---

### Issue 4 — nginx Sub-filter Missing JSON-escaped URLs (HTTPS → ERR_CONNECTION_REFUSED)

**What happened:**
This was the cause of AJAX calls going to `https://137.149.157.198/...` instead of `http://137.149.157.198/...`, resulting in `ERR_CONNECTION_REFUSED` (port 443 is not open externally).

WordPress embeds admin URLs as inline JavaScript variables using PHP's `json_encode()`, which escapes forward slashes:
```html
<script>
var H5PAdminIntegration = {"ajaxPath":"https:\/\/pressbooks.test\/wp\/wp-admin\/admin-ajax.php"};
</script>
```

The old nginx `sub_filter` rules only matched literal `https://pressbooks.test` (with unescaped slashes). They did **not** match `https:\/\/pressbooks.test`. The fallback rule `sub_filter 'pressbooks.test' '137.149.157.198'` then matched and replaced only the hostname, leaving the `https://` prefix intact. The browser then tried to reach `https://137.149.157.198/...` on port 443, which is blocked.

**Affected file:** `/etc/nginx/sites-available/pressbooks-proxy`

**Before:**
```nginx
sub_filter 'https://pressbooks.test' 'http://137.149.157.198';
sub_filter 'http://pressbooks.test' 'http://137.149.157.198';
sub_filter 'pressbooks.test' '137.149.157.198';
```

**After (add the two JSON-escaped lines first):**
```nginx
sub_filter 'https:\/\/pressbooks.test' 'http:\/\/137.149.157.198';
sub_filter 'http:\/\/pressbooks.test' 'http:\/\/137.149.157.198';
sub_filter 'https://pressbooks.test' 'http://137.149.157.198';
sub_filter 'http://pressbooks.test' 'http://137.149.157.198';
sub_filter 'pressbooks.test' '137.149.157.198';
```

**Apply the fix:**
```bash
sudo cp /etc/nginx/sites-available/pressbooks-proxy /etc/nginx/sites-available/pressbooks-proxy.bak
sudo nano /etc/nginx/sites-available/pressbooks-proxy
# Edit as above, then:
sudo nginx -t
sudo systemctl reload nginx
```

---

## Full Current nginx Config

File: `/etc/nginx/sites-available/pressbooks-proxy`

```nginx
server {
    listen 192.168.0.198:80;
    server_name 137.149.157.198;

    location / {
        proxy_pass https://127.0.0.1:443;
        proxy_ssl_verify off;
        proxy_set_header Host pressbooks.test;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Accept-Encoding "";

        # Rewrite Location headers
        proxy_redirect https://pressbooks.test/ http://137.149.157.198/;
        proxy_redirect http://pressbooks.test/ http://137.149.157.198/;

        # Rewrite cookie domains and strip secure flag
        proxy_cookie_domain pressbooks.test 137.149.157.198;
        proxy_cookie_flags ~ nosecure;

        # Rewrite URLs in response body (plain and JSON-escaped variants)
        sub_filter 'https:\/\/pressbooks.test' 'http:\/\/137.149.157.198';
        sub_filter 'http:\/\/pressbooks.test' 'http:\/\/137.149.157.198';
        sub_filter 'https://pressbooks.test' 'http://137.149.157.198';
        sub_filter 'http://pressbooks.test' 'http://137.149.157.198';
        sub_filter 'pressbooks.test' '137.149.157.198';
        sub_filter_once off;
        sub_filter_types text/html text/css text/javascript application/javascript application/json;
    }
}
```

---

## Why This Server Uses HTTP While WordPress Uses HTTPS

The staging server architecture is:

```
Browser (http://137.149.157.198:80)
    ↓ nginx reverse proxy
Traefik (https://127.0.0.1:443)  ← Lando, localhost only, self-signed cert
    ↓
WordPress/Pressbooks (thinks it's at https://pressbooks.test)
```

- WordPress is configured for `https://pressbooks.test` (stored in database)
- Traefik uses a self-signed certificate — only accessible on `localhost`
- nginx proxies external requests to Traefik and rewrites all `pressbooks.test` URLs in responses to `137.149.157.198`
- Browsers access the site over plain HTTP on port 80

**⚠️ Do NOT run `wp search-replace` on the database** to change the URLs. nginx handles all rewriting at the proxy layer.

---

## Teammate Local Dev Environments (Lando Only)

Teammates running `lando` directly (without the nginx proxy layer) may encounter the H5P hub/cache issues independently. Their browsers access the site at `https://pressbooks.test` directly through Lando, so the nginx JSON-escaping fix does not apply to them.

However, they may still need the database fixes for H5P (Issues 1–3 above) if working from a fresh database import. Run these commands from the project directory:

```bash
# From /var/www/guide-on-the-side (or your local equivalent)

# 0. Install H5P plugin if not already present
lando composer install
lando wp --url=https://pressbooks.test/wp/ plugin activate h5p
lando wp --url=https://pressbooks.test/wp/ plugin activate pb-split-guide

# Fix hub settings for sub-site (blog_id=39 / /development/)
# Note: --skip-themes --skip-plugins is required here because the McLuhan theme
# on the /development/ sub-site requires its own composer install and will error
# otherwise. It is safe to use for these option updates.
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_hub_is_enabled 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_send_usage_statistics 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_has_request_user_consent 1

# Add UUID so cache refresh works (copy from site 1)
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option add h5p_h5p_site_uuid 575494e6-7409-47ce-a3e9-3a2279aca75e

# Populate the content type cache
lando wp --url=https://pressbooks.test/wp/ eval '
  $plugin = H5P_Plugin::get_instance();
  $core = $plugin->get_h5p_instance("core");
  $result = $core->updateContentTypeCache();
  echo (bool)$result ? "SUCCESS" : "FAILED";
'

# Flush object cache
lando wp cache flush
```

> **Note:** The H5P Hub registration endpoint (`hub-api.h5p.org/v1/sites`) is currently broken and returns a 302 redirect loop. This is an H5P upstream issue. Workaround is to reuse the site UUID from site 1 as shown above.

---

## Verification Checklist

After applying all fixes, verify:

- [ ] H5P admin page loads without "Failed to load data"
- [ ] Content Type Cache page shows today's date (not 1970)
- [ ] Can browse H5P content types when adding content to a book
- [ ] Browser console shows no `ERR_CONNECTION_REFUSED` for `admin-ajax.php`
- [ ] DB: `wp_h5p_libraries_hub_cache` has 50+ rows
- [ ] DB: `wp_sitemeta` has a recent `h5p_content_type_cache_updated_at` value

---

## AI Disclosure

This troubleshooting session and documentation were completed with assistance from Claude Code (Anthropic). Per course policy, all AI-assisted work must be disclosed.
