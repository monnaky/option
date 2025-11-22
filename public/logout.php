<?php
/**
 * Logout Page
 */

@error_reporting(0);
@ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: /login.php');
exit;

