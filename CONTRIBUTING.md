# Contributing to Guide on the Side

Thank you for contributing to the Guide on the Side Interactive Tutorial System. This document outlines our development workflow, branch naming conventions, and contribution guidelines.

## Table of Contents

- [Branch Naming Conventions](#branch-naming-conventions)
- [Git Workflow](#git-workflow)
- [Commit Message Format](#commit-message-format)
- [Pull Request Process](#pull-request-process)
- [Code Review Guidelines](#code-review-guidelines)
- [Coding Standards](#coding-standards)

---

## Branch Naming Conventions

We follow a modified GitFlow workflow with the following branch types:

### Main Branches

| Branch | Purpose | Protected |
|--------|---------|-----------|
| `main` | Production-ready code. Deployed to production server. | Yes |
| `develop` | Integration branch for features. All feature branches merge here first. | Yes |

### Supporting Branches

| Branch Type | Naming Pattern | Purpose | Merges To |
|-------------|----------------|---------|-----------|
| Feature | `feature/[issue#]-[short-description]` | New features and enhancements | `develop` |
| Bugfix | `bugfix/[issue#]-[short-description]` | Bug fixes for develop branch | `develop` |
| Hotfix | `hotfix/[issue#]-[short-description]` | Urgent fixes for production | `main` and `develop` |
| Release | `release/v[X.Y.Z]` | Release preparation | `main` and `develop` |
| Docs | `docs/[short-description]` | Documentation updates | `develop` |
| Test | `test/[short-description]` | Test additions/improvements | `develop` |

### Naming Rules

1. **Use lowercase letters only**
2. **Use hyphens (-) to separate words** (not underscores or spaces)
3. **Keep names short but descriptive** (max 50 characters)
4. **Always include issue number** when applicable
5. **Use present tense** for descriptions

### Examples

```
✅ Good branch names:
feature/42-quiz-multiple-choice
bugfix/78-iframe-loading-error
hotfix/91-login-security-patch
docs/update-api-reference
release/v1.2.0

❌ Bad branch names:
Feature/Quiz                    # Wrong: uppercase, too vague
feature_quiz_system            # Wrong: underscores
fix-bug                        # Wrong: no issue number, too vague
feature/implement-the-new-quiz-system-with-all-question-types  # Wrong: too long
```

---

## Git Workflow

### Starting a New Feature

```bash
# 1. Ensure you have the latest develop branch
git checkout develop
git pull origin develop

# 2. Create your feature branch
git checkout -b feature/[issue#]-[description]

# 3. Make your changes, commit frequently
git add .
git commit -m "feat: add quiz question builder component"

# 4. Push your branch
git push -u origin feature/[issue#]-[description]

# 5. Create a Pull Request to develop
```

### Keeping Your Branch Updated

```bash
# Regularly sync with develop to avoid conflicts
git checkout develop
git pull origin develop
git checkout feature/[your-branch]
git merge develop
```

### Branch Lifecycle

```
main ─────────────────────────────────────────────────────►
       │                              ▲
       │                              │ (merge after release)
       ▼                              │
develop ──┬──────────────────────────────────────────────►
          │         ▲         ▲
          │         │         │
          ▼         │         │
    feature/42-quiz─┘         │
                              │
    feature/43-embed──────────┘
```

---

## Commit Message Format

We follow the [Conventional Commits](https://www.conventionalcommits.org/) specification.

### Format

```
<type>(<scope>): <description>

[optional body]

[optional footer(s)]
```

### Types

| Type | Description |
|------|-------------|
| `feat` | New feature |
| `fix` | Bug fix |
| `docs` | Documentation changes |
| `style` | Code style changes (formatting, semicolons, etc.) |
| `refactor` | Code refactoring (no feature or fix) |
| `test` | Adding or updating tests |
| `chore` | Maintenance tasks (dependencies, config, etc.) |
| `perf` | Performance improvements |
| `ci` | CI/CD configuration changes |

### Scopes (Optional)

| Scope | Description |
|-------|-------------|
| `editor` | Librarian tutorial editor |
| `student` | Student learning interface |
| `quiz` | Quiz system components |
| `embed` | Embedded content management |
| `auth` | Authentication and authorization |
| `api` | Backend API |
| `ui` | User interface components |
| `db` | Database schema or queries |
| `a11y` | Accessibility |
| `config` | Configuration |

### Examples

```bash
# Feature
feat(quiz): add multiple choice question builder

# Bug fix
fix(embed): resolve iframe loading timeout issue

Fixes #78

# Documentation
docs: update installation instructions for Docker

# Breaking change
feat(api)!: change tutorial endpoint response format

BREAKING CHANGE: Tutorial API now returns nested page objects
```

---

## Pull Request Process

### Before Creating a PR

1. [ ] Ensure your branch is up to date with `develop`
2. [ ] Run all tests locally: `npm test`
3. [ ] Check code style: `npm run lint`
4. [ ] Update documentation if needed
5. [ ] Add/update tests for new functionality

### PR Title Format

Use the same format as commit messages:

```
feat(quiz): implement checkbox question type
fix(embed): handle X-Frame-Options error gracefully
```

### PR Description Template

Your PR description should include:

- **Summary**: What does this PR do?
- **Related Issue**: Link to the GitHub issue (e.g., `Closes #42`)
- **Changes Made**: List of specific changes
- **Testing**: How was this tested?
- **Screenshots**: If UI changes, include before/after screenshots

### Review Requirements

- Minimum **1 reviewer** approval required
- All CI checks must pass
- No merge conflicts

### Merge Strategy

We use **Squash and Merge** for feature branches to keep the main history clean.

---

## Code Review Guidelines

### For Authors

- Keep PRs small and focused (< 400 lines when possible)
- Respond to feedback promptly
- Mark conversations as resolved when addressed

### For Reviewers

- Review within 24 hours when possible
- Be constructive and specific
- Approve when ready, request changes when blocking issues exist
- Use suggestions feature for small fixes

### Review Checklist

- [ ] Code follows project coding standards
- [ ] Tests are included and passing
- [ ] Documentation is updated
- [ ] No security vulnerabilities introduced
- [ ] Accessibility requirements met (WCAG 2.1 AA)
- [ ] Performance impact considered

---

## Coding Standards

### PHP (PSR-12)

```php
<?php

namespace App\Tutorial;

class QuizBuilder
{
    public function createQuestion(string $type, array $options): Question
    {
        // Implementation
    }
}
```

### JavaScript (Airbnb Style Guide)

```javascript
// Use const/let, not var
const tutorialId = 42;

// Use arrow functions for callbacks
slides.forEach((slide) => {
  renderSlide(slide);
});

// Use template literals
const message = `Tutorial ${tutorialId} saved successfully`;
```

### CSS (BEM Methodology)

```css
/* Block */
.quiz-question { }

/* Element */
.quiz-question__option { }

/* Modifier */
.quiz-question--multiple-choice { }
.quiz-question__option--selected { }
```

### HTML (Semantic & Accessible)

```html
<article class="tutorial-slide" aria-labelledby="slide-title">
  <h2 id="slide-title">Introduction to Library Search</h2>
  <section class="slide-content">
    <!-- Content -->
  </section>
</article>
```

---

## Questions?

If you have questions about contributing, please:

1. Check existing documentation
2. Search closed issues/PRs
3. Ask in the team chat
4. Contact the project maintainers

Thank you for contributing to Guide on the Side!
