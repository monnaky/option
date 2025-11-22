<?php

/**
 * Pure PHP Autoloader
 * 
 * Replaces Composer autoloader with direct class loading
 */

// Define base paths (with checks to prevent redefinition)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}
if (!defined('APP_PATH')) {
    define('APP_PATH', APP_ROOT . '/app');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', APP_ROOT . '/app/config');
}
if (!defined('MIDDLEWARE_PATH')) {
    define('MIDDLEWARE_PATH', APP_ROOT . '/app/middleware');
}
if (!defined('SERVICES_PATH')) {
    define('SERVICES_PATH', APP_ROOT . '/app/services');
}
if (!defined('UTILS_PATH')) {
    define('UTILS_PATH', APP_ROOT . '/app/utils');
}

// Load configuration first
if (file_exists(APP_ROOT . '/config.php')) {
    require_once APP_ROOT . '/config.php';
}

/**
 * Simple autoloader for App namespace classes
 */
spl_autoload_register(function ($class) {
    // Remove namespace prefix
    $class = str_replace('App\\', '', $class);
    
    // Convert namespace separators to directory separators
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    
    // Try different paths
    $paths = [
        APP_PATH . '/' . $class . '.php',
        CONFIG_PATH . '/' . $class . '.php',
        MIDDLEWARE_PATH . '/' . $class . '.php',
        SERVICES_PATH . '/' . $class . '.php',
        UTILS_PATH . '/' . $class . '.php',
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return true;
        }
    }
    
    return false;
});

// Load helper functions
require_once APP_PATH . '/helpers.php';

// Load core classes directly (for backward compatibility)
require_once CONFIG_PATH . '/Database.php';
require_once MIDDLEWARE_PATH . '/Authentication.php';
require_once MIDDLEWARE_PATH . '/AuthMiddleware.php';
require_once MIDDLEWARE_PATH . '/AdminMiddleware.php';
require_once MIDDLEWARE_PATH . '/CSRFProtection.php';
require_once MIDDLEWARE_PATH . '/SecurityMiddleware.php';
require_once MIDDLEWARE_PATH . '/RateLimiter.php';
require_once SERVICES_PATH . '/EncryptionService.php';
require_once SERVICES_PATH . '/DerivAPI.php';
require_once SERVICES_PATH . '/TradingBotService.php';
require_once SERVICES_PATH . '/SignalService.php';
require_once UTILS_PATH . '/Validator.php';
require_once UTILS_PATH . '/Response.php';
require_once UTILS_PATH . '/DatabaseHelper.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

