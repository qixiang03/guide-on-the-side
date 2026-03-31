# Server Deployment Fix — pb-split-guide Template Picker Methods

**Author:** Daniel McGrath (Tech Lead)
**Date:** 2026-03-31
**Sprint:** Sprint 8

---

## Problem Summary

After `lando destroy` + fresh start on `VirtualProjectServer08` (`137.149.157.198`), the development admin at `http://137.149.157.198/development/wp-admin/` returned **HTTP 500** even after correct plugin activation.

---

## Root Cause

Enzo's merge commit `8f14a70` (merged 2026-03-24) accidentally deleted six method implementations from `web/app/plugins/pb-split-guide/pb-split-guide.php` during conflict resolution, while leaving their `add_action()` hook registrations intact in `__construct()`.

**Fatal error from `/var/www/guide-on-the-side/web/app/debug.log`:**

```
PHP Fatal error: Uncaught TypeError: call_user_func_array(): Argument #1 ($callback)
must be a valid callback, class PB_Split_Guide_Plugin does not have a method
"register_template_picker_page" in /app/web/wp/wp-includes/class-wp-hook.php:341
```

The hooks registered but the method bodies were gone:

| Hook | Missing Method |
|------|---------------|
| `admin_menu` | `register_template_picker_page` |
| `load-post-new.php` | `maybe_redirect_to_template_picker` |
| `wp_ajax_pbsg_get_templates` | `ajax_get_templates` |
| `wp_ajax_pbsg_save_as_template` | `ajax_save_as_template` |
| `wp_ajax_pbsg_create_from_template` | `ajax_create_from_template` |
| (called by above) | `render_template_picker_page` |

The last known-good commit containing all six method bodies:
```
c235882 feat(authoring): add template picker and export/import (Sprint 7 SG3 & SG4)
```

---

## Fix Applied (Server-Side)

The fix was applied directly to the server at `/var/www/guide-on-the-side/`. **This does NOT affect the local git repo** — a proper fix for the develop branch should cherry-pick or re-apply these methods.

### Step 1 — Extract methods from last good commit

```bash
cd /var/www/guide-on-the-side

git show c235882:web/app/plugins/pb-split-guide/pb-split-guide.php \
  | sed -n '671,751p' > /tmp/picker_methods.php
```

Verify: `wc -l /tmp/picker_methods.php` should show 81 lines.

### Step 2 — Insert methods into the live file

The class closing brace in the current file was at line 1698. The script inserts the methods just before it:

```bash
cat > /tmp/patch_plugin.py << 'ENDOFSCRIPT'
with open('/var/www/guide-on-the-side/web/app/plugins/pb-split-guide/pb-split-guide.php', 'r') as f:
    lines = f.readlines()
with open('/tmp/picker_methods.php', 'r') as f:
    methods = f.read()
insert_at = 1697
insert_block = '\n  # Template Picker (restored from c235882)\n\n' + methods + '\n'
lines.insert(insert_at, insert_block)
with open('/var/www/guide-on-the-side/web/app/plugins/pb-split-guide/pb-split-guide.php', 'w') as f:
    f.writelines(lines)
print('Done. Lines:', len(lines))
ENDOFSCRIPT

python3 /tmp/patch_plugin.py
```

Expected output: `Done. Lines: 1712`

### Step 3 — Verify

```bash
# All 6 methods should now appear (hooks at ~124-128, implementations at ~1704-1764)
grep -n 'register_template_picker_page\|maybe_redirect_to_template_picker\|render_template_picker_page\|ajax_get_templates\|ajax_save_as_template\|ajax_create_from_template' \
  web/app/plugins/pb-split-guide/pb-split-guide.php

# PHP syntax check
lando php -l web/app/plugins/pb-split-guide/pb-split-guide.php
# Expected: No syntax errors detected
```

```bash
# HTTP check — should return 302 (login redirect), not 500
curl -s -o /dev/null -w '%{http_code}\n' http://137.149.157.198/development/wp-admin/
```

---

## Other Issues Resolved This Session

### MariaDB stale PID (lando startup failure)

Symptom: `rm: cannot remove '/opt/bitnami/mariadb/tmp/mysqld.pid': Permission denied`, all pages 500.

Fix:
```bash
lando destroy -y
lando start
```

