<?php
/**
 * Logout Page
 */

// Load helpers first
if (!function_exists('url')) {
    require_once __DIR__ . '/app/helpers.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: ' . url('login.php'));
exit;
