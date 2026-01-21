# Guide on the Side - Interactive Tutorial System

A web-based interactive tutorial system for UPEI Library that enables librarians to create split-screen tutorials with instructional content on the left and embedded library resources on the right.

## Project Overview

The Guide on the Side Interactive Tutorial System allows librarians to create self-paced learning modules that guide students through using library databases, catalogues, and research tools. The system features:

- **Split-screen interface**: Instructions and quizzes on the left, live library resources on the right
- **WYSIWYG editor**: Task-relevant design interface for librarians
- **Quiz functionality**: Multiple choice, checkbox, yes/no, and open-ended questions with immediate feedback
- **Template system**: Consistent look and feel with customizable overrides
- **Accessibility compliance**: WCAG 2.1 AA standards

## Technology Stack

- **CMS**: Pressbooks or Drupal 10 (under evaluation)
- **Backend**: PHP
- **Frontend**: HTML, CSS, JavaScript
- **Version Control**: Git/GitHub

## Team Members

| Name | Role |
|------|------|
| Yang Guo | Developer |
| Qi Xiang Phang | Developer |
| Xiaohan Yu | Developer |
| Daniel McGrath | Developer |
| Caleb Jones | Developer |

**Project Advisor**: Dr. David LeBlanc

## Getting Started

### Prerequisites

- PHP 8.1 or higher
- MySQL/MariaDB 8.0+
- Composer
- Node.js 18+ (for frontend tooling)
- Local server environment (XAMPP, MAMP, or Docker)

### Installation

1. **Clone the repository**
   ```bash
   git clone https://github.com/qixiang03/guide-on-the-side/
   cd guide-on-the-side
   ```

2. **Install dependencies**
   ```bash
   composer install
   npm install
   ```

3. **Configure environment**
   ```bash
   cp .env.example .env
   # Edit .env with your local database credentials
   ```

4. **Set up the database**
   ```bash
   # Import the database schema
   mysql -u [username] -p [database_name] < database/schema.sql
   ```

5. **Start the development server**
   ```bash
   # For XAMPP/MAMP: Place project in htdocs/www folder
   # For Docker: docker-compose up -d
   ```

6. **Access the application**
   - Development: `http://localhost/guide-on-the-side`
   - Admin panel: `http://localhost/guide-on-the-side/admin`

## Project Structure

```
guide-on-the-side/
├── docs/                    # Documentation
│   ├── api/                 # API documentation
│   ├── user-guide/          # User manual
│   └── technical/           # Technical documentation
├── src/                     # Source code
│   ├── backend/             # PHP backend code
│   ├── frontend/            # Frontend assets
│   └── modules/             # CMS modules/plugins
├── tests/                   # Test suites
│   ├── unit/                # Unit tests
│   ├── integration/         # Integration tests
│   └── e2e/                 # End-to-end tests
├── database/                # Database migrations and seeds
├── config/                  # Configuration files
└── public/                  # Public web root
```

## Development Workflow

See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed contribution guidelines.

### Quick Reference

- **Main branch**: `main` - Production-ready code
- **Development branch**: `develop` - Integration branch for features
- **Feature branches**: `feature/[feature-name]` - New features
- **Bugfix branches**: `bugfix/[issue-number]-[description]` - Bug fixes
- **Hotfix branches**: `hotfix/[issue-number]-[description]` - Urgent production fixes

## Documentation

- [Project Plan](docs/project-plan.md)
- [Feature List](docs/features.md)
- [API Documentation](docs/api/README.md)
- [User Guide](docs/user-guide/README.md)
- [Deployment Guide](docs/deployment.md)

## Testing

```bash
# Run all tests
npm test

# Run unit tests only
npm run test:unit

# Run with coverage
npm run test:coverage
```

## Deployment

See [docs/deployment.md](docs/deployment.md) for detailed deployment instructions.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- UPEI Library for project requirements and guidance
- University of Arizona for the original Guide on the Side concept
- Dr. David LeBlanc for project supervision

## Contact

For questions about this project, please contact through Prof. LeBlanc at UPEI.
