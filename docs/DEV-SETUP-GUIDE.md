# Guide on the Side - Development Environment Setup Guide

**Project**: Guide on the Side - Interactive Tutorial System for UPEI Library
**Tech Stack**: WordPress Multisite (Bedrock) + Pressbooks + H5P
**Local Dev**: Lando (Docker-based)
**Last Updated**: April 9, 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Step 1: Install Lando](#step-1-install-lando)
4. [Step 2: Clone the Repository](#step-2-clone-the-repository)
5. [Step 3: Configure Environment](#step-3-configure-environment)
6. [Step 4: Obtain the Database Dump](#step-4-obtain-the-database-dump)
7. [Step 5: Start the Environment](#step-5-start-the-environment)
8. [Step 6: Install the Plugin Dependencies](#step-6-install-the-plugin-dependencies)
9. [Step 7: Verify the Setup](#step-7-verify-the-setup)
10. [Running Tests](#running-tests)
11. [Daily Usage Commands](#daily-usage-commands)
12. [Access URLs and Credentials](#access-urls-and-credentials)
13. [Services](#services)
14. [Repository Structure](#repository-structure)
15. [XDebug (IDE Debugging)](#xdebug-ide-debugging)
16. [Git Workflow](#git-workflow)
17. [CI/CD Pipeline](#cicd-pipeline)
18. [Troubleshooting](#troubleshooting)

---

## Overview

This guide walks through setting up a local development environment for the Guide on the Side project. The environment uses **Lando** to orchestrate Docker containers with the following services:

- **PHP 8.3 + Nginx** — WordPress application server
- **MariaDB 10.5.23** — Database
- **Redis 5.0** — Object caching (optional)
- **Node.js** — Frontend tooling and Jest tests
- **Mailhog** — Email capture for testing

The project uses the [Bedrock](https://roots.io/bedrock/) WordPress boilerplate, which separates WordPress core (`web/wp/`) from application code (`web/app/`), manages dependencies via Composer, and uses `.env` files for configuration.

> **Important**: The local dev domain is `pressbooks.test`, **NOT** `localhost:8080`. These are different vhosts with different configurations.

---

## Prerequisites

- **Docker Desktop** (macOS/Windows) or **Docker Engine** (Linux)
- **Lando** v3.21+ ([lando.dev](https://lando.dev))
- **Git**
- **A browser** (Firefox recommended — see [Troubleshooting](#secure-cookie-rejection) for why)

---

## Step 1: Install Lando

Lando wraps Docker and provides per-project orchestration via `.lando.yml`.

### macOS

```bash
# Via Homebrew
brew install lando

# Or download the .dmg installer from https://lando.dev/download/
```

### Windows

Download the installer from [lando.dev/download](https://lando.dev/download/). Requires WSL2 + Docker Desktop.

### Linux (Debian/Ubuntu)

```bash
wget https://files.lando.dev/installer/lando-x64-stable.deb
sudo dpkg -i lando-x64-stable.deb
```

### Linux (Arch-based)

```bash
# AUR
yay -S lando-bin
```

### Verify Installation

```bash
lando version
docker --version
```

---

## Step 2: Clone the Repository

```bash
git clone https://github.com/qixiang03/guide-on-the-side.git
cd guide-on-the-side
```

---

## Step 3: Configure Environment

Lando's `pre-start` event automatically copies `.env.example` → `.env` and `config_services/.env.example` → `config_services/.env` if they don't exist. However, you should generate unique salts:

```bash
# Copy env files (Lando does this automatically, but doing it now lets you edit first)
cp .env.example .env
cp config_services/.env.example config_services/.env
```

### Generate Auth Salts

1. Visit [roots.io/salts.html](https://roots.io/salts.html)
2. Copy the generated salts
3. Replace the `'generateme'` values in `.env`:

```env
AUTH_KEY='your-generated-key'
SECURE_AUTH_KEY='your-generated-key'
LOGGED_IN_KEY='your-generated-key'
NONCE_KEY='your-generated-key'
AUTH_SALT='your-generated-salt'
SECURE_AUTH_SALT='your-generated-salt'
LOGGED_IN_SALT='your-generated-salt'
NONCE_SALT='your-generated-salt'
```

### Set Architecture (Apple Silicon / ARM)

If you're on Apple Silicon (M1/M2/M3/M4), edit `config_services/.env`:

```env
ARCHITECTURE=arm64
```

For Intel/AMD systems:

```env
ARCHITECTURE=amd64
```

---

## Step 4: Obtain the Database Dump

The project auto-imports a database dump on first start. You need the file `pb_local_db.sql` in the repository root.

**Ask a team member** (Daniel or Enzo) for the current database dump, then place it at:

```
guide-on-the-side/pb_local_db.sql
```

This file is gitignored. On `lando start`, the `post-start` event automatically imports it into MariaDB.

> **Note**: This dump is a **dev-team convenience only** — it contains the entire WordPress/Pressbooks database (users, multisite config, test content) so developers can skip manual setup. It is NOT part of the client deliverable. Melissa's library already runs Pressbooks; she only needs the `pb-split-guide/` plugin folder, which creates its own tables on activation via `dbDelta()`.

> If you don't have a dump, you can set up WordPress from scratch (see [Manual WordPress Setup](#manual-wordpress-setup-without-db-dump) in Troubleshooting), but using the team dump is strongly recommended.

---

## Step 5: Start the Environment

```bash
cd guide-on-the-side
lando start
```

This will:
1. Pull/build Docker images (first run takes 5–10 minutes)
2. Run `scripts/pressbooks_required_libraries.sh` (installs Java, epubcheck, PrinceXML, Saxon, Node.js, XSL extension)
3. Run `composer install` (installs WordPress, Pressbooks, all plugins/themes)
4. Copy `.env` files if missing
5. Import `pb_local_db.sql` into the database

When it finishes, Lando prints the proxy URLs:

```
APPSERVER NGINX URLS
 https://pressbooks.test
 http://pressbooks.test
```

> **First start is slow** due to the build step. Subsequent starts are much faster.

---

## Step 6: Install the Plugin Dependencies

The `pb-split-guide` plugin has its own Composer dependencies (TCPDF for PDF certificates):

```bash
lando composer install --working-dir=web/app/plugins/pb-split-guide
```

---

## Step 7: Verify the Setup

1. **Frontend**: Open [https://pressbooks.test](https://pressbooks.test) — you should see the Pressbooks network catalog
2. **WP Admin**: Open [https://pressbooks.test/wp/wp-admin/](https://pressbooks.test/wp/wp-admin/)
3. **Network Admin**: Open [https://pressbooks.test/wp/wp-admin/network/](https://pressbooks.test/wp/wp-admin/network/)
4. **phpMyAdmin**: Open [http://localhost:8081](http://localhost:8081) (if port is mapped)
5. **Mailhog**: Open [http://localhost:8026](http://localhost:8026)

### Verify the Plugin

1. Go to **Network Admin → Plugins**
2. Confirm "PB Split Guide" is listed and network-activated
3. Create or open a test book site
4. Create a new page and assign the **"Split Guide"** template
5. Check the **Split Guide Steps** metabox appears in the page editor

---

## Running Tests

### PHPUnit (181 tests)

```bash
# Full suite with testdox output
lando phpunit --configuration phpunit.xml --testdox

# Unit tests only
lando phpunit --testsuite "UPEI Guide-on-the-Side Unit Tests"

# Integration tests only
lando phpunit --testsuite "UPEI Guide-on-the-Side Integration Smoke Tests"

# Via composer script
lando test

# Without Lando (if PHP/Composer installed locally)
vendor/bin/phpunit --configuration phpunit.xml --testdox
```

Test results output to `build/logs/junit.xml` for CI.

### Jest (JavaScript tests)

```bash
lando npm test
```

### Install Test Dependencies (first time)

```bash
lando install-tests
```

---

## Daily Usage Commands

All commands run from the repository root (`guide-on-the-side/`).

### Starting and Stopping

```bash
lando start          # Start all services
lando stop           # Stop services (preserves data)
lando restart        # Restart all services
lando rebuild        # Rebuild from scratch (re-runs build steps)
lando destroy        # Remove containers and volumes (data loss!)
```

### Development Tools

```bash
lando php <args>           # Run PHP CLI
lando composer <args>      # Run Composer
lando npm <args>           # Run npm
lando node <args>          # Run Node.js
lando phpunit <args>       # Run PHPUnit
lando test                 # Run composer test script
```

### Database

```bash
# Import a SQL dump
lando db-import-custom /app/path/to/dump.sql

# Direct MySQL access
lando mysql

# Export database
lando db-export dump.sql
```

### Logs and Debugging

```bash
lando logs              # All service logs
lando logs -s appserver # PHP/Nginx logs only
lando logs -s database  # MariaDB logs only
lando ssh               # Shell into the app container
lando info              # Show all service URLs and ports
```

### What Persists vs. What Doesn't

| Persists across `lando stop` / `lando start` | Persists across `lando rebuild` | Destroyed by `lando destroy` |
|---|---|---|
| Database data | Database data | Everything |
| Composer vendor/ | — (re-runs `composer install`) | Everything |
| `.env` files | `.env` files | Everything |
| Uploaded files (`web/app/uploads/`) | Uploaded files | Everything |

---

## Access URLs and Credentials

### URLs

| Service | URL |
|---------|-----|
| Pressbooks (frontend) | https://pressbooks.test |
| WordPress Admin | https://pressbooks.test/wp/wp-admin/ |
| Network Admin | https://pressbooks.test/wp/wp-admin/network/ |
| Mailhog (email) | http://localhost:8026 |

> Admin credentials depend on your database dump. Ask a team member for the current credentials.

### Database

| Setting | Value |
|---------|-------|
| Host | `database` (from within containers) |
| Port | `32778` (from host machine) |
| Database | `pressbooks_oss` |
| User | `pressbooks_oss_user` |
| Password | `secretpassword` |

Connect from a GUI tool (e.g., TablePlus, DBeaver):
- Host: `127.0.0.1`
- Port: `32778`

---

## Services

| Service | Technology | Port (Host) | Notes |
|---------|-----------|-------------|-------|
| App Server | PHP 8.3 + Nginx | 80/443 via `pressbooks.test` | XDebug enabled |
| Database | MariaDB 10.5.23 | 32778 | Persistent volume |
| Redis | Redis 5.0 | 6380 | Optional object cache |
| Node | Node.js (latest) | — | npm/Jest tooling |
| Mailhog | Mailhog 1.0.1 | 8026 | Captures outgoing email |

---

## Repository Structure

```
guide-on-the-side/
├── .lando.yml                          # Lando configuration
├── .env.example                        # Environment template
├── .env                                # Local env (gitignored)
├── composer.json / composer.lock       # Root dependencies
├── package.json                        # Node/Jest config
├── phpunit.xml                         # PHPUnit config
├── pb_local_db.sql                     # Database dump (gitignored)
│
├── config/
│   └── application.php                 # Bedrock WP configuration
│
├── config_services/
│   ├── php.ini                         # PHP/XDebug settings
│   ├── nginx.conf                      # Nginx vhost config
│   ├── my.cnf                          # MariaDB config
│   ├── .env                            # Service-level env (gitignored)
│   └── .env.example                    # Service env template
│
├── scripts/
│   ├── import_db.sh                    # Database import (used by Lando)
│   ├── prepare_test_environment.sh     # PHPUnit test DB setup
│   ├── prepare_acceptance_tests_environment.sh
│   └── pressbooks_required_libraries.sh  # Build-time deps (Java, epubcheck, etc.)
│
├── web/
│   ├── wp/                             # WordPress core (Composer-managed)
│   └── app/
│       ├── plugins/
│       │   ├── pb-split-guide/         # ← THE PLUGIN (our code)
│       │   │   ├── pb-split-guide.php          # Entry point
│       │   │   ├── class-pbsg-analytics.php    # Analytics engine
│       │   │   ├── class-pbsg-analytics-dashboard.php
│       │   │   ├── includes/                   # Helper classes
│       │   │   ├── templates/                  # Page templates
│       │   │   ├── assets/                     # CSS/JS
│       │   │   ├── accessibility-dashboard/    # A11y features
│       │   │   └── vendor/                     # Plugin Composer deps (TCPDF)
│       │   ├── pressbooks/             # Pressbooks core plugin
│       │   ├── h5p/                    # H5P quiz plugin
│       │   └── pressbooks-*/           # SSO, catalog, etc.
│       ├── themes/                     # Aldine, Clarke, Donham, Jacobs
│       ├── mu-plugins/                 # Must-use plugins (Bedrock)
│       └── uploads/                    # Media uploads
│
├── tests/
│   ├── bootstrap.php                   # Test bootstrap (loads stubs)
│   ├── Unit/                           # 8 test classes
│   └── Integration/                    # Smoke tests
│
├── docs/                               # Project documentation
│   ├── DEV-SETUP-GUIDE.md              # ← You are here
│   ├── DEPLOYMENT-STAGING.md
│   ├── TUTORIAL-DATA-MODEL.md
│   ├── TUTORIAL-STORAGE-SYSTEM.md
│   └── ...
│
├── .github/workflows/ci.yml           # CI pipeline
└── CLAUDE.local.md                     # AI context (gitignored)
```

---

## XDebug (IDE Debugging)

XDebug is pre-configured and enabled. To use it with your IDE:

### VS Code

Install the **PHP Debug** extension, then add to `.vscode/launch.json`:

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Lando XDebug",
      "type": "php",
      "request": "launch",
      "port": 9003,
      "pathMappings": {
        "/app/": "${workspaceFolder}/"
      },
      "hostname": "0.0.0.0"
    }
  ]
}
```

### PHPStorm

1. **Settings → PHP → Servers**: Add server named `appserver`, host `pressbooks.test`, port 443, debugger Xdebug
2. Map `/app/` → your project root
3. **Settings → PHP → Debug**: Port `9003`
4. Click **Start Listening for PHP Debug Connections**

### Configuration Reference

The XDebug settings in `config_services/php.ini`:

```ini
xdebug.mode=debug
xdebug.client_port=9003
xdebug.start_with_request=yes
xdebug.max_nesting_level=256
```

---

## Git Workflow

### Branch Strategy (Gitflow)

| Branch | Purpose | Merges Into |
|--------|---------|-------------|
| `main` | Production (protected) | — |
| `develop` | Integration (protected) | `main` via release |
| `feature/{issue#}-{desc}` | New features | `develop` |
| `bugfix/{issue#}-{desc}` | Bug fixes | `develop` |
| `hotfix/{issue#}-{desc}` | Urgent fixes | `main` |
| `release/*` | Release prep | `main` |

### Commit Convention

Format: `type(scope): description`

**Types**: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `chore`, `perf`, `ci`

**Scopes**: `editor`, `student`, `quiz`, `embed`, `auth`, `api`, `ui`, `db`, `a11y`, `config`, `analytics`, `settings`

Examples:
```
feat(analytics): add retry tracking columns
fix(admin): reset sliders to site defaults
docs(setup): update dev environment guide
```

### Pull Requests

- Target: `main` (for releases) or `develop` (for features)
- Strategy: **Squash and Merge**
- Requirements: 1 reviewer + CI passing

---

## CI/CD Pipeline

The GitHub Actions pipeline (`.github/workflows/ci.yml`) runs on:
- Push to `main`
- Pull requests against `main`

### Pipeline Steps

1. Checkout code
2. Set up PHP 8.3 (extensions: mbstring, xml, ctype, iconv, mysql, dom)
3. `composer install`
4. Run PHPUnit with JUnit output
5. Archive test logs as artifact (`phpunit-test-log`)
6. Auto-comment on PR with test results

---

## Troubleshooting

### SSL Certificate Warning

Lando uses self-signed certificates. Your browser will warn about this on first visit to `https://pressbooks.test`. Click **Advanced → Proceed** (or add an exception).

### Secure Cookie Rejection

`config/application.php` sets `$_SERVER['HTTPS'] = 'on'`, which can cause secure cookie rejection over plain HTTP. **Workarounds**:
- Use `https://pressbooks.test` (recommended)
- Use Firefox or incognito mode if cookies aren't sticking
- This is a known issue — Daniel should fix server-side for production

### "pressbooks.test" Doesn't Resolve

Lando adds the proxy domain automatically on most systems. If it doesn't resolve:

```bash
# macOS/Linux: Add manually to /etc/hosts
echo "127.0.0.1 pressbooks.test" | sudo tee -a /etc/hosts

# Verify
ping pressbooks.test
```

### Port Conflicts

If port 32778 (MariaDB) or 8026 (Mailhog) is in use:

```bash
# Check what's using the port
lsof -i :32778
lsof -i :8026

# Change ports in .lando.yml under services → database → portforward / mailhog → portforward
```

### Lando Build Fails

```bash
# View detailed build output
lando rebuild --debug

# Common fixes
lando destroy        # Clean slate
lando start          # Fresh build
```

### Composer Memory Errors

```bash
# Increase PHP memory for Composer
lando php -d memory_limit=-1 /usr/local/bin/composer install
```

### Database Connection Errors

```bash
# Verify database is running
lando info | grep database

# Re-import the database
lando db-import-custom /app/pb_local_db.sql
```

### PHPUnit Fails on First Run

```bash
# Install test environment first
lando install-tests

# Then run tests
lando phpunit --configuration phpunit.xml --testdox
```

### TCPDF Warning in Tests

The test bootstrap suppresses a benign `file_exists()` deprecation warning from TCPDF. This is expected — if you see it, the suppression may need updating.

### Manual WordPress Setup (Without DB Dump)

If you don't have `pb_local_db.sql`, you'll need to configure WordPress manually:

1. `lando start` (skip the DB import error)
2. Visit `https://pressbooks.test` and complete the WordPress installer
3. Enable Multisite in `config/application.php` (already configured in Bedrock)
4. Network-activate Pressbooks and H5P via **Network Admin → Plugins**
5. Create a test book site via **Network Admin → Sites → Add New**
6. Activate the `pb-split-guide` plugin on your test site

This gives you a clean environment but without the team's shared test content.

### Reset Everything

```bash
# Remove containers but keep code
lando destroy

# Start fresh
lando start
```

**Warning**: `lando destroy` deletes all database data and container state.

---

## Notes

**Tested Environments**: This guide has been verified on macOS (Apple Silicon) and Linux (Arch-based) with Lando 3.21+ and Docker Desktop 4.x.

**AI Disclosure**: This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
