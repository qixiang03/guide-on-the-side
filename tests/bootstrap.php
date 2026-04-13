<?php
/**
 * PHPUnit Bootstrap for UPEI Guide-on-the-Side Project
 * This file handles class loading and basic environment setup for testing.
 *
 * Supports both Unit tests (pure PHP) and Integration smoke tests
 * (plugin ↔ WordPress wiring verified via lightweight stubs).
 */

$root_dir = dirname(__DIR__);

// 1. Load the Composer autoloader
if (file_exists($root_dir . '/vendor/autoload.php')) {
    require_once $root_dir . '/vendor/autoload.php';
}

// 2. Define basic WordPress constants
if (!defined('ABSPATH')) {
    define('ABSPATH', $root_dir . '/web/wp/');
}

// 3. Load lightweight WP function stubs (no-ops for unit tests, recorders
//    for integration tests). Must come before the plugin file is loaded.
require_once __DIR__ . '/Integration/Helpers/WPStubs.php';

// 3b. Load mock TCPDF before plugin so certificate tests don't emit PDF/headers.
require_once __DIR__ . '/Integration/Helpers/MockTCPDF.php';

// 4. Load the plugin so PB_Split_Guide_Plugin is available to integration tests.
//    The plugin's top-level `new PB_Split_Guide_Plugin()` is harmless with stubs.
//    Some branches may include optional files that emit a benign PHP warning
//    ("use TCPDF has no effect"). Suppress only that exact warning here so
//    PHPUnit subprocess tests remain stable in CI.
$previous_error_handler = set_error_handler(
    static function (int $severity, string $message): bool {
        if (
            $severity === E_WARNING
            && strpos($message, "use statement with non-compound name 'TCPDF' has no effect") !== false
        ) {
            return true;
        }
        return false;
    }
);
require_once $root_dir . '/web/app/plugins/pb-split-guide/pb-split-guide.php';
if ($previous_error_handler !== null) {
    set_error_handler($previous_error_handler);
} else {
    restore_error_handler();
}

// 5. Load RBAC classes (not yet wired into pb-split-guide.php via require_once,
//    but needed by unit tests that reference their constants and source code).
$plugin_dir = $root_dir . '/web/app/plugins/pb-split-guide/';
require_once $plugin_dir . 'includes/class-pbsg-roles.php';
require_once $plugin_dir . 'includes/class-pbsg-admin-menu-filter.php';
require_once $plugin_dir . 'includes/class-pbsg-librarian-manager.php';
require_once $plugin_dir . 'includes/class-pbsg-h5p-usage-map.php';
require_once $plugin_dir . 'includes/class-pbsg-embed-check.php';
require_once $plugin_dir . 'accessibility-dashboard/class-pbsg-accessibility-dashboard.php';

echo "UPEI Project Test Bootstrap: Initialized successfully.\n";