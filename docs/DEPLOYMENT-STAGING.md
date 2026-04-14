# Guide on the Side - Deployment Staging Documentation

**Author:** Daniel McGrath (Tech Lead)  
**Sprint:** 4

## Server Information

| Item | Value |
| - | - |
| External IP | 137.149.157.198 |
| SSH Port | 65022 |
| Internal LAN IP | 192.168.0.198 |
| OS | Ubuntu 24.04.3 LTS |
| Open Ports | 80 (HTTP), 443 (HTTPS) only |


## Team Access

### SSH Connection

```
ssh -p 65022 \\\<username\\\>@137.149.157.198
```

### User Accounts

| Username | Team Member | Groups |
| - | - | - |
| dmcgrath15021 | Daniel McGrath (Tech Lead) | team8, docker, sudo |
| cnjones | Caleb Jones | team8, docker |
| gyang16970 | Guo Yang (Cindy) | team8, docker |
| qphang | Qi Xiang (Enzo) | team8, docker |
| xyu16465 | Xiaohan Yu (Reagan) | team8, docker |


### Directory Structure

```
/var/www/guide-on-the-side/    \\\# Shared project folder (team8 group)    
├── web/                        \\\# WordPress webroot    
├── .lando.yml                  \\\# Lando configuration    
├── .env                        \\\# Environment variables    
└── pb\\\_local\\\_db.sql            \\\# Sample database    
    
/home/\\\<username\\\>/               \\\# Personal directories    
/home/dmcgrath15021/backups/   \\\# Backup storage (tech lead only)
```

## Accessing Pressbooks

### External Access (Browser)

```
http://137.149.157.198
```

### WordPress Admin Login

