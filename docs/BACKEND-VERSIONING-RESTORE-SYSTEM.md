# Guide on the Side - Backend Versioning & Restore System

**Author:** Daniel McGrath (Tech Lead)  
**Sprint:** 5

## Overview

This document defines the versioning system for tutorials, allowing librarians to view history and restore earlier versions of their content.

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| Store full snapshots | Simpler than diffs, easier to restore |
| JSON serialization | Captures entire tutorial + steps in one record |
| Automatic versioning | New version created on each publish/major save |
| Keep last N versions | Prevent unlimited storage growth |

## Database Schema

### wp_tutorial_versions

| Field | Type | Null | Key | Default | Description |
|-------|------|------|-----|---------|-------------|
| id | INT UNSIGNED | NO | PK | AUTO_INCREMENT | Unique identifier |
| tutorial_id | INT UNSIGNED | NO | FK | | Reference to tutorial |
| version_number | INT | NO | | | Sequential version (1, 2, 3...) |
| snapshot | LONGTEXT | NO | | | JSON snapshot of tutorial + steps |
| created_by | BIGINT UNSIGNED | NO | FK | | WordPress user who made the change |
| created_at | DATETIME | NO | | CURRENT_TIMESTAMP | When version was created |
| notes | VARCHAR(255) | YES | | NULL | Optional version notes |

### SQL

```sql
CREATE TABLE wp_tutorial_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tutorial_id INT UNSIGNED NOT NULL,
    version_number INT NOT NULL,
    snapshot LONGTEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(255),
    
    INDEX idx_tutorial_version (tutorial_id, version_number),
    FOREIGN KEY (tutorial_id) REFERENCES wp_tutorials(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES wp_users(ID)
);
```

## Snapshot Format

The `snapshot` field stores a JSON object containing the full tutorial state:

```json
{
    "tutorial": {
        "id": 1,
        "title": "Library Catalog Basics",
        "description": "Learn to search the catalog",
        "learning_objectives": "Find books, Use filters",
        "status": "published",
        "is_template": false
    },
    "steps": [
        {
            "id": 1,
            "title": "Introduction",
            "left_content": "<p>Welcome to this tutorial...</p>",
            "h5p_content_id": null,
            "iframe_url": null,
            "order_index": 1,
            "branch_to_step_id": null
        },
        {
            "id": 2,
            "title": "Finding Books",
            "left_content": "<p>Use the search bar...</p>",
            "h5p_content_id": 5,
            "iframe_url": "https://library.upei.ca/catalog",
            "order_index": 2,
            "branch_to_step_id": 4
        }
    ],
    "meta": {
        "version": 3,
        "created_at": "2026-03-03T14:30:00Z",
        "created_by": 1,
        "step_count": 5
    }
}
```

## How It Works

### Creating a Version

```
Librarian clicks "Publish" or "Save Major Changes"
    │
    ▼
Plugin serializes current tutorial + steps to JSON
    │
    ▼
Get next version number for this tutorial
    │
    ▼
Insert into wp_tutorial_versions
    │
    ▼
(Optional) Prune old versions if > max limit
```

### PHP Implementation

```php
function create_tutorial_version($tutorial_id, $notes = null) {
    global $wpdb;
    
    // Get current tutorial data
    $tutorial = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM wp_tutorials WHERE id = %d", $tutorial_id
    ), ARRAY_A);
    
    // Get current steps
    $steps = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM wp_tutorial_steps WHERE tutorial_id = %d ORDER BY order_index",
        $tutorial_id
    ), ARRAY_A);
    
    // Get next version number
    $version_number = $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(MAX(version_number), 0) + 1 FROM wp_tutorial_versions WHERE tutorial_id = %d",
        $tutorial_id
    ));
    
    // Create snapshot
    $snapshot = json_encode([
        'tutorial' => $tutorial,
        'steps' => $steps,
        'meta' => [
            'version' => $version_number,
            'created_at' => current_time('c'),
            'created_by' => get_current_user_id(),
            'step_count' => count($steps)
        ]
    ]);
    
    // Insert version record
    $wpdb->insert('wp_tutorial_versions', [
        'tutorial_id' => $tutorial_id,
        'version_number' => $version_number,
        'snapshot' => $snapshot,
        'created_by' => get_current_user_id(),
        'notes' => $notes
    ]);
    
    // Prune old versions (keep last 20)
    prune_old_versions($tutorial_id, 20);
    
    return $version_number;
}
```

