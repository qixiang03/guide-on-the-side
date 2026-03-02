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

// 4. Load the plugin so PB_Split_Guide_Plugin is available to integration tests.
//    The plugin's top-level `new PB_Split_Guide_Plugin()` is harmless with stubs.
require_once $root_dir . '/web/app/plugins/pb-split-guide/pb-split-guide.php';

echo "UPEI Project Test Bootstrap: Initialized successfully.\n";