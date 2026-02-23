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

## Troubleshooting

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
    
        \\\# Rewrite URLs in response body    
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


## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.

