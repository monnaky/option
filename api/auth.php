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
require_once __DIR__ . '/../app/bootstrap.php';

// use statements MUST be at top level, immediately after require
use App\Config\Database;
use App\Utils\DatabaseHelper;
use App\Utils\Validator;
use App\Utils\Response;
use App\Middleware\Authentication;
use App\Utils\RateLimit;

// Start output buffering AFTER use statements
@ob_start();
@ob_clean(); // Clean any previous output

// Set JSON header AFTER output buffering starts
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Wrap everything in try-catch to prevent any errors from leaking
try {

    // Start session using new Authentication class
    Authentication::startSession();

    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Route requests
    switch ($method) {
        case 'POST':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid actions: register, login, logout, check', 400);
            } elseif ($action === 'register') {
                handleRegister();
            } elseif ($action === 'login') {
                handleLogin();
            } elseif ($action === 'logout') {
                handleLogout();
            } elseif ($action === 'check') {
                handleCheck();
            } else {
                Response::error('Invalid action. Valid actions: register, login, logout, check', 400);
            }
            break;
        
        case 'GET':
            if ($action === 'check') {
                handleCheck();
            } else {
                Response::error('GET method only supports "check" action. Use POST for register/login/logout', 405);
            }
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
        // Rate limiting: 5 registrations per hour per IP
        $identifier = RateLimit::getIdentifier();
        try {
            RateLimit::require($identifier, 'register', 5, 3600);
        } catch (Exception $e) {
            Response::error('Too many registration attempts. Please try again later.', 429);
        }
        
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';
        
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
        
        // Check password confirmation if provided
        if ($confirmPassword && $password !== $confirmPassword) {
            Response::error('Passwords do not match', 400);
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
        
        // Create user using new Authentication class
        $user = Authentication::register($email, $password);
        
        if (!$user) {
            throw new Exception('User registration failed');
        }
        
        // Create default settings (with new trade duration columns)
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $db->insert('settings', [
            'user_id' => $user['id'],
            'stake' => 1.00,
            'target' => 100.00,
            'stop_limit' => 50.00,
            'trade_duration' => 5, // Default from migration
            'trade_duration_unit' => 't', // Default from migration
            'is_bot_active' => 0, // Use integer 0/1 for MySQL BOOLEAN
            'daily_profit' => 0.00,
            'daily_loss' => 0.00,
            'reset_date' => $tomorrow,
        ]);
        
        // Login the user automatically after registration
        Authentication::login($user['id'], $user['email']);
        
        // Return response
        Response::success([
            'token' => session_id(), // Using session ID as token
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
            ],
            'session_info' => [
                'id' => session_id(),
                'created' => $_SESSION['created'] ?? null,
                'login_time' => $_SESSION['login_time'] ?? null
            ]
        ], 'User registered successfully', 201);
        
    } catch (Exception $e) {
        error_log('Registration error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        Response::error('Registration failed: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle user login
 */
function handleLogin()
{
    try {
        // Rate limiting: 10 login attempts per minute per IP
        $identifier = RateLimit::getIdentifier();
        try {
            RateLimit::require($identifier, 'login', 10, 60);
        } catch (Exception $e) {
            Response::error('Too many login attempts. Please try again in a minute.', 429);
        }
        
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $rememberMe = filter_var($data['remember_me'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        // Validate input
        $errors = Validator::required($data, ['email', 'password']);
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        if (!Validator::email($email)) {
            Response::error('Invalid email format', 400);
        }
        
        // Verify credentials using new Authentication class
        $user = Authentication::verifyCredentials($email, $password);
        
        if (!$user) {
            Response::error('Invalid email or password', 401);
        }
        
        // Get additional user info from database
        $db = Database::getInstance();
        $userDetails = $db->queryOne(
            "SELECT id, email, is_active, is_admin FROM users WHERE id = :id",
            ['id' => $user['id']]
        );
        
        if (!$userDetails) {
            Response::error('User not found', 404);
        }
        
        // Check if user is active
        if (!$userDetails['is_active']) {
            Response::error('Account is inactive', 403);
        }
        
        // Login using new Authentication class
        Authentication::login($user['id'], $user['email'], [
            'remember_me' => $rememberMe,
            'is_admin' => !empty($userDetails['is_admin'])
        ]);
        
        // Generate CSRF token if CSRF class exists
        $csrfToken = null;
        if (class_exists('App\\Utils\\CSRF')) {
            $csrfToken = \App\Utils\CSRF::generateToken();
        }
        
        // Get user settings to include in response
        $settings = $db->queryOne(
            "SELECT stake, target, stop_limit, trade_duration, trade_duration_unit, is_bot_active 
             FROM settings WHERE user_id = :user_id",
            ['user_id' => $user['id']]
        );
        
        // Return response
        Response::success([
            'token' => session_id(), // Using session ID as token
            'csrf_token' => $csrfToken,
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'is_admin' => !empty($userDetails['is_admin']) ? (bool)$userDetails['is_admin'] : false,
                'settings' => $settings ?: null
            ],
            'session_info' => [
                'id' => session_id(),
                'created' => $_SESSION['created'] ?? null,
                'login_time' => $_SESSION['login_time'] ?? null,
                'last_activity' => $_SESSION['last_activity'] ?? null,
                'expires_at' => ($_SESSION['last_activity'] ?? 0) + (3600 * 8) // 8 hours from config
            ]
        ], 'Login successful');
        
    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        Response::error('Login failed: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle user logout
 */
function handleLogout()
{
    try {
        // Check if user is logged in first
        $wasLoggedIn = Authentication::isLoggedIn();
        $userData = Authentication::getUserData();
        
        // Perform logout using new Authentication class
        Authentication::logout();
        
        if ($wasLoggedIn) {
            Response::success([
                'message' => 'Logged out successfully',
                'user_id' => $userData['id'] ?? null
            ], 'Logged out successfully');
        } else {
            Response::success([
                'message' => 'No active session'
            ], 'No active session found');
        }
        
    } catch (Exception $e) {
        error_log('Logout error: ' . $e->getMessage());
        Response::error('Logout failed', 500);
    }
}

/**
 * Handle session check
 */
function handleCheck()
{
    try {
        // Check authentication using new system
        $isLoggedIn = Authentication::isLoggedIn();
        $userData = Authentication::getUserData();
        
        if ($isLoggedIn && $userData) {
            // Get additional user info
            $db = Database::getInstance();
            $userDetails = $db->queryOne(
                "SELECT id, email, is_active, is_admin FROM users WHERE id = :id AND is_active = 1",
                ['id' => $userData['id']]
            );
            
            if ($userDetails) {
                // Get user settings
                $settings = $db->queryOne(
                    "SELECT stake, target, stop_limit, trade_duration, trade_duration_unit, is_bot_active 
                     FROM settings WHERE user_id = :user_id",
                    ['user_id' => $userData['id']]
                );
                
                Response::success([
                    'authenticated' => true,
                    'user' => [
                        'id' => $userData['id'],
                        'email' => $userData['email'],
                        'is_admin' => !empty($userDetails['is_admin']) ? (bool)$userDetails['is_admin'] : false,
                        'settings' => $settings ?: null
                    ],
                    'session_info' => [
                        'id' => session_id(),
                        'login_time' => $userData['login_time'] ?? null,
                        'last_activity' => $userData['last_activity'] ?? null,
                        'remaining_time' => ($userData['last_activity'] ?? 0) + (3600 * 8) - time(),
                        'is_valid' => true
                    ]
                ], 'Session is valid');
            } else {
                // User not found or inactive in database
                Authentication::logout();
                Response::success([
                    'authenticated' => false,
                    'message' => 'User not found or inactive'
                ], 'Session invalid - user not found');
            }
        } else {
            Response::success([
                'authenticated' => false,
                'session_active' => false,
                'message' => 'No active session'
            ], 'No active session');
        }
        
    } catch (Exception $e) {
        error_log('Session check error: ' . $e->getMessage());
        Response::error('Session check failed', 500);
    }
}
