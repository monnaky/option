<?php
// api/ping.php
// Session keep-alive endpoint

require_once __DIR__ . '/../app/bootstrap.php';

use App\Middleware\Authentication;

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Allow CORS if needed
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: " . $_SERVER['HTTP_ORIGIN']);
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

try {
    // Start/update session without requiring auth
    Authentication::startSession();
    
    if (Authentication::isLoggedIn()) {
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        // Get user info
        $userData = Authentication::getUserData();
        
        echo json_encode([
            'status' => 'authenticated',
            'user_id' => Authentication::getUserId(),
            'email' => Authentication::getUserEmail(),
            'timestamp' => time(),
            'session_active' => true,
            'last_activity' => $_SESSION['last_activity'],
            'data' => $userData
        ]);
    } else {
        echo json_encode([
            'status' => 'not_authenticated',
            'timestamp' => time(),
            'session_active' => false,
            'message' => 'No active session found'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error: ' . $e->getMessage(),
        'timestamp' => time(),
        'code' => 'SERVER_ERROR'
    ]);
}
