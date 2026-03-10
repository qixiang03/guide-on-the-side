# Guide on the Side - Certificate Storage System

**Author:** Daniel McGrath (Tech Lead)  
**Sprint:** 5

## Overview

This document defines the database schema for storing PDF certificates generated when students complete tutorials. Certificates are temporary and can be downloaded via a unique token link (no login required).

## Design Decisions

| Decision | Rationale |
|----------|-----------|
| Store file path, not blob | PDFs stored on filesystem, database holds metadata + path |
| Download token | Unique token allows sharing download link without authentication |
| Optional expiry | Certificates can expire after set time to manage storage |
| No user tracking | Students are anonymous - only optional name field for certificate |

## Database Schema

### wp_tutorial_certificates

| Field | Type | Null | Key | Default | Description |
|-------|------|------|-----|---------|-------------|
| id | INT UNSIGNED | NO | PK | AUTO_INCREMENT | Unique identifier |
| tutorial_id | INT UNSIGNED | NO | FK | | Reference to tutorial completed |
| student_name | VARCHAR(255) | YES | | NULL | Optional name entered by student |
| completion_date | DATETIME | NO | | CURRENT_TIMESTAMP | When tutorial was completed |
| certificate_path | VARCHAR(500) | NO | | | File path to generated PDF |
| download_token | VARCHAR(64) | NO | UQ | | Unique token for download URL |
| expires_at | DATETIME | YES | | NULL | Optional expiry timestamp |
| created_at | DATETIME | NO | | CURRENT_TIMESTAMP | Record creation timestamp |

### SQL

```sql
CREATE TABLE wp_tutorial_certificates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tutorial_id INT UNSIGNED NOT NULL,
    student_name VARCHAR(255),
    completion_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    certificate_path VARCHAR(500) NOT NULL,
    download_token VARCHAR(64) NOT NULL,
    expires_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY (download_token),
    FOREIGN KEY (tutorial_id) REFERENCES wp_tutorials(id) ON DELETE CASCADE
);
```

## How It Works

### Certificate Generation Flow

```
Student completes tutorial
    │
    ▼
Cindy's plugin generates PDF
    │
    ▼
PDF saved to /web/app/uploads/certificates/
    │
    ▼
Record inserted into wp_tutorial_certificates
    │
    ▼
Download link returned: /certificate/download/{token}
```

### Download Flow

```
Student clicks download link
    │
    ▼
Plugin looks up token in wp_tutorial_certificates
    │
    ▼
Check if expired (expires_at)
    │
    ▼
If valid: serve PDF from certificate_path
If expired: show "Certificate expired" message
```

## File Storage

| Item | Location |
|------|----------|
| Certificate PDFs | `/var/www/guide-on-the-side/web/app/uploads/certificates/` |
| Filename format | `cert_{tutorial_id}_{token}.pdf` |

## Token Generation

```php
// Generate unique download token
$token = bin2hex(random_bytes(32)); // 64 character hex string
```

## Expiry Policy

| Option | Value | Use Case |
|--------|-------|----------|
| No expiry | `expires_at = NULL` | Permanent certificates |
| 24 hours | `expires_at = NOW() + 1 day` | Temporary download |
| 7 days | `expires_at = NOW() + 7 days` | Short-term access |

Recommendation: Default to 7 days, configurable in admin settings.

## Cleanup

Optional cron job to remove expired certificates:

```bash
# Add to crontab - runs daily at 4am
0 4 * * * /home/dmcgrath15021/cleanup-certs.sh
```

```bash
#!/bin/bash
# cleanup-certs.sh - Remove expired certificates

cd /var/www/guide-on-the-side
lando wp eval '
    global $wpdb;
    $expired = $wpdb->get_results("
        SELECT certificate_path FROM wp_tutorial_certificates 
        WHERE expires_at IS NOT NULL AND expires_at < NOW()
    ");
    foreach ($expired as $cert) {
        @unlink(ABSPATH . $cert->certificate_path);
    }
    $wpdb->query("
        DELETE FROM wp_tutorial_certificates 
        WHERE expires_at IS NOT NULL AND expires_at < NOW()
    ");
'
echo "Certificate cleanup completed at $(date)" >> ~/cleanup.log
```

## Integration with Cindy's Certificate Generation

Cindy's plugin should:

1. Generate the PDF
2. Save to `web/app/uploads/certificates/`
3. Insert record:

```php
global $wpdb;

$token = bin2hex(random_bytes(32));
$filename = "cert_{$tutorial_id}_{$token}.pdf";
$path = "web/app/uploads/certificates/{$filename}";

// Save PDF to $path...

$wpdb->insert('wp_tutorial_certificates', [
    'tutorial_id' => $tutorial_id,
    'student_name' => $student_name, // can be null
    'certificate_path' => $path,
    'download_token' => $token,
    'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days'))
]);

$download_url = home_url("/certificate/download/{$token}");
```

## Sample Data

| id | tutorial_id | student_name | completion_date | certificate_path | download_token | expires_at |
|----|-------------|--------------|-----------------|------------------|----------------|------------|
| 1 | 1 | Jane Doe | 2026-03-03 14:30:00 | web/app/uploads/certificates/cert_1_abc123...pdf | abc123... | 2026-03-10 14:30:00 |
| 2 | 1 | NULL | 2026-03-03 15:00:00 | web/app/uploads/certificates/cert_1_def456...pdf | def456... | 2026-03-10 15:00:00 |

## AI Disclosure

This documentation was created with assistance from Claude AI (Anthropic). Per course policy, all AI-assisted work must be disclosed.
