# Project Board Setup Guide

This guide explains how to set up and use GitHub Projects for tracking tasks in the Guide on the Side project.

## Overview

We use GitHub Projects (or Trello as an alternative) to manage tasks, track progress, and coordinate team efforts across sprints.

## GitHub Projects Setup

### Step 1: Create the Project Board

1. Go to your GitHub repository
2. Click on **Projects** tab
3. Click **New project**
4. Select **Board** view
5. Name it: `Guide on the Side - Development Board`
6. Description: `Sprint task tracking for Guide on the Side Interactive Tutorial Builder`

### Step 2: Configure Columns

Create the following columns (in order):

| Column | Purpose | Card Behavior |
|--------|---------|---------------|
| **📋 Backlog** | All tasks not yet scheduled | Items move here when created |
| **🎯 Sprint Backlog** | Tasks planned for current sprint | Move from Backlog during sprint planning |
| **🔄 In Progress** | Tasks actively being worked on | Move when developer starts work |
| **👀 In Review** | Tasks awaiting code review | Move when PR is opened |
| **✅ Done** | Completed tasks | Move when PR is merged |

### Step 3: Create Labels

Navigate to **Issues** > **Labels** and create these labels:

#### Priority Labels
| Label | Color | Description |
|-------|-------|-------------|
| `priority: critical` | `#b60205` | Must be done immediately |
| `priority: high` | `#d93f0b` | Important, do this sprint |
| `priority: medium` | `#fbca04` | Should be done soon |
| `priority: low` | `#0e8a16` | Nice to have |

#### Type Labels
| Label | Color | Description |
|-------|-------|-------------|
| `type: feature` | `#1d76db` | New feature or enhancement |
| `type: bug` | `#d73a4a` | Something isn't working |
| `type: docs` | `#0075ca` | Documentation updates |
| `type: test` | `#7057ff` | Testing related |
| `type: refactor` | `#5319e7` | Code improvement |
| `type: chore` | `#666666` | Maintenance tasks |

#### Component Labels
| Label | Color | Description |
|-------|-------|-------------|
| `component: editor` | `#c2e0c6` | Tutorial editor |
| `component: student-ui` | `#c5def5` | Student interface |
| `component: quiz` | `#fef2c0` | Quiz system |
| `component: embed` | `#f9d0c4` | Embedded content |
| `component: auth` | `#e99695` | Authentication |
| `component: api` | `#bfdadc` | Backend API |
| `component: a11y` | `#d4c5f9` | Accessibility |

#### Status Labels
| Label | Color | Description |
|-------|-------|-------------|
| `needs-triage` | `#ededed` | Needs review/assignment |
| `blocked` | `#b60205` | Blocked by dependency |
| `help-wanted` | `#008672` | Extra attention needed |
| `good-first-issue` | `#7057ff` | Good for newcomers |

### Step 4: Create Milestones

Create milestones matching project sprints:

1. **Sprint 1: CMS Selection & Environment Setup**
   - Due: Jan 24, 2025
   
2. **Sprint 2: Core Editor MVP**
   - Due: Feb 7, 2025
   
3. **Sprint 3: Student Interface Prototype**
   - Due: Feb 21, 2025
   
4. **Sprint 4: Quiz System Implementation**
   - Due: Mar 7, 2025
   
5. **Sprint 5: Accessibility & Testing**
   - Due: Mar 28, 2025
   
6. **Sprint 6: Final Integration & Deployment**
   - Due: Apr 18, 2025

7. **Sprint 7: Final Presentation & Handoff**
   - Due: Apr 30, 2025

### Step 5: Set Up Automation (Optional)

In Project Settings > Workflows, enable:

- **Item added to project** → Set status to "Backlog"
- **Pull request merged** → Set status to "Done"
- **Issue closed** → Set status to "Done"
- **Pull request opened** → Set status to "In Review"

## Using the Project Board

### Sprint Planning Process

1. **Before Sprint Starts:**
   - Review backlog items
   - Prioritize tasks for upcoming sprint
   - Move selected items to "Sprint Backlog"
   - Assign team members
   - Set milestone to current sprint

2. **During Sprint:**
   - Move task to "In Progress" when starting
   - Update task comments with progress
   - Move to "In Review" when PR is opened
   - Link PR to issue

3. **Sprint Review:**
   - Move completed items to "Done"
   - Review incomplete items
   - Update backlog priorities

### Task Card Best Practices

Each task card should include:

```markdown
## Task Title

**Description:**
Brief description of what needs to be done.

**Acceptance Criteria:**
- [ ] Criterion 1
- [ ] Criterion 2
- [ ] Criterion 3

**Estimated Time:** X hours

**Dependencies:**
- Depends on #[issue number]

**Notes:**
Any additional context or resources.
```

### Assigning Tasks

- Each task should have **one primary assignee**
- Use **@mentions** in comments for collaboration
- Update assignment if task is transferred

### Linking Issues and PRs

Always link related items:

```markdown
Closes #42
Related to #15
Depends on #23
```

## Trello Alternative

If using Trello instead of GitHub Projects:

### Board Setup

1. Create board: `Guide on the Side - Development`
2. Create same columns as above
3. Enable Power-Ups:
   - GitHub (for PR linking)
   - Calendar (for deadlines)

### Card Template

Create a card template with:
- Description section
- Checklist for acceptance criteria
- Labels for priority/type
- Due date field
- Member assignment

### Integration

Use the GitHub Power-Up to:
- Link cards to issues/PRs
- See PR status on cards
- Auto-move cards based on PR status

## Team Member Responsibilities

### Project Lead
- Maintain backlog priorities
- Run sprint planning meetings
- Monitor overall progress

### Technical Lead
- Review technical tasks
- Approve architecture decisions
- Monitor code review queue

### All Team Members
- Update task status daily
- Comment on blockers immediately
- Keep cards up-to-date

## Reporting

### Sprint Burndown

Track progress using:
- Number of tasks completed vs planned
- Story points (if using)
- Hours logged vs estimated

### Weekly Updates

Prepare brief updates including:
- Tasks completed this week
- Tasks in progress
- Blockers or risks
- Plan for next week

---

## Quick Reference

### Moving Tasks

| Action | From | To |
|--------|------|-----|
| Sprint planning | Backlog | Sprint Backlog |
| Start working | Sprint Backlog | In Progress |
| Open PR | In Progress | In Review |
| PR merged | In Review | Done |
| Blocked | Any | Add `blocked` label |

### Issue Commands

```
/assign @username    - Assign user
/label priority:high - Add label
/milestone Sprint 1  - Set milestone
/close               - Close issue
```

---

**Questions?** Contact the project lead or discuss in team meetings.