### Viewing Version History

```php
function get_tutorial_versions($tutorial_id) {
    global $wpdb;
    
    return $wpdb->get_results($wpdb->prepare("
        SELECT v.id, v.version_number, v.created_at, v.notes, u.display_name as author
        FROM wp_tutorial_versions v
        JOIN wp_users u ON v.created_by = u.ID
        WHERE v.tutorial_id = %d
        ORDER BY v.version_number DESC
    ", $tutorial_id));
}
```

### Restoring a Version

```
Librarian selects version to restore
    │
    ▼
Create new version of CURRENT state (backup)
    │
    ▼
Load snapshot JSON from selected version
    │
    ▼
Delete current steps
    │
    ▼
Update tutorial record from snapshot
    │
    ▼
Insert steps from snapshot
    │
    ▼
Create new version noting "Restored from v{X}"
```

### PHP Restore Implementation

```php
function restore_tutorial_version($tutorial_id, $version_id) {
    global $wpdb;
    
    // Get the version to restore
    $version = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM wp_tutorial_versions WHERE id = %d AND tutorial_id = %d",
        $version_id, $tutorial_id
    ));
    
    if (!$version) {
        return new WP_Error('invalid_version', 'Version not found');
    }
    
    // Create backup of current state first
    create_tutorial_version($tutorial_id, 'Auto-backup before restore');
    
    // Parse snapshot
    $snapshot = json_decode($version->snapshot, true);
    
    // Start transaction
    $wpdb->query('START TRANSACTION');
    
    try {
        // Update tutorial
        $wpdb->update('wp_tutorials', [
            'title' => $snapshot['tutorial']['title'],
            'description' => $snapshot['tutorial']['description'],
            'learning_objectives' => $snapshot['tutorial']['learning_objectives'],
            'status' => $snapshot['tutorial']['status'],
            'is_template' => $snapshot['tutorial']['is_template']
        ], ['id' => $tutorial_id]);
        
        // Delete current steps
        $wpdb->delete('wp_tutorial_steps', ['tutorial_id' => $tutorial_id]);
        
        // Insert steps from snapshot
        foreach ($snapshot['steps'] as $step) {
            unset($step['id']); // Remove old ID
            $step['tutorial_id'] = $tutorial_id;
            $wpdb->insert('wp_tutorial_steps', $step);
        }
        
        $wpdb->query('COMMIT');
        
        // Create version noting the restore
        create_tutorial_version($tutorial_id, "Restored from version {$version->version_number}");
        
        return true;
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('restore_failed', $e->getMessage());
    }
}
```

## Version Pruning

Keep only the last N versions to manage storage:

```php
function prune_old_versions($tutorial_id, $keep = 20) {
    global $wpdb;
    
    $wpdb->query($wpdb->prepare("
        DELETE FROM wp_tutorial_versions 
        WHERE tutorial_id = %d 
        AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM wp_tutorial_versions 
                WHERE tutorial_id = %d 
                ORDER BY version_number DESC 
                LIMIT %d
            ) AS keep_versions
        )
    ", $tutorial_id, $tutorial_id, $keep));
}
```

## UI Considerations

### Version History Panel

```
┌─────────────────────────────────────────────────────┐
│ Version History                              [Close]│
├─────────────────────────────────────────────────────┤
│ v5 - Mar 3, 2026 2:30pm - Jane Doe                 │
│     "Updated quiz questions"              [Restore] │
├─────────────────────────────────────────────────────┤
│ v4 - Mar 2, 2026 10:00am - Jane Doe                │
│     "Added new step"                      [Restore] │
├─────────────────────────────────────────────────────┤
│ v3 - Mar 1, 2026 4:00pm - Admin                    │
│     "Initial publish"                     [Restore] │
└─────────────────────────────────────────────────────┘
```

## Configuration Options

| Setting | Default | Description |
|---------|---------|-------------|
| Max versions to keep | 20 | Older versions auto-deleted |
| Auto-version on publish | Yes | Create version when publishing |
| Auto-version on save | No | Only major saves, not every edit |

## Integration Notes

- Hook into tutorial save/publish actions
- Add "Version History" button to tutorial editor
- Add restore confirmation modal ("This will overwrite current content")

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
