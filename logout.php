<?php
/**
 * Logout Page
 */

// Load helpers and bootstrap
if (!function_exists('url')) {
    require_once __DIR__ . '/app/helpers.php';
}

// Use new Authentication class for proper logout
require_once __DIR__ . '/app/bootstrap.php';
use App\Middleware\Authentication;

// Check if we have queued requests to preserve
$queuedRequests = [];
if (isset($_SESSION['queued_requests'])) {
    $queuedRequests = $_SESSION['queued_requests'];
}

// Get current page for potential redirect back
$currentPage = $_SERVER['REQUEST_URI'] ?? '/';
$referrer = $_SERVER['HTTP_REFERER'] ?? $currentPage;

// Use new Authentication class for logout
Authentication::logout();

// Preserve queued requests if any (for session expiry flows)
if (!empty($queuedRequests)) {
    // Start a new session to store the queued requests
    Authentication::startSession();
    $_SESSION['queued_requests'] = $queuedRequests;
    
    // Add current page as redirect back
    $_SESSION['logout_redirect'] = $referrer;
    
    // Add logout message
    header('Location: ' . url('login.php?message=logged_out&redirect=' . urlencode($referrer)));
} else {
    // Regular logout - redirect to login
    header('Location: ' . url('login.php?message=logged_out'));
}

exit;
