<?php
/**
 * PHPUnit Bootstrap for UPEI Guide-on-the-Side Project
 * This file handles class loading and basic environment setup for testing.
 */

// 1. Load the Composer autoloader to ensure all dependencies are available
$root_dir = dirname(__DIR__);
if (file_exists($root_dir . '/vendor/autoload.php')) {
    require_once $root_dir . '/vendor/autoload.php';
}

// 2. Define basic WordPress constants to prevent "undefined constant" errors
// This mimics a lightweight WP environment for Unit Testing
if (!defined('ABSPATH')) {
    define('ABSPATH', $root_dir . '/web/wp/');
}

echo "UPEI Project Test Bootstrap: Initialized successfully.\n";