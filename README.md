# Guide on the Side - Interactive Tutorial System

A WordPress/Pressbooks plugin (`pb-split-guide`) that lets librarians create split-screen tutorials where instructional content and H5P quizzes appear on the left pane, and embedded library resources (YouTube, databases, PDFs) appear on the right — all without requiring student logins.

Built for **UPEI Library** as a free, accessible, PIPEDA-compliant alternative to the now-abandoned [University of Arizona Guide on the Side](https://ualibraries.github.io/Guide-on-the-Side/about.html) and the commercial [LibWizard by SpringShare](https://www.springshare.com/libwizard).

## Features

- **Split-screen interface**: Step-by-step instructions and H5P quizzes on the left, live embedded resources on the right
- **Multi-source embedding**: YouTube (auto-converted to embed format), library databases via iframe, uploaded PDFs, and web URLs
- **H5P quiz integration**: Multiple choice, fill-in-the-blank, and interactive content with xAPI event tracking
- **Privacy-first analytics**: Aggregate-only dashboard with zero PII — fully PIPEDA compliant
- **Certificate generation**: PDF completion certificates via TCPDF
- **Accessibility compliance**: WCAG 2.1 AA — keyboard navigation, ARIA labels, screen reader support

## Technology Stack

| Layer | Technology |
|-------|------------|
| CMS | WordPress Multisite + Pressbooks |
| Plugin | `pb-split-guide` (this repo) |
| Backend | PHP 8.3, MariaDB |
| Frontend | HTML, CSS (BEM), JavaScript (jQuery) |
| Interactive Content | H5P |
| Local Dev | Lando / Docker |
| CI/CD | GitHub Actions + PHPUnit |
| Version Control | Git (Gitflow) |

## Team Members

| Name | Role | Focus Areas |
|------|------|-------------|
| Qi Xiang Phang | Communication Rep & Developer | Analytics, dashboard, documentation |
| Daniel McGrath | Tech Lead | Deployment, backup/versioning, tutorial storage |
| Yang Guo (Cindy) | Developer | Admin UI, multi-source embeds, H5P integration, certificates |
| Xiaohan Yu (Reagan) | Developer | CI/CD, testing framework, automated reporting |
| Caleb Jones | Developer | Accessibility audits, WCAG compliance, custom themes |

### Past Contributors

| Name | Role | Contribution |
|------|------|--------------|
| Tanguy Merrien | Team Lead (Fall 2024) | Project coordination, Jira board setup, initial architecture |

**Project Advisor**: Dr. David LeBlanc, UPEI Computer Science

## Getting Started

### Prerequisites

- [Lando](https://lando.dev/) (includes Docker)
- Composer
- Git

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/qixiang03/guide-on-the-side/
   cd guide-on-the-side
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env with your local database credentials
   ```

4. **Start the Lando environment**
   ```bash
   lando start
   ```

5. **Install plugin dependencies**
   ```bash
   cd web/app/plugins/pb-split-guide
   composer install
   cd ../../../..
   ```

6. **Access the application**
   - WordPress: `http://localhost:8080`
   - WordPress Admin: `http://localhost:8080/wp/wp-admin/`
   - phpMyAdmin: `http://localhost:8081`

For detailed setup instructions, see [docs/DEV-SETUP-GUIDE.md](docs/DEV-SETUP-GUIDE.md).

## Project Structure

```
guide-on-the-side/
├── web/app/plugins/pb-split-guide/   # The plugin (all source code)
│   ├── pb-split-guide.php            # Entry point, hooks registration
│   ├── class-pbsg-analytics.php      # Analytics tracking & data API
│   ├── class-pbsg-analytics-dashboard.php  # Admin dashboard rendering
│   ├── includes/                     # PHP classes
│   │   ├── steps-normalizer.php      # Step data validation/migration
│   │   └── class-pbsg-certificate.php # PDF certificate generation
│   ├── templates/                    # Page templates
│   │   └── split-guide-template.php  # Student-facing split-screen view
│   └── assets/                       # CSS and JavaScript
│       ├── split-guide.css           # Frontend styles
│       ├── split-guide.js            # Frontend interaction logic
│       ├── split-guide-tracker.js    # Analytics event tracking
│       ├── analytics-dashboard.js    # Admin dashboard charts
│       ├── analytics-dashboard.css   # Dashboard styles
│       └── admin/                    # Admin editor assets
├── tests/                            # PHPUnit test suite
│   ├── bootstrap.php                 # Test bootstrap (WP stubs)
│   ├── Unit/                         # Unit tests
│   └── Integration/                  # Integration smoke tests
├── docs/                             # Project documentation
├── .github/workflows/                # GitHub Actions CI/CD
├── .lando.yml                        # Lando local dev config
├── phpunit.xml                       # PHPUnit configuration
└── composer.json                     # Root Composer config
```

## Development Workflow

See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed contribution guidelines.

### Quick Reference

- **Main branch**: `main` — Production-ready code
- **Development branch**: `develop` — Integration branch for features
- **Feature branches**: `feature/{issue#}-{description}` — New features
- **Bugfix branches**: `bugfix/{issue#}-{description}` — Bug fixes
- **Hotfix branches**: `hotfix/{issue#}-{description}` — Urgent production fixes
- **Merge strategy**: Squash and Merge

## Documentation

- [Dev Environment Setup](docs/DEV-SETUP-GUIDE.md)
- [Deployment & Staging](docs/DEPLOYMENT-STAGING.md)
- [Tutorial Data Model](docs/TUTORIAL-DATA-MODEL.md)
- [Tutorial Storage System](docs/TUTORIAL-STORAGE-SYSTEM.md)
- [Backup & Versioning Policy](docs/BACKUP-VERSIONING-POLICY.md)
- [Migration Guide](docs/MIGRATION-GUIDE.md)
- [Testing Log](docs/TESTING_LOG.md)

## Testing

```bash
# Run all tests (via Lando)
lando phpunit --configuration phpunit.xml --testdox

# Run unit tests only
lando phpunit

# Run integration smoke tests
lando phpunit --configuration phpunit.xml --testsuite "UPEI Guide-on-the-Side Integration Smoke Tests" --testdox

# Run locally (without Lando)
vendor/bin/phpunit --configuration phpunit.xml --testdox
```

## Deployment

See [docs/DEPLOYMENT-STAGING.md](docs/DEPLOYMENT-STAGING.md) for deployment instructions.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- UPEI Library (Melissa Belvadi) for project requirements and guidance
- University of Arizona for the original Guide on the Side concept
- Dr. David LeBlanc for project supervision

## Contact

For questions about this project, please contact through Prof. LeBlanc at UPEI.
