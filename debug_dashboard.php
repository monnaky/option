<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/app/autoload.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "Session data: ";
var_dump($_SESSION);

echo "<br>Checking authentication...<br>";

// Test authentication
try {
    $isLoggedIn = App\Middleware\Authentication::isLoggedIn();
    echo "Is logged in: " . ($isLoggedIn ? 'true' : 'false') . "<br>";
    
    if ($isLoggedIn) {
        $user = App\Middleware\Authentication::getCurrentUser();
        echo "Current user: ";
        var_dump($user);
    }
} catch (Exception $e) {
    echo "Authentication error: " . $e->getMessage() . "<br>";
    echo "Stack trace: " . $e->getTraceAsString() . "<br>";
}

echo "<br>Testing requireAuth...<br>";
try {
    App\Middleware\Authentication::requireAuth();
    echo "requireAuth passed<br>";
} catch (Exception $e) {
    echo "requireAuth error: " . $e->getMessage() . "<br>";
    echo "Stack trace: " . $e->getTraceAsString() . "<br>";
}
?>