- **URL:** [http://137.149.157.198/wp/wp-admin/](http://137.149.157.198/wp/wp-admin/)

- **Username:** admin

- **Password:** admin

### Database Credentials

- **Host:** database (internal container name)

- **Database:** pressbooks\_oss

- **User:** pressbooks\_oss\_user

- **Password:** secretpassword

- **External Port:** Check with `lando info`

## Architecture Overview

```
External Browser (http://137.149.157.198:80)    
    │    
    ▼ NAT    
Server LAN (192.168.0.198:80)    
    │    
    ▼ nginx reverse proxy    
Traefik HTTPS (127.0.0.1:443)    
    │    
    ▼ Docker container routing    
Lando appserver\\\_nginx    
    │    
    ▼    
WordPress/Pressbooks
```

### Why This Architecture?

1. **Lando/Traefik** binds to localhost (127.0.0.1) only

2. **nginx** binds to external interface (192.168.0.198:80)

3. **nginx** proxies to Traefik with `Host: pressbooks.test` header

4. **nginx** rewrites all `pressbooks.test` URLs to the external IP

5. **WordPress database URLs unchanged** - nginx handles rewriting

## Starting the Server

### After Server Reboot

```
\\\# SSH into server    
ssh -p 65022 dmcgrath15021@137.149.157.198    
    
\\\# Start Lando    
cd /var/www/guide-on-the-side    
lando start    
    
\\\# Verify Traefik is on localhost only    
sudo netstat -tlnp | grep :80    
\\\# Should show: 127.0.0.1:80    
    
\\\# Start nginx (if not running)    
sudo systemctl start nginx    
    
\\\# Verify nginx    
sudo systemctl status nginx
```

### Quick Health Check

```
\\\# Test from server    
curl -sI http://192.168.0.198/wp/wp-login.php    
\\\# Should return HTTP 200
```

## H5P Setup (After Fresh Database Import)

After a fresh `lando start` and database import, H5P requires additional configuration on the `/development/` subsite (blog\_id=39). Run these commands from `/var/www/guide-on-the-side`:

```bash
# 1. Install pressbooks-book theme dependencies (required for /development/ subsite)
lando composer install --working-dir=web/app/themes/pressbooks-book

# 2. Install pb-split-guide plugin dependencies (TCPDF for PDF certificate generation)
lando composer install --working-dir=web/app/plugins/pb-split-guide

# 3. Fix H5P hub settings for the /development/ subsite
#    --skip-themes --skip-plugins required because McLuhan theme errors without its composer deps
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_hub_is_enabled 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_send_usage_statistics 1
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option update h5p_has_request_user_consent 1

# 4. Add site UUID (workaround for broken H5P Hub registration endpoint)
#    hub-api.h5p.org/v1/sites returns a broken 302 redirect — upstream H5P bug.
#    Copy site 1's UUID to site 39 as a workaround.
lando wp --url=https://pressbooks.test/development/ --skip-themes --skip-plugins option add h5p_h5p_site_uuid 575494e6-7409-47ce-a3e9-3a2279aca75e

# 5. Populate the H5P content type cache
lando wp --url=https://pressbooks.test/wp/ eval '
  $plugin = H5P_Plugin::get_instance();
  $core = $plugin->get_h5p_instance("core");
  $result = $core->updateContentTypeCache();
  echo (bool)$result ? "SUCCESS" : "FAILED";
'

# 6. Flush object cache
lando wp cache flush
```

### H5P Verification

```bash
lando ssh -s database -c "mysql -u pressbooks_oss_user -psecretpassword pressbooks_oss -e '
  SELECT COUNT(*) FROM wp_h5p_libraries_hub_cache;
  SELECT meta_key, meta_value FROM wp_sitemeta WHERE meta_key = \"h5p_content_type_cache_updated_at\";
'"
```

Expected: 50+ rows in `wp_h5p_libraries_hub_cache`, timestamp = today (not 0 / 1970).

### Why These Steps Are Needed

| Issue | Symptom | Root Cause |
| - | - | - |
| H5P hub disabled on site 39 | "Failed to load data" on H5P admin pages | `h5p_hub_is_enabled` empty in `wp_39_options` — H5P treats empty string as false, returns 403 HUB\_DISABLED |
| Empty content type cache | "Last update: January 1, 1970" | `wp_h5p_libraries_hub_cache` has 0 rows — cache was never populated for this environment |
| Missing site UUID | Cache refresh fails silently after ~1 week | Site 39 has no UUID; H5P tries to register one but `hub-api.h5p.org/v1/sites` returns a broken 302 redirect (upstream bug) — workaround is reusing site 1's UUID |
| JSON-escaped AJAX URLs go to HTTPS | `ERR_CONNECTION_REFUSED` in browser console for `admin-ajax.php` | WordPress `json_encode()` escapes slashes (`https:\/\/pressbooks.test`); old nginx `sub_filter` rules only matched literal `https://pressbooks.test` and missed the escaped variant — fixed by adding escaped `sub_filter` lines (see nginx config below) |

## Troubleshooting

### H5P "Failed to load data" / "Last update: 1970"

Run the full H5P setup sequence from the **H5P Setup** section above. The three most common causes are: hub disabled for site 39, empty content type cache, and missing site UUID. See that section for fix commands and root cause details.

### AJAX Calls Failing with ERR\_CONNECTION\_REFUSED (HTTPS on port 443)

WordPress embeds admin URLs using `json_encode()`, which escapes slashes: `https:\/\/pressbooks.test`. If the nginx `sub_filter` config only has the plain variants (`https://pressbooks.test`), it misses these escaped URLs. The fallback `pressbooks.test → 137.149.157.198` rule then strips only the hostname, leaving `https://` intact — the browser tries port 443 (blocked) and gets `ERR_CONNECTION_REFUSED`.

**Fix:** Ensure the nginx config includes the JSON-escaped `sub_filter` lines (shown in nginx Configuration section below). Then reload nginx:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### Database Healthcheck Fails (mysqld.pid error)

**Symptom:** Lando shows database healthcheck failed, logs show:

```
rm: cannot remove '/opt/bitnami/mariadb/tmp/mysqld.pid': Permission denied
```

**Cause:** Stale data in Docker volumes from previous runs

**Solution:**

```
cd /var/www/guide-on-the-side    
lando destroy -y    
docker volume rm $(docker volume ls -q | grep osspblocal)    
lando start
```

### nginx Won't Start (Port 80 in use)

**Check what's using port 80:**

```
sudo netstat -tlnp | grep :80
```

**If Traefik is on 0.0.0.0:80:**

```
\\\# Remove the bindAddress config    
rm ~/.lando/config.yml    
    
\\\# Restart Lando    
lando poweroff    
lando start    
    
\\\# Then start nginx    
sudo systemctl start nginx
```

### 404 Error When Accessing via IP

Traefik is running but nginx isn't proxying. Check:

```
sudo systemctl status nginx
```

### "Cookies are blocked" Error on Login

**Cause:** WordPress sets `secure` flag on cookies because it thinks it's HTTPS, but the browser accesses via plain HTTP and won't send secure cookies back.

**Solution:** Ensure `proxy\\\_cookie\\\_flags ~ nosecure;` is in the nginx config.

### Browser Redirects to pressbooks.test

The nginx sub\_filter isn't working. Verify the config:

```
sudo nginx -t    
cat /etc/nginx/sites-enabled/pressbooks-proxy
```

## nginx Configuration

**File:** `/etc/nginx/sites-available/pressbooks-proxy`

```
server \\\{    
    listen 192.168.0.198:80;    
    server\\\_name 137.149.157.198;    
    
    location / \\\{    
        proxy\\\_pass https://127.0.0.1:443;    
        proxy\\\_ssl\\\_verify off;    
        proxy\\\_set\\\_header Host pressbooks.test;    
        proxy\\\_set\\\_header X-Real-IP $remote\\\_addr;    
        proxy\\\_set\\\_header X-Forwarded-For $proxy\\\_add\\\_x\\\_forwarded\\\_for;    
        proxy\\\_set\\\_header X-Forwarded-Proto https;    
        proxy\\\_set\\\_header Accept-Encoding "";    
    
        \\\# Rewrite Location headers    
        proxy\\\_redirect https://pressbooks.test/ http://137.149.157.198/;    
        proxy\\\_redirect http://pressbooks.test/ http://137.149.157.198/;    
    
        \\\# Rewrite cookie domains and strip secure flag for HTTP access    
        proxy\\\_cookie\\\_domain pressbooks.test 137.149.157.198;    
        proxy\\\_cookie\\\_flags ~ nosecure;    
    
        \\\# Rewrite URLs in response body (plain and JSON-escaped variants)    
        sub\\\_filter 'https:\/\/pressbooks.test' 'http:\/\/137.149.157.198';    
        sub\\\_filter 'http:\/\/pressbooks.test' 'http:\/\/137.149.157.198';    
        sub\\\_filter 'https://pressbooks.test' 'http://137.149.157.198';    
        sub\\\_filter 'http://pressbooks.test' 'http://137.149.157.198';    
        sub\\\_filter 'pressbooks.test' '137.149.157.198';    
        sub\\\_filter\\\_once off;    
        sub\\\_filter\\\_types text/html text/css text/javascript application/javascript application/json;    
    \\\}    
\\\}
```

### Key Configuration Explained

| Directive | Purpose |
| - | - |
| `listen 192.168.0.198:80` | Bind to LAN IP only, avoid Traefik conflict |
| `proxy\\\_pass https://127.0.0.1:443` | Forward to Traefik's HTTPS port |
| `proxy\\\_ssl\\\_verify off` | Accept Traefik's self-signed cert |
| `Host pressbooks.test` | Traefik routes based on this header |
| `X-Forwarded-Proto https` | Prevent WordPress HTTPS redirect loops |
| `Accept-Encoding ""` | Disable gzip so sub\_filter works |
| `proxy\\\_redirect` | Rewrite Location headers (redirects) |
| `proxy\\\_cookie\\\_domain` | Rewrite Set-Cookie domains |
| `proxy\\\_cookie\\\_flags ~ nosecure` | Strip `secure` flag so cookies work over HTTP |
| `sub\\\_filter` | Rewrite URLs in HTML/CSS/JS bodies |


## Important Warnings

⚠️ **Do NOT run `wp search-replace` on the database** - This broke things before. nginx handles all URL rewriting.

⚠️ **Do NOT modify WordPress siteurl/home options** - Leave them as `pressbooks.test`.

⚠️ **Do NOT recreate `~/.lando/config.yml` with `bindAddress: 0.0.0.0`** - This conflicts with nginx on port 80.

⚠️ **Lando does NOT auto-start on reboot** - Must be started manually.

## Installed Software

| Software | Version | Purpose |
| - | - | - |
| Docker | 24.x | Container runtime |
| Lando | 3.21.0 | Local dev environment |
| nginx | 1.24.x | Reverse proxy |
| Git | 2.x | Version control |


## Useful Commands

```
\\\# Lando commands (run from /var/www/guide-on-the-side)    
lando start              \\\# Start all services    
lando stop               \\\# Stop all services    
lando restart            \\\# Restart all services    
lando poweroff           \\\# Stop Lando completely    
lando destroy -y         \\\# Destroy app (keeps files, removes containers)    
lando info               \\\# Show service info and ports    
lando logs               \\\# View all logs    
lando logs -s database   \\\# View database logs    
lando ssh                \\\# SSH into appserver container    
lando wp \\\<command\\\>       \\\# Run WP-CLI commands    
    
\\\# nginx commands    
sudo systemctl start nginx    
sudo systemctl stop nginx    
sudo systemctl restart nginx    
sudo systemctl status nginx    
sudo nginx -t            \\\# Test configuration    
    
\\\# Docker commands    
docker ps                \\\# List running containers    
docker ps -a             \\\# List all containers    
docker volume ls         \\\# List volumes    
docker network ls        \\\# List networks    
docker system prune -a   \\\# Clean up (careful!)
```

## Next Steps

1. Install custom plugin(s) to `/var/www/guide-on-the-side/web/wp-content/plugins/`

2. Set up automated backups (see Backup Policy document)

3. Configure CI/CD to deploy to server

## Document History

| Date | Author | Changes |
| - | - | - |
| 2026-02-17 | Daniel McGrath | Initial deployment staging documentation |
| 2026-02-17 | Daniel McGrath | Added proxy\_cookie\_flags to fix login cookie issue |
| 2026-04-14 | Daniel McGrath | Added H5P setup section and JSON-escaped sub\_filter fix (from Week 6/7 troubleshooting docs) |
| 2026-04-14 | Daniel McGrath | Added plugin composer install step (TCPDF for certificate generation) |


## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.

