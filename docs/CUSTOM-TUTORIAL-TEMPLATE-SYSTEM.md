# Guide on the Side - Custom Tutorial Template System

**Author:** Daniel McGrath (Tech Lead)  
**Sprint:** 5

## Overview

This document defines the database schema and backend service for custom tutorial templates. Templates allow librarians to create reusable starting points for new tutorials with pre-configured layouts, styles, and default content.

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| Separate templates table | Templates have different lifecycle than tutorials |
| Style configuration as JSON | Flexible, extensible without schema changes |
| Template categories | Organize templates by use case |
| Clone on use | Creating from template copies data, doesn't reference |

## Database Schema

### wp_tutorial_templates

| Field | Type | Null | Key | Default | Description |
|-------|------|------|-----|---------|-------------|
| id | INT UNSIGNED | NO | PK | AUTO_INCREMENT | Unique identifier |
| name | VARCHAR(255) | NO | | | Template name |
| description | TEXT | YES | | NULL | Template description |
| category | VARCHAR(100) | YES | | NULL | Template category |
| thumbnail_url | VARCHAR(500) | YES | | NULL | Preview image |
| style_config | JSON | YES | | NULL | CSS/style settings |
| default_steps | JSON | YES | | NULL | Pre-configured steps |
| is_system | BOOLEAN | NO | | FALSE | System template (non-deletable) |
| is_active | BOOLEAN | NO | | TRUE | Available for use |
| created_by | BIGINT UNSIGNED | NO | FK | | Creator user ID |
| created_at | DATETIME | NO | | CURRENT_TIMESTAMP | Creation timestamp |
| updated_at | DATETIME | NO | | CURRENT_TIMESTAMP | Last update timestamp |

### SQL

```sql
CREATE TABLE wp_tutorial_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    thumbnail_url VARCHAR(500),
    style_config JSON,
    default_steps JSON,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_category (category),
    INDEX idx_active (is_active),
    FOREIGN KEY (created_by) REFERENCES wp_users(ID)
);
```

## Style Configuration Format

The `style_config` JSON field stores customizable CSS properties:

```json
{
    "layout": {
        "split_ratio": "50-50",
        "left_panel_position": "left",
        "show_progress_bar": true,
        "show_step_menu": true
    },
    "colors": {
        "primary": "#1e4d8c",
        "secondary": "#6c757d",
        "background": "#ffffff",
        "text": "#333333",
        "accent": "#28a745",
        "error": "#dc3545"
    },
    "typography": {
        "font_family": "Open Sans, sans-serif",
        "heading_font": "Roboto, sans-serif",
        "base_font_size": "16px",
        "line_height": "1.6"
    },
    "spacing": {
        "panel_padding": "20px",
        "element_gap": "15px"
    },
    "branding": {
        "logo_url": "/wp-content/uploads/library-logo.png",
        "show_logo": true,
        "logo_position": "top-left"
    }
}
```

## Default Steps Format

The `default_steps` JSON field stores pre-configured step templates:

```json
[
    {
        "title": "Introduction",
        "left_content": "<h2>Welcome!</h2><p>In this tutorial, you will learn...</p>",
        "h5p_content_id": null,
        "iframe_url": null,
        "order_index": 1
    },
    {
        "title": "Step 1",
        "left_content": "<p>Instructions go here...</p>",
        "h5p_content_id": null,
        "iframe_url": "{{LIBRARY_CATALOG_URL}}",
        "order_index": 2
    },
    {
        "title": "Knowledge Check",
        "left_content": "<p>Let's test what you've learned.</p>",
        "h5p_content_id": null,
        "iframe_url": null,
        "order_index": 3
    },
    {
        "title": "Summary",
        "left_content": "<h2>Great job!</h2><p>You have completed this tutorial.</p>",
        "h5p_content_id": null,
        "iframe_url": null,
        "order_index": 4
    }
]
```

### Template Variables

Default steps can include variables that get replaced on creation:

| Variable | Replaced With |
|----------|---------------|
| `{{LIBRARY_CATALOG_URL}}` | Library catalog URL from settings |
| `{{TUTORIAL_TITLE}}` | User-entered tutorial title |
| `{{AUTHOR_NAME}}` | Current user's display name |
| `{{CURRENT_DATE}}` | Current date |

## System Templates

Pre-installed templates that cannot be deleted:

| Name | Category | Description |
|------|----------|-------------|
| Split Guide (Default) | Basic | Standard two-pane layout |
| Quiz Focus | Assessment | Emphasis on quiz content |
| Resource Explorer | Research | For exploring library resources |
| Quick Tip | Basic | Single-step micro-tutorial |

### Insert System Templates

