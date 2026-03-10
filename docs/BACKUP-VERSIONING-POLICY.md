# Guide on the Side - Backup & Versioning Policy

**Author:** Daniel McGrath (Tech Lead)  
**Sprint:** 4 (Updated Sprint 5)

## What to Back Up

| Component | Location | Priority |
|-----------|----------|----------|
| Database | MariaDB container | High |
| Plugin code | `/var/www/guide-on-the-side/web/app/plugins/` | High |
| Uploads | `/var/www/guide-on-the-side/web/app/uploads/` | Medium |
| Lando config | `/var/www/guide-on-the-side/.lando.yml`, `.env` | Medium |
| nginx config | `/etc/nginx/sites-available/pressbooks-proxy` | Low |

## Automated Backups

Backups run automatically via cron jobs on the server.

| Schedule | Script | Purpose |
|----------|--------|---------|
| Daily at 3am | `~/auto-update.sh` | Pull latest code from main branch |
| Sundays at 2am | `~/backup.sh` | Database + plugin backup |

### auto-update.sh

```bash
#!/bin/bash
cd /var/www/guide-on-the-side
git pull origin main
echo "Updated at $(date)" >> ~/update.log
```

### backup.sh

```bash
#!/bin/bash
cd /var/www/guide-on-the-side

# Database - remove old temp, export, then move
rm -f backup-temp.sql.gz
lando db-export backup-temp.sql
mv backup-temp.sql.gz ~/backups/db-$(date +%Y%m%d).sql.gz

# Plugins
tar -czf ~/backups/plugins-$(date +%Y%m%d).tar.gz web/app/plugins/

echo "Backup completed at $(date)" >> ~/backup.log
```

### Crontab Entries

```
0 3 * * * /home/dmcgrath15021/auto-update.sh
0 2 * * 0 /home/dmcgrath15021/backup.sh
```

To view: `crontab -l`  
To edit: `crontab -e`

## Manual Backup Commands

### Database Backup

```bash
cd /var/www/guide-on-the-side
lando db-export backup-temp.sql
mv backup-temp.sql.gz ~/backups/db-$(date +%Y%m%d).sql.gz
```

### Plugin/Code Backup

```bash
cd /var/www/guide-on-the-side
tar -czf ~/backups/plugins-$(date +%Y%m%d).tar.gz web/app/plugins/
```

### Full Project Backup

```bash
cd /var/www
tar -czf ~/backups/full-$(date +%Y%m%d).tar.gz guide-on-the-side/
```

## Restore Procedures

### Restore Database

```bash
cd /var/www/guide-on-the-side
gunzip ~/backups/db-YYYYMMDD.sql.gz
lando db-import ~/backups/db-YYYYMMDD.sql
```

### Restore Plugins

```bash
cd /var/www/guide-on-the-side
tar -xzf ~/backups/plugins-YYYYMMDD.tar.gz
```

### Full Restore (Nuclear Option)

If everything is broken:

```bash
# Destroy current setup
cd /var/www/guide-on-the-side
lando destroy -y
docker volume rm $(docker volume ls -q | grep guideontheside)

# Restore files
cd /var/www
rm -rf guide-on-the-side/*
tar -xzf ~/backups/full-YYYYMMDD.tar.gz

# Restart
cd guide-on-the-side
lando start
gunzip ~/backups/db-YYYYMMDD.sql.gz
lando db-import ~/backups/db-YYYYMMDD.sql
```

## Backup Storage

| Location | Purpose |
|----------|---------|
| `/home/dmcgrath15021/backups/` | Primary backup storage (tech lead only) |
| `~/backup.log` | Backup history log |
| `~/update.log` | Auto-update history log |

Backups are stored in tech lead's home directory so teammates cannot accidentally delete them.

## Responsibilities

| Role | Responsibility |
|------|----------------|
| Tech Lead (Daniel) | Maintain scripts, monitor logs, manage restores |
| All Team Members | Request backup before major changes |

## Version Control

**Code:** All plugin code is in Git (`https://github.com/qixiang03/guide-on-the-side`). Auto-pulled daily.

**Database:** WordPress has built-in revision history for posts/pages. Pressbooks extends this for book content.

## Important Notes

⚠️ **Server is discarded at end of calendar year** - Export final product before then

⚠️ **SMCS provides no backups** - We are fully responsible

⚠️ **Test restores periodically** - A backup is only good if it works

⚠️ **Check logs periodically** - `cat ~/backup.log` and `cat ~/update.log`

## Document History

| Date | Author | Changes |
|------|--------|---------|
| 2026-02-17 | Daniel McGrath | Initial backup policy |
| 2026-03-03 | Daniel McGrath | Added automated backups via cron, updated paths |

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
