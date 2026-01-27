# Migration Guide: Updating from Original Setup

If you already followed the original setup documentation (setup.md), use this guide to add the missing components.

---

## What's Missing in the Original Guide

| Component | Why It's Needed |
|-----------|-----------------|
| PDO MySQL extension | Pressbooks requires it - without it, creating books fails |
| WordPress Multisite | Pressbooks only works as a Multisite network |
| H5P Plugin | Required for interactive quizzes |
| Pressbooks from GitHub | **Pressbooks is NOT available via WordPress plugin search** (repo is closed) |
| Pressbooks Book Theme | Required or Pressbooks throws errors |
| Default WordPress themes | Main site needs a theme (twentytwentyfive, etc.) |
| Cache permissions | Without this, Network Admin shows critical errors |

---

## Migration Steps

### Step 1: Add the Dockerfile

```bash
cd ~/pressbooks-simple   # or wherever your setup is

cat > Dockerfile << 'EOF'
FROM wordpress:php8.2-apache
RUN docker-php-ext-install pdo_mysql
EOF
```

### Step 2: Update docker-compose.yml

Open `docker-compose.yml` and find the `wordpress` service section. Make these changes:

```yaml
# FROM THIS:
wordpress:
  image: wordpress:php8.2-apache
  # ...
  volumes:
    - wp_data:/var/www/html

# TO THIS:
wordpress:
  build: .
  # ...
  volumes:
    - wp_data:/var/www/html
    - ./wp-content:/var/www/html/wp-content
```

The `./wp-content` mount lets you persist plugins/themes locally.

### Step 3: Rebuild Containers

```bash
docker compose down
docker compose up -d --build
```

### Step 4: Enable Multisite (if not already done)

```bash
# Add multisite flag
docker exec pressbooks_wp sed -i "/That's all, stop editing/i define('WP_ALLOW_MULTISITE', true);" /var/www/html/wp-config.php
```

Then go to **http://localhost:8080/wp-admin** → **Tools** → **Network Setup** → **Install**

After that, add the network config:

```bash
docker exec pressbooks_wp sed -i "/define('WP_ALLOW_MULTISITE', true);/a\\
define( 'MULTISITE', true );\\
define( 'SUBDOMAIN_INSTALL', false );\\
define( 'DOMAIN_CURRENT_SITE', 'localhost:8080' );\\
define( 'PATH_CURRENT_SITE', '/' );\\
define( 'SITE_ID_CURRENT_SITE', 1 );\\
define( 'BLOG_ID_CURRENT_SITE', 1 );" /var/www/html/wp-config.php
```

Create .htaccess:

```bash
docker exec pressbooks_wp bash -c 'cat > /var/www/html/.htaccess << "EOF"
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
EOF'

docker exec pressbooks_wp a2enmod rewrite
docker exec pressbooks_wp service apache2 restart
```

### Step 5: Install Missing Components

```bash
# Install WP-CLI
docker exec pressbooks_wp bash -c "curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp"

# Install H5P
docker exec pressbooks_wp wp plugin install h5p --activate --allow-root

# Install git and composer
docker exec pressbooks_wp apt-get update
docker exec pressbooks_wp apt-get install -y git unzip
docker exec pressbooks_wp bash -c "curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer"

# Install Pressbooks plugin (from GitHub - NOT available via plugin search!)
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/plugins && git clone https://github.com/pressbooks/pressbooks.git"
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/plugins/pressbooks && composer install --no-dev"

# Create themes directory and install Pressbooks Book theme
docker exec pressbooks_wp mkdir -p /var/www/html/wp-content/themes
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/themes && git clone https://github.com/pressbooks/pressbooks-book.git"
docker exec pressbooks_wp bash -c "cd /var/www/html/wp-content/themes/pressbooks-book && composer install --no-dev"

# Copy default WordPress themes (needed for main site)
docker exec pressbooks_wp bash -c "cp -r /usr/src/wordpress/wp-content/themes/* /var/www/html/wp-content/themes/"

# Activate everything
docker exec pressbooks_wp wp plugin activate h5p --network --allow-root
docker exec pressbooks_wp wp plugin activate pressbooks --network --allow-root
docker exec pressbooks_wp wp theme enable pressbooks-book --network --allow-root
```

### Step 6: Fix Permissions

```bash
docker exec pressbooks_wp bash -c "mkdir -p /var/www/html/wp-content/uploads/pressbooks/cache && chown -R www-data:www-data /var/www/html/wp-content/uploads && chmod -R 755 /var/www/html/wp-content/uploads"
```

### Step 7: Final Restart

```bash
cd ~/pressbooks-simple
docker compose down
docker compose up -d
```

---

## Verify Everything Works

1. Go to **http://localhost:8080/wp-admin/network/** - should load without errors
2. Go to **Sites > Add New** - create a test book
3. The book should be created without PDO errors

---

## Important Notes

### Pressbooks is NOT on WordPress Plugin Search

If you try to search for "Pressbooks" in **Plugins > Add New**, you won't find it. The WordPress.org plugin listing is closed. You **must** install from GitHub as shown in Step 5.

### If You Somehow Installed an Old Pressbooks Version

Remove it and install properly:

```bash
# Remove the incomplete version
docker exec pressbooks_wp wp plugin deactivate pressbooks --network --allow-root
docker exec pressbooks_wp rm -rf /var/www/html/wp-content/plugins/pressbooks

# Install from GitHub (see Step 5 above)
```

---

**AI Disclosure**: This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