```sql
INSERT INTO wp_tutorial_templates (name, description, category, style_config, default_steps, is_system, created_by) VALUES
('Split Guide (Default)', 'Standard two-pane tutorial layout', 'Basic', 
 '{"layout":{"split_ratio":"50-50"},"colors":{"primary":"#1e4d8c"}}',
 '[{"title":"Introduction","left_content":"<p>Welcome!</p>","order_index":1}]',
 TRUE, 1),
('Quiz Focus', 'Emphasis on assessment and quizzes', 'Assessment',
 '{"layout":{"split_ratio":"40-60"},"colors":{"primary":"#28a745"}}',
 '[{"title":"Instructions","left_content":"<p>Read carefully...</p>","order_index":1}]',
 TRUE, 1);
```

## API Functions

### Get Available Templates

```php
function get_tutorial_templates($category = null) {
    global $wpdb;
    
    $sql = "SELECT * FROM wp_tutorial_templates WHERE is_active = 1";
    
    if ($category) {
        $sql .= $wpdb->prepare(" AND category = %s", $category);
    }
    
    $sql .= " ORDER BY is_system DESC, name ASC";
    
    return $wpdb->get_results($sql);
}
```

### Create Tutorial from Template

```php
function create_tutorial_from_template($template_id, $title, $description = '') {
    global $wpdb;
    
    // Get template
    $template = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM wp_tutorial_templates WHERE id = %d AND is_active = 1",
        $template_id
    ));
    
    if (!$template) {
        return new WP_Error('invalid_template', 'Template not found');
    }
    
    // Create tutorial
    $wpdb->insert('wp_tutorials', [
        'title' => $title,
        'description' => $description,
        'status' => 'draft',
        'is_template' => false,
        'created_by' => get_current_user_id()
    ]);
    
    $tutorial_id = $wpdb->insert_id;
    
    // Create steps from template
    $default_steps = json_decode($template->default_steps, true) ?: [];
    
    foreach ($default_steps as $step) {
        // Replace template variables
        $step['left_content'] = replace_template_variables($step['left_content'], [
            'TUTORIAL_TITLE' => $title,
            'AUTHOR_NAME' => wp_get_current_user()->display_name,
            'CURRENT_DATE' => date('F j, Y')
        ]);
        
        $wpdb->insert('wp_tutorial_steps', [
            'tutorial_id' => $tutorial_id,
            'title' => $step['title'],
            'left_content' => $step['left_content'],
            'h5p_content_id' => $step['h5p_content_id'],
            'iframe_url' => $step['iframe_url'],
            'order_index' => $step['order_index']
        ]);
    }
    
    // Store style config reference
    if ($template->style_config) {
        update_post_meta($tutorial_id, '_style_config', $template->style_config);
    }
    
    return $tutorial_id;
}

function replace_template_variables($content, $vars) {
    foreach ($vars as $key => $value) {
        $content = str_replace("{{{$key}}}", $value, $content);
    }
    return $content;
}
```

### Save Tutorial as Template

```php
function save_tutorial_as_template($tutorial_id, $template_name, $category = null) {
    global $wpdb;
    
    // Get tutorial
    $tutorial = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM wp_tutorials WHERE id = %d",
        $tutorial_id
    ));
    
    // Get steps
    $steps = $wpdb->get_results($wpdb->prepare(
        "SELECT title, left_content, h5p_content_id, iframe_url, order_index 
         FROM wp_tutorial_steps WHERE tutorial_id = %d ORDER BY order_index",
        $tutorial_id
    ), ARRAY_A);
    
    // Get style config if exists
    $style_config = get_post_meta($tutorial_id, '_style_config', true);
    
    // Create template
    $wpdb->insert('wp_tutorial_templates', [
        'name' => $template_name,
        'description' => $tutorial->description,
        'category' => $category,
        'style_config' => $style_config ?: null,
        'default_steps' => json_encode($steps),
        'is_system' => false,
        'created_by' => get_current_user_id()
    ]);
    
    return $wpdb->insert_id;
}
```

## Template Selection UI

```
┌─────────────────────────────────────────────────────────────┐
│ Create New Tutorial                                         │
├─────────────────────────────────────────────────────────────┤
│ Choose a Template:                                          │
│                                                             │
│ ┌─────────────┐  ┌─────────────┐  ┌─────────────┐          │
│ │   [image]   │  │   [image]   │  │   [image]   │          │
│ │             │  │             │  │             │          │
│ │ Split Guide │  │ Quiz Focus  │  │  Resource   │          │
│ │  (Default)  │  │             │  │  Explorer   │          │
│ └─────────────┘  └─────────────┘  └─────────────┘          │
│                                                             │
│ Category: [All ▼]                                           │
│                                                             │
│ Tutorial Title: [________________________]                  │
│                                                             │
│                              [Cancel] [Create Tutorial]     │
└─────────────────────────────────────────────────────────────┘
```

## Integration with H5P

The notes mention H5P has an interface for custom elements. Templates can reference H5P content types:

```json
{
    "h5p_defaults": {
        "quiz_type": "MultiChoice",
        "show_solution": true,
        "retry_enabled": true
    }
}
```

## Admin Interface

Template management available to administrators:

- View all templates
- Create new template
- Edit existing templates (except system)
- Deactivate templates (soft delete)
- Delete custom templates
- Set template categories

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
