# H5P Local Dev Setup — Quick Reference

**Author:** Daniel McGrath (Tech Lead)
**Relates to:** Sprint 6 fix (originally documented in H5P-TROUBLESHOOTING.md)

This is the condensed version for teammates setting up a fresh local Lando environment.
See `docs/H5P-TROUBLESHOOTING.md` for full root cause analysis.

---

## Run Once After `lando start` on a Fresh DB Import

```bash
# From /var/www/guide-on-the-side (inside lando) or project root (outside)

# 1. Install H5P plugin if missing
lando composer install
lando wp --url=https://pressbooks.test/wp/ plugin activate h5p
lando wp --url=https://pressbooks.test/wp/ plugin activate pb-split-guide

# 2. Install pressbooks-book theme dependencies (required for /development/ subsite)
lando composer install --working-dir=web/app/themes/pressbooks-book

# 3. Fix H5P hub settings for the /development/ subsite
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_hub_is_enabled 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_send_usage_statistics 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_has_request_user_consent 1

# 4. Add site UUID so cache refresh works (reuses site 1's UUID as workaround —
#    H5P Hub registration endpoint is currently broken upstream)
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option add h5p_h5p_site_uuid 575494e6-7409-47ce-a3e9-3a2279aca75e

# 5. Populate content type cache
lando wp --url=https://pressbooks.test/wp/ eval '
  $plugin = H5P_Plugin::get_instance();
  $core = $plugin->get_h5p_instance("core");
  $result = $core->updateContentTypeCache();
  echo (bool)$result ? "SUCCESS" : "FAILED";
'

# 6. Flush object cache
lando wp cache flush
```

---

## Important

- **Always use `pressbooks.test/development/wp-admin/`** for tutorial editing, NOT `pressbooks.test/wp/wp-admin/`. The `/wp/` root site is Pressbooks's network hub — it strips non-Pressbooks admin scripts and metaboxes.
- The `--skip-themes --skip-plugins` flags on step 3–4 are required because the McLuhan/pressbooks-book theme errors without its composer deps. Steps 3–4 only update DB options so skipping is safe.
- The H5P Hub UUID workaround (step 4) is needed because `hub-api.h5p.org/v1/sites` returns a broken 302 redirect. This is an upstream H5P bug.

---

## Verification

```bash
lando ssh -s database -c "mysql -u pressbooks_oss_user -psecretpassword pressbooks_oss -e '
  SELECT COUNT(*) FROM wp_h5p_libraries_hub_cache;
  SELECT meta_key, meta_value FROM wp_sitemeta WHERE meta_key = \"h5p_content_type_cache_updated_at\";
'"
```

Expected: 50+ rows, timestamp = recent Unix epoch (not 0).

---

## AI Disclosure

This documentation was completed with assistance from Claude Code (Anthropic). Per course policy, all AI-assisted work is disclosed.
