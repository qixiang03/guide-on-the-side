# Guide on the Side — Interactive Tutorial System

A WordPress/Pressbooks plugin for UPEI Library that enables librarians to create split-screen tutorials with instructional content and quizzes on the left pane and embedded library resources on the right.

## Quick Start

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- Git

### Setup (5 minutes)

```bash
# 1. Clone the repo
git clone https://github.com/qixiang03/guide-on-the-side.git
cd guide-on-the-side

# 2. Start Docker containers
cd docker
docker compose up -d

# 3. Run the automated setup (installs Multisite + Pressbooks + plugin)
chmod +x setup.sh
./setup.sh
```

### Access

| Service     | URL                          | Credentials          |
|-------------|------------------------------|----------------------|
| WordPress   | http://localhost:8080        | admin / admin_password |
| Network Admin | http://localhost:8080/wp-admin/network/ | same |
| phpMyAdmin  | http://localhost:8081        | pressbooks / pressbooks_pw |

### Development Workflow

Your plugin code is **bind-mounted** — edit files in `plugin/pb-split-guide/` and changes appear immediately in the running container. No rebuild needed.

```bash
# Stop containers
cd docker && docker compose down

# Start containers
cd docker && docker compose up -d

# Reset everything (nuclear option)
cd docker && docker compose down -v && docker compose up -d && ./setup.sh
```

## Repository Structure

```
guide-on-the-side/
├── docker/                    # Docker infrastructure
│   ├── docker-compose.yml     # WP Multisite + MariaDB + phpMyAdmin
│   └── setup.sh               # Automated Pressbooks setup
├── plugin/                    # Plugin source code (the product)
│   └── pb-split-guide/
│       ├── pb-split-guide.php # Main plugin file
│       ├── includes/          # PHP classes
│       ├── assets/            # CSS + JS
│       ├── blocks/            # Gutenberg blocks
│       └── templates/         # Template overrides
├── docs/                      # Project documentation
├── .github/                   # PR template
├── CONTRIBUTING.md            # Git workflow + coding standards
└── README.md                  # This file
```

## Technology Stack

| Layer       | Technology                        |
|-------------|-----------------------------------|
| CMS         | WordPress Multisite + Pressbooks  |
| Plugin      | PHP 8.2, Gutenberg Blocks (React) |
| Frontend    | HTML, CSS (Flexbox), JavaScript   |
| Database    | MariaDB 10.6                      |
| Dev Environment | Docker                        |
| Version Control | Git / GitHub (Gitflow)        |

## Key Features

- **Split-screen interface** — Instructions/quizzes on the left, live library resources on the right
- **Gutenberg block editor** — Librarians use the standard WordPress editor
- **Quiz system** — Multiple choice, checkbox, yes/no with immediate feedback
- **Pressbooks integration** — Works within the Pressbooks publishing environment
- **WCAG 2.1 AA accessible** — Keyboard navigation, screen reader support, color contrast

## Team

| Name           | Role        |
|----------------|-------------|
| Yang Guo       | Developer   |
| Qi Xiang Phang | Developer   |
| Xiaohan Yu     | Developer   |
| Daniel McGrath | Developer   |
| Caleb Jones    | Developer   |

**Project Advisor**: Dr. David LeBlanc  
**Client**: Melissa Belvadi, UPEI Library

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for branch naming conventions, commit format, and PR process.

## License

MIT — see [LICENSE](LICENSE).