<?php

/**
 * Application Bootstrap
 * 
 * Initializes security, database, and common functionality
 */

// Load configuration (for shared hosting)
// Only load if not already loaded (prevents redeclaration errors)
if (!defined('APP_ENV') && file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

// Load autoloader (config.php already includes autoload.php, so this is safe)
// But we check if constants are defined to avoid re-including config.php
if (!defined('APP_PATH') && file_exists(__DIR__ . '/autoload.php')) {
    require_once __DIR__ . '/autoload.php';
}

// Start secure session
require_once __DIR__ . '/middleware/Authentication.php';
App\Middleware\Authentication::startSession();

// Apply security headers and CORS
require_once __DIR__ . '/middleware/SecurityMiddleware.php';
App\Middleware\SecurityMiddleware::applyAll();

// Set error reporting based on environment
$appEnv = defined('APP_ENV') ? APP_ENV : 'development';
if ($appEnv === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Set timezone
$timezone = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'UTC';
date_default_timezone_set($timezone);

