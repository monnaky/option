<?php
/**
 * Authentication API Endpoints
 * 
 * POST /api/auth/register
 * POST /api/auth/login
 */

// CRITICAL: Suppress ALL errors FIRST
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');

// Load autoloader BEFORE use statements
require_once __DIR__ . '/../app/autoload.php';

// use statements MUST be at top level, immediately after require
use App\Config\Database;
use App\Utils\DatabaseHelper;
use App\Utils\Validator;
use App\Utils\Response;

// Start output buffering AFTER use statements
@ob_start();
@ob_clean(); // Clean any previous output

// Set JSON header AFTER output buffering starts
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Wrap everything in try-catch to prevent any errors from leaking
try {

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Route requests
    switch ($method) {
        case 'POST':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid actions: register, login', 400);
            } elseif ($action === 'register') {
                handleRegister();
            } elseif ($action === 'login') {
                handleLogin();
            } else {
                Response::error('Invalid action. Valid actions: register, login', 400);
            }
            break;
        
        case 'GET':
            Response::error('GET method not supported. Use POST with action parameter (register or login)', 405);
            break;
        
        default:
            Response::error("Method {$method} not allowed. Use POST with action parameter", 405);
    }
    
} catch (Throwable $e) {
    // Clean any output before sending error
    @ob_clean();
    
    // Catch ANY error (including fatal errors, parse errors, etc.)
    @error_log('Fatal error in api/auth.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Ensure only JSON is output
    Response::error('Internal server error', 500);
}

/**
 * Handle user registration
 */
function handleRegister()
{
    try {
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        
        // Validate input
        $errors = Validator::required($data, ['email', 'password']);
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        if (!Validator::email($email)) {
            Response::error('Invalid email format', 400);
        }
        
        if (!Validator::password($password)) {
            Response::error('Password must be at least 6 characters', 400);
        }
        
        // Check if user exists
        $db = Database::getInstance();
        $existingUser = $db->queryOne(
            "SELECT id FROM users WHERE email = :email",
            ['email' => strtolower(trim($email))]
        );
        
        if ($existingUser) {
            Response::error('User already exists', 400);
        }
        
        // Create user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $userId = $db->insert('users', [
            'email' => strtolower(trim($email)),
            'password' => $hashedPassword,
            'is_active' => true,
        ]);
        
        // Create default settings
        $helper = new DatabaseHelper();
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $db->insert('settings', [
            'user_id' => $userId,
            'stake' => 1.00,
            'target' => 100.00,
            'stop_limit' => 50.00,
            'is_bot_active' => false,
            'daily_profit' => 0.00,
            'daily_loss' => 0.00,
            'reset_date' => $tomorrow,
        ]);
        
        // Set session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = strtolower(trim($email));
        
        // Return response
        Response::success([
            'token' => session_id(), // Using session ID as token
            'user' => [
                'id' => $userId,
                'email' => strtolower(trim($email)),
            ],
        ], 'User registered successfully', 201);
        
    } catch (Exception $e) {
        error_log('Registration error: ' . $e->getMessage());
        Response::error('Registration failed', 500);
    }
}

/**
 * Handle user login
 */
function handleLogin()
{
    try {
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        
        // Validate input
        $errors = Validator::required($data, ['email', 'password']);
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        if (!Validator::email($email)) {
            Response::error('Invalid email format', 400);
        }
        
        // Find user
        $db = Database::getInstance();
        $user = $db->queryOne(
            "SELECT id, email, password, is_active, is_admin FROM users WHERE email = :email",
            ['email' => strtolower(trim($email))]
        );
        
        if (!$user) {
            Response::error('Invalid credentials', 401);
        }
        
        // Check password
        if (!password_verify($password, $user['password'])) {
            Response::error('Invalid credentials', 401);
        }
        
        // Check if user is active
        if (!$user['is_active']) {
            Response::error('Account is inactive', 403);
        }
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['is_admin'] = !empty($user['is_admin']) ? (bool)$user['is_admin'] : false;
        
        // Return response
        Response::success([
            'token' => session_id(), // Using session ID as token
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'is_admin' => !empty($user['is_admin']) ? (bool)$user['is_admin'] : false,
            ],
        ], 'Login successful');
        
    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        Response::error('Login failed', 500);
    }
}