`lando destroy` removes containers and volumes, which clears the stale PID. A plain `lando stop` + `lando start` is NOT sufficient.

### H5P content type cache returning 0 rows

Symptom: `updateContentTypeCache()` returns `true` but `SELECT COUNT(*) FROM wp_h5p_libraries_hub_cache` = 0.

Root cause: `h5p_hub_is_enabled` was unset on the **root `/wp/` site**. The cache call runs in that context and silently no-ops.

Fix (in addition to the `/development/` steps in `Week 7/Deliverables/H5P-LOCAL-DEV-SETUP.md`):
```bash
# Also enable hub on the root site
lando wp --url=https://pressbooks.test/wp/ --skip-themes --skip-plugins option update h5p_hub_is_enabled 1
lando wp --url=https://pressbooks.test/wp/ --skip-themes --skip-plugins option update h5p_send_usage_statistics 1
lando wp --url=https://pressbooks.test/wp/ --skip-themes --skip-plugins option update h5p_has_request_user_consent 1
```

### Correct plugin activation order

**Never** activate pb-split-guide or H5P on the root `/wp/` site or via Network Activate — this breaks `/wp/wp-admin/`.

Correct procedure:
```bash
# Activate only on the /development/ subsite
lando wp --url=https://pressbooks.test/development/ plugin activate h5p
lando wp --url=https://pressbooks.test/development/ plugin activate pb-split-guide
```

If accidentally network-activated:
```bash
lando wp --url=https://pressbooks.test/wp/ plugin deactivate pb-split-guide --network --skip-themes --skip-plugins
lando wp --url=https://pressbooks.test/wp/ plugin deactivate h5p --network --skip-themes --skip-plugins
# Then also deactivate from root site individually
lando wp --url=https://pressbooks.test/wp/ plugin deactivate pb-split-guide --skip-themes --skip-plugins
lando wp --url=https://pressbooks.test/wp/ plugin deactivate h5p --skip-themes --skip-plugins
```

---

## Fix 2 — Missing "Add Tutorial" Button (admin-my-tutorials.php)

### Problem

The "Add Tutorial" button/link on the My Tutorials admin page was missing. Also absent: the Import Tutorial panel, per-tutorial Export button, and the import JS handler.

### Root Cause

Same merge (`8f14a70`) — it replaced the c235882 version of `templates/admin-my-tutorials.php` with an older pre-Sprint-7 version. Confirmed via:

```bash
git log --oneline web/app/plugins/pb-split-guide/templates/admin-my-tutorials.php
# Only shows: f3aa87e and 38741fb — both pre-Sprint-7
```

**What was deleted:**

| Missing piece | User-visible impact |
|---|---|
| `"Add your first tutorial."` link (empty state) | **No way to create a new tutorial** |
| Import Tutorial panel | No tutorial import |
| `$export_nonce` / `$import_nonce` vars | Export would PHP-error |
| Export button per tutorial card | No tutorial export |
| Import AJAX JS (65 lines) | Import handler non-functional |

### Fix Applied (Server-Side)

Full restore from c235882 in one command:

```bash
cd /var/www/guide-on-the-side

git show c235882:web/app/plugins/pb-split-guide/templates/admin-my-tutorials.php \
  > web/app/plugins/pb-split-guide/templates/admin-my-tutorials.php
```

### Verify

```bash
grep -n 'Add\|Import\|Export\|pbsg-new-tutorial' \
  web/app/plugins/pb-split-guide/templates/admin-my-tutorials.php
# Should show lines 23, 25, 31, 38, 135, 142 with Import/Export/Add references
```

Also confirmed `templates/admin-new-tutorial.php` exists (required by `render_template_picker_page`).

---

## TODO — Proper Long-Term Fix

Both server-side patches above are hotfixes. The develop branch local files are still broken. Someone needs to:

1. Restore the six template picker methods in `web/app/plugins/pb-split-guide/pb-split-guide.php` on the develop branch (cherry-pick from `c235882` or re-apply manually).
2. Restore `templates/admin-my-tutorials.php` on the develop branch from `c235882`.
3. Commit and push so the server can be brought back in sync with git.

---

## AI Disclosure

This documentation was completed with assistance from Claude Code (Anthropic). Per course policy, all AI-assisted work is disclosed.
