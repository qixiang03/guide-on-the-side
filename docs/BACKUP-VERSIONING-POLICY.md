# Guide on the Side - Backup & Versioning Policy

**Author:** Daniel McGrath (Tech Lead)  
**Sprint:** 4

## What to Back Up

| Component | Location | Priority |
| - | - | - |
| Database | MariaDB container | High |
| Plugin code | `/var/www/guide-on-the-side/web/wp-content/plugins/` | High |
| Uploads | `/var/www/guide-on-the-side/web/wp-content/uploads/` | Medium |
| Lando config | `/var/www/guide-on-the-side/.lando.yml`, `.env` | Medium |
| nginx config | `/etc/nginx/sites-available/pressbooks-proxy` | Low |


## When to Back Up

| Trigger | Type |
| - | - |
| Before each deployment | Manual |
| Before major changes | Manual |
| Weekly (recommended) | Manual |


## Backup Commands

### Database Backup

```
cd /var/www/guide-on-the-side      
lando db-export backup-$(date +%Y%m%d).sql      
mv backup-\\\\\\\*.sql ~/backups/
```

### Plugin/Code Backup

```
cd /var/www/guide-on-the-side      
tar -czf ~/backups/plugins-$(date +%Y%m%d).tar.gz web/wp-content/plugins/
```

### Full Project Backup

```
cd /var/www      
tar -czf ~/backups/full-$(date +%Y%m%d).tar.gz guide-on-the-side/
```

## Restore Procedures

### Restore Database

```
cd /var/www/guide-on-the-side      
lando db-import ~/backups/backup-YYYYMMDD.sql
```

### Restore Plugins

```
cd /var/www/guide-on-the-side      
tar -xzf ~/backups/plugins-YYYYMMDD.tar.gz
```

### Full Restore (Nuclear Option)

If everything is broken:

```
\\\\\\\# Destroy current setup      
cd /var/www/guide-on-the-side      
lando destroy -y      
docker volume rm $(docker volume ls -q | grep osspblocal)      
      
\\\\\\\# Restore files      
cd /var/www      
rm -rf guide-on-the-side/\\\\\\\*      
tar -xzf ~/backups/full-YYYYMMDD.tar.gz      
      
\\\\\\\# Restart      
cd guide-on-the-side      
lando start      
lando db-import ~/backups/backup-YYYYMMDD.sql
```

## Backup Storage

| Location | Purpose |
| - | - |
| `/home/dmcgrath15021/backups/` | Primary backup storage (tech lead only) |


Backups are stored in tech lead's home directory so teammates cannot accidentally delete them.

## Responsibilities

| Role | Responsibility |
| - | - |
| Tech Lead (Daniel) | Perform backups, manage restore procedures |
| All Team Members | Request backup before major changes |


## Version Control

**Code:** All plugin code should be committed to Git repository before deployment.

**Database:** WordPress has built-in revision history for posts/pages. Pressbooks extends this for book content.

## Important Notes

⚠️ **Server is discarded at end of calendar year** - Export final product before then

⚠️ **SMCS provides no backups** - We are fully responsible

⚠️ **Test restores periodically** - A backup is only good if it works

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.

