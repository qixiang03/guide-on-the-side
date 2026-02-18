# Guide on the Side - Development Environment Setup Guide

**Project**: Guide on the Side - Interactive Tutorial System for UPEI Library  
**Tech Stack**: Pressbooks (WordPress-based) + H5P for interactivity  
**Last Updated**: January 27, 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Step 1: Install Docker](#step-1-install-docker)
4. [Step 2: Create Docker Compose Environment](#step-2-create-docker-compose-environment)
5. [Step 3: Start the Environment](#step-3-start-the-environment)
6. [Step 4: Complete WordPress Installation](#step-4-complete-wordpress-installation)
7. [Step 5: Enable WordPress Multisite](#step-5-enable-wordpress-multisite)
8. [Step 6: Apply Network Configuration](#step-6-apply-network-configuration)
9. [Step 7: Install WP-CLI](#step-7-install-wp-cli)
10. [Step 8: Install H5P Plugin](#step-8-install-h5p-plugin)
11. [Step 9: Install Pressbooks Plugin](#step-9-install-pressbooks-plugin)
12. [Step 10: Install Pressbooks Book Theme](#step-10-install-pressbooks-book-theme)
13. [Step 11: Activate Plugins and Theme](#step-11-activate-plugins-and-theme)
14. [Step 12: Fix Pressbooks Cache Permissions](#step-12-fix-pressbooks-cache-permissions)
15. [Step 13: Restart Environment](#step-13-restart-environment-recommended)
16. [Daily Usage Commands](#daily-usage-commands)
17. [Access URLs and Credentials](#access-urls-and-credentials)
18. [Folder Structure](#folder-structure)
19. [Troubleshooting](#troubleshooting)

---

## Overview

This guide walks through setting up a local development environment for the Guide on the Side project. The environment uses:

- **Docker & Docker Compose**: Container orchestration
- **WordPress**: Content management system
- **Pressbooks**: WordPress plugin for creating structured books/tutorials
- **H5P**: Interactive content plugin for quizzes and activities
- **MariaDB**: Database server
- **phpMyAdmin**: Database administration interface

---

## Prerequisites

- **Operating System**: Linux (tested on CachyOS/Arch, should work on any Linux distro)
- **Terminal access**: Required for all commands
- **Browser**: For WordPress configuration

---

## Step 1: Install Docker

### For Arch-based systems (CachyOS, Manjaro, EndeavourOS):

```bash
# Install Docker and Docker Compose
sudo pacman -S docker docker-compose

# Enable and start Docker service
sudo systemctl enable --now docker

# Add your user to the docker group (avoids needing sudo for docker commands)
sudo usermod -aG docker $USER
```

### For Debian/Ubuntu-based systems:

```bash
# Update package index
sudo apt update

# Install Docker
sudo apt install docker.io docker-compose

# Enable and start Docker service
sudo systemctl enable --now docker

# Add your user to the docker group
sudo usermod -aG docker $USER
```

### IMPORTANT: After adding yourself to the docker group:

**You must log out and log back in** for the group change to take effect.

Alternatively, run this command to apply the group change in your current session:
```bash
newgrp docker
```

### Verify Installation:

```bash
docker --version
docker compose version
groups $USER | grep docker
```

---

## Step 2: Create Docker Compose Environment

```bash
# Create the project directory
mkdir -p ~/pressbooks-simple
cd ~/pressbooks-simple

# Create the Dockerfile (adds PDO MySQL extension required by Pressbooks)
cat > Dockerfile << 'EOF'
FROM wordpress:php8.2-apache

# Install PDO MySQL extension required by Pressbooks
RUN docker-php-ext-install pdo_mysql
EOF

# Create the docker-compose.yml file
cat > docker-compose.yml << 'EOF'
services:
  db:
    image: mariadb:10.6
    container_name: pressbooks_db
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: pressbooks
      MYSQL_USER: pressbooks
      MYSQL_PASSWORD: pressbooks_pw
      MYSQL_ROOT_PASSWORD: root_pw
    volumes:
      - db_data:/var/lib/mysql

  wordpress:
    build: .
    container_name: pressbooks_wp
    restart: unless-stopped
    depends_on:
      - db
    ports:
      - "8080:80"
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_NAME: pressbooks
      WORDPRESS_DB_USER: pressbooks
      WORDPRESS_DB_PASSWORD: pressbooks_pw
    volumes:
      - wp_data:/var/www/html
      - ./wp-content:/var/www/html/wp-content

  phpmyadmin:
    image: phpmyadmin/phpmyadmin
    container_name: pressbooks_pma
    restart: unless-stopped
    depends_on:
      - db
    ports:
      - "8081:80"
    environment:
      PMA_HOST: db
      PMA_PORT: 3306
      PMA_USER: pressbooks
      PMA_PASSWORD: pressbooks_pw

volumes:
  db_data:
  wp_data:
EOF
```

---

## Step 3: Start the Environment

```bash
cd ~/pressbooks-simple

# Build and start all containers in detached mode
docker compose up -d --build

# Verify containers are running
docker compose ps
```

Wait about 30 seconds for all services to fully initialize before proceeding.

**Note**: The `--build` flag ensures the custom WordPress image with PDO MySQL support is built. On subsequent starts, you can omit it unless you've changed the Dockerfile.

---

## Step 4: Complete WordPress Installation

1. Open your browser and navigate to: **http://localhost:8080**
2. Fill in the WordPress installation wizard:
   - **Site Title**: Guide on the Side (or your preferred name)
   - **Username**: admin
   - **Password**: admin
   - **Email**: your-email@example.com
3. Click **Install WordPress**
4. Log in with the credentials you just created

---

## Step 5: Enable WordPress Multisite

Pressbooks requires WordPress Multisite to function.

```bash
# Add WP_ALLOW_MULTISITE to wp-config.php
docker exec pressbooks_wp sed -i "/That's all, stop editing/i define('WP_ALLOW_MULTISITE', true);" /var/www/html/wp-config.php

# Verify the change
docker exec pressbooks_wp grep "WP_ALLOW_MULTISITE" /var/www/html/wp-config.php
```

### Complete Network Setup in Browser:

1. Go to **http://localhost:8080/wp-admin**
2. Navigate to **Tools > Network Setup**
3. Click **Install**
4. WordPress will display configuration snippets - save these for the next step

---

## Step 6: Apply Network Configuration

```bash
# Add the multisite configuration after WP_ALLOW_MULTISITE
docker exec pressbooks_wp sed -i "/define('WP_ALLOW_MULTISITE', true);/a\\
define( 'MULTISITE', true );\\
define( 'SUBDOMAIN_INSTALL', false );\\
define( 'DOMAIN_CURRENT_SITE', 'localhost:8080' );\\
define( 'PATH_CURRENT_SITE', '/' );\\
define( 'SITE_ID_CURRENT_SITE', 1 );\\
define( 'BLOG_ID_CURRENT_SITE', 1 );" /var/www/html/wp-config.php
```

### Create .htaccess file:

```bash
docker exec pressbooks_wp bash -c 'cat > /var/www/html/.htaccess << "EOF"
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]

# add a trailing slash to /wp-admin
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]

RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
EOF'
```

### Enable mod_rewrite and restart Apache:

```bash
docker exec pressbooks_wp a2enmod rewrite
docker exec pressbooks_wp service apache2 restart
```

---

## Step 7: Install WP-CLI

```bash
docker exec pressbooks_wp bash -c "curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"

# Verify installation
docker exec pressbooks_wp wp --version --allow-root
```

---

## Step 8: Install H5P Plugin

```bash
docker exec pressbooks_wp wp plugin install h5p --activate --allow-root
```

---

## Step 9: Install Pressbooks Plugin

```bash
# Install git in the container
docker exec pressbooks_wp apt-get update
docker exec pressbooks_wp apt-get install -y git unzip

# Clone Pressbooks plugin
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/plugins && git clone https://github.com/pressbooks/pressbooks.git"

# Install Composer in the container
docker exec pressbooks_wp bash -c "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"

# Install Pressbooks dependencies
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/plugins/pressbooks && composer install --no-dev"
```

---

## Step 10: Install Pressbooks Book Theme

```bash
# Clone the theme
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/themes && git clone https://github.com/pressbooks/pressbooks-book.git"

# Install theme dependencies
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/themes/pressbooks-book && composer install --no-dev"
```

---

## Step 11: Activate Plugins and Theme

```bash
# Activate H5P network-wide
docker exec pressbooks_wp wp plugin activate h5p --network --allow-root

# Activate Pressbooks network-wide
docker exec pressbooks_wp wp plugin activate pressbooks --network --allow-root

# Enable the Pressbooks Book theme network-wide
docker exec pressbooks_wp wp theme enable pressbooks-book --network --allow-root
```

### Verify installation:

```bash
docker exec pressbooks_wp wp plugin list --allow-root
docker exec pressbooks_wp wp theme list --allow-root
```

---

## Step 12: Fix Pressbooks Cache Permissions

Pressbooks uses a cache directory for compiled Blade templates.

```bash
docker exec pressbooks_wp bash -c "mkdir -p /var/www/html/wp-content/uploads/pressbooks/cache && chown -R www-data:www-data /var/www/html/wp-content/uploads/pressbooks && chmod -R 755 /var/www/html/wp-content/uploads/pressbooks"
```

After running this command, visit **http://localhost:8080/wp-admin/network/** to verify it loads without errors.

---

## Step 13: Restart Environment (Recommended)

After completing all setup steps, do a full restart to clear any cached errors:

```bash
cd ~/pressbooks-simple
docker compose down
docker compose up -d
```

Wait 30 seconds, then verify everything works:
- http://localhost:8080/wp-admin/network/ (Network Admin)
- Try creating a test site: **Sites > Add New**

This avoids permission/cache issues that can occur during initial setup.

---

## Daily Usage Commands

**Important**: All `docker compose` commands must be run from the `~/pressbooks-simple` directory (where `docker-compose.yml` is located). Always `cd` there first:

```bash
cd ~/pressbooks-simple
```

Then you can run these commands:

```bash
# Start environment
docker compose up -d

# Start environment (with rebuild if you changed the Dockerfile)
docker compose up -d --build

# Stop environment
docker compose down

# View logs (Ctrl+C to exit)
docker compose logs -f

# Check status
docker compose ps

# Access WordPress container shell (type 'exit' to leave)
docker exec -it pressbooks_wp bash

# Run WP-CLI commands
docker exec pressbooks_wp wp plugin list --allow-root
```

### Note: What Persists vs. What Doesn't

**Persists across restarts:**
- WordPress files in `./wp-content` (plugins, themes, uploads)
- Database in the `db_data` Docker volume

**Doesn't persist (installed inside container):**
- WP-CLI, git, unzip, composer - these are setup tools only

If you need WP-CLI after a restart, reinstall it:
```bash
docker exec pressbooks_wp bash -c "curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"
```

---

## Access URLs and Credentials

| Service | URL |
|---------|-----|
| WordPress/Pressbooks | http://localhost:8080 |
| WordPress Admin | http://localhost:8080/wp-admin |
| Network Admin | http://localhost:8080/wp-admin/network/ |
| phpMyAdmin | http://localhost:8081 |

| Service | Username | Password |
|---------|----------|----------|
| WordPress Admin | admin | admin |
| Database | pressbooks | pressbooks_pw |
| Database Root | root | root_pw |

---

## Folder Structure

```
~/pressbooks-simple/          # Docker environment (local only, don't push)
├── Dockerfile                # Custom WordPress image with PDO MySQL
├── docker-compose.yml
└── wp-content/

~/guide-on-the-side/          # Team GitHub repo (push/pull here)
├── docs/
├── src/
└── ...
```

---

## Troubleshooting

### Docker Permission Denied

```bash
sudo usermod -aG docker $USER
# Then log out and back in
```

### Port Already in Use

```bash
sudo lsof -i :8080
# Change port in docker-compose.yml to "8088:80" if needed
```

### Containers Not Starting

```bash
docker compose logs
docker compose down
docker compose up -d
```

### Pressbooks Cache Error

```bash
docker exec pressbooks_wp bash -c "mkdir -p /var/www/html/wp-content/uploads/pressbooks/cache && chown -R www-data:www-data /var/www/html/wp-content/uploads/pressbooks && chmod -R 755 /var/www/html/wp-content/uploads/pressbooks"
```

### PDO Driver Error ("could not find driver")

If you see an error like `PDOException: could not find driver` when creating a new site, the PDO MySQL extension is missing. This happens if you're using the standard WordPress image instead of our custom Dockerfile.

**Quick fix (temporary, won't survive container restart):**
```bash
docker exec pressbooks_wp docker-php-ext-install pdo_mysql
docker exec pressbooks_wp service apache2 restart
```

**Permanent fix:** Ensure you have the `Dockerfile` in your `~/pressbooks-simple` directory and that `docker-compose.yml` uses `build: .` instead of `image: wordpress:php8.2-apache`. Then rebuild:
```bash
cd ~/pressbooks-simple
docker compose down
docker compose up -d --build
```

### Sub-site Shows "Critical Error" But Was Working Before

This is often browser cache. Try:
1. Hard refresh: `Ctrl+Shift+R`
2. Open in incognito/private window
3. Clear browser cache

The site may actually be working - test with:
```bash
curl -I http://localhost:8080/your-site-name/
# If you see "HTTP/1.1 200 OK" - the site works, it's browser cache
```

### Reset Everything (Nuclear Option)

```bash
cd ~/pressbooks-simple
docker compose down -v
docker compose up -d
```

**Warning**: This deletes all WordPress data!

---

## Next Steps

1. Create a test book in Pressbooks (Network Admin > Sites > Add New)
2. Add H5P content to test interactive quizzes
3. Test iframe embedding for the split-screen layout

---

## Notes

**Tested Environment**: This guide was written and fully tested on a CachyOS (Arch-based) laptop on January 27, 2026. All commands verified working with Docker 29.x and WordPress 6.x.

**AI Disclosure**: This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
