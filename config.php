<?php

/**
 * Configuration File for Shared Hosting
 * 
 * This file loads environment variables and sets up the application
 * for shared hosting environments like Namecheap
 * 
 * IMPORTANT: Update the values below with your actual hosting details
 */

// ============================================================================
// ENVIRONMENT CONFIGURATION
// ============================================================================

// Application environment (development or production)
// Supports environment variables for Docker/Coolify deployment
define('APP_ENV', $_ENV['APP_ENV'] ?? 'development'); // Change to 'production' for production hosting

// Application URL (your domain)
// For Coolify: Set APP_URL environment variable to your domain
define('APP_URL', $_ENV['APP_URL'] ?? 'http://localhost/vtm'); // Replace with your actual domain

// Application timezone
define('APP_TIMEZONE', $_ENV['APP_TIMEZONE'] ?? 'UTC');

// ============================================================================
// DATABASE CONFIGURATION
// ============================================================================

// Database host
// For Coolify: Set DB_HOST to your database service name (e.g., 'mariadb' or 'mysql')
// For local development: Use 'localhost'
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');

// Database port (usually 3306 for MySQL/MariaDB)
define('DB_PORT', $_ENV['DB_PORT'] ?? '3306');

// Database name
// For Coolify: Set DB_NAME environment variable
define('DB_NAME', $_ENV['DB_NAME'] ?? 'vtm'); // Replace with your database name

// Database username
// For Coolify: Set DB_USER environment variable
define('DB_USER', $_ENV['DB_USER'] ?? 'root'); // Replace with your database username

// Database password
// For Coolify: Set DB_PASS environment variable (REQUIRED for production)
define('DB_PASS', $_ENV['DB_PASS'] ?? ''); // Replace with your database password

// Database charset
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// ============================================================================
// SECURITY CONFIGURATION
// ============================================================================

// Encryption key for API tokens (64-character hex string)
// Generated using: bin2hex(random_bytes(32))
// IMPORTANT: Keep this key secure and never commit it to version control
// For Coolify: Set ENCRYPTION_KEY environment variable
// To generate a new key, run: php -r "echo bin2hex(random_bytes(32));"
define('ENCRYPTION_KEY', $_ENV['ENCRYPTION_KEY'] ?? '7f3a9b2c8d4e1f6a5b9c2d7e3f8a1b4c6d9e2f5a8b1c4d7e0f3a6b9c2d5e8f1a4');

// Session configuration
define('SESSION_LIFETIME', 86400); // 24 hours in seconds

// ============================================================================
// DERIV API CONFIGURATION
// ============================================================================

// Deriv App ID (default is 105326)
// For Coolify: Can be set via DERIV_APP_ID environment variable
define('DERIV_APP_ID', $_ENV['DERIV_APP_ID'] ?? '105326');

// Deriv WebSocket host
// Correct hostname: ws.derivws.com (Deriv WebSocket) or wss.binaryws.com (Binary.com WebSocket)
// For Coolify: Can be set via DERIV_WS_HOST environment variable
define('DERIV_WS_HOST', $_ENV['DERIV_WS_HOST'] ?? 'ws.derivws.com');

// ============================================================================
// ERROR HANDLING
// ============================================================================

// Error reporting (set to 0 in production)
if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// Log errors
// For Coolify: Set LOG_PATH environment variable for custom log location
ini_set('log_errors', '1');
$logPath = $_ENV['LOG_PATH'] ?? __DIR__ . '/error_log';
ini_set('error_log', $logPath);

// ============================================================================
// PATH CONFIGURATION
// ============================================================================

// Base path (usually the directory where this file is located)
define('BASE_PATH', __DIR__);

// Public path (where public files are served from)
define('PUBLIC_PATH', BASE_PATH . '/public');

// App path
if (!defined('APP_PATH')) {
    define('APP_PATH', BASE_PATH . '/app');
}

// Vendor path (Composer dependencies)
define('VENDOR_PATH', BASE_PATH . '/vendor');

// ============================================================================
// SET ENVIRONMENT VARIABLES FOR DOTENV COMPATIBILITY
// ============================================================================

// Set environment variables that dotenv would normally load
$_ENV['APP_ENV'] = APP_ENV;
$_ENV['APP_URL'] = APP_URL;
$_ENV['DB_HOST'] = DB_HOST;
$_ENV['DB_PORT'] = DB_PORT;
$_ENV['DB_NAME'] = DB_NAME;
$_ENV['DB_USER'] = DB_USER;
$_ENV['DB_PASS'] = DB_PASS;
$_ENV['DB_CHARSET'] = DB_CHARSET;
$_ENV['ENCRYPTION_KEY'] = ENCRYPTION_KEY;
$_ENV['DERIV_APP_ID'] = DERIV_APP_ID;
$_ENV['DERIV_WS_HOST'] = DERIV_WS_HOST;
$_ENV['TIMEZONE'] = APP_TIMEZONE;

// ============================================================================
// TIMEZONE SETTING
// ============================================================================

date_default_timezone_set(APP_TIMEZONE);

// ============================================================================
// AUTOLOADER
// ============================================================================

// Load pure PHP autoloader (no Composer needed)
if (file_exists(__DIR__ . '/app/autoload.php')) {
    require_once __DIR__ . '/app/autoload.php';
} else {
    // Don't use die() as it outputs HTML - log error instead
    error_log('CRITICAL: Autoloader not found at: ' . __DIR__ . '/app/autoload.php');
    // If we're in an API context, try to return JSON
    if (php_sapi_name() !== 'cli' && strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Internal server error: Autoloader not found']);
        exit;
    }
    die('Autoloader not found. Please check file structure.');
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get configuration value
 */
if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $default;
        
        // Convert string booleans to actual booleans
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        return $value;
    }
}

/**
 * Check if application is in production
 */
if (!function_exists('isProduction')) {
    function isProduction(): bool
    {
        return defined('APP_ENV') && APP_ENV === 'production';
    }
}

/**
 * Check if application is in development
 */
if (!function_exists('isDevelopment')) {
    function isDevelopment(): bool
    {
        return defined('APP_ENV') && APP_ENV === 'development';
    }
}

