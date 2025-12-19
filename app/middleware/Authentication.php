<?php

/**
 * Enhanced Authentication Class
 * 
 * Provides session-based authentication with security features
 */

namespace App\Middleware;

use App\Config\Database;
use App\Utils\Validator;
use Exception;

class Authentication
{
    /**
     * Get session configuration
     */
    private static function getSessionConfig(): array
    {
        static $config = null;
        
        if ($config === null) {
            $configFile = __DIR__ . '/../config/session.php';
            $config = file_exists($configFile) ? require $configFile : [
                'name' => 'VTMOPTION_SESSION',
                'lifetime' => 3600 * 8, // 8 hours
                'paths' => [
                    __DIR__ . '/../../storage/sessions',
                    sys_get_temp_dir() . '/vtmoption_sessions',
                    session_save_path()
                ],
                'cookie' => [
                    'lifetime' => 3600 * 8, // 8 hours
                    'path' => '/',
                    'domain' => '',
                    'secure' => ($_ENV['APP_ENV'] ?? 'development') === 'production',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ],
                'security' => [
                    'regenerate_id' => 1800, // 30 minutes
                    'use_strict_mode' => true,
                    'use_trans_sid' => false
                ]
            ];
        }
        
        return $config;
    }
    
    /**
     * Start secure session with fallback for XAMPP permission issues
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        
        $config = self::getSessionConfig();
        
        // Configure session
        session_name($config['name'] ?? 'VTMOPTION_SESSION');
        
        // Set cookie parameters
        session_set_cookie_params([
            'lifetime' => $config['cookie']['lifetime'] ?? 0,
            'path' => $config['cookie']['path'] ?? '/',
            'domain' => $config['cookie']['domain'] ?? '',
            'secure' => $config['cookie']['secure'] ?? false,
            'httponly' => $config['cookie']['httponly'] ?? true,
            'samesite' => $config['cookie']['samesite'] ?? 'Lax'
        ]);
        
        // Handle session path with fallbacks
        $paths = $config['paths'] ?? [
            __DIR__ . '/../../storage/sessions',
            sys_get_temp_dir() . '/vtmoption_sessions',
            session_save_path()
        ];
        
        foreach ($paths as $path) {
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            
            if (is_dir($path) && is_writable($path)) {
                @session_save_path($path);
                break;
            }
        }
        
        // Set security options
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_trans_sid', '0');
        
        // Start session
        try {
            @session_start();
        } catch (Exception $e) {
            error_log('Session start failed: ' . $e->getMessage());
            @session_save_path(session_save_path()); // Reset to default
            @session_start();
        }
        
        // Initialize session if new
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
            $_SESSION['last_activity'] = time();
        }
        
        // Regenerate session ID periodically
        $regenerateTime = $config['security']['regenerate_id'] ?? 1800;
        if (time() - $_SESSION['created'] > $regenerateTime) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
    
    /**
     * Login user
     */
    public static function login(int $userId, string $email, array $additionalData = []): void
    {
        self::startSession();
        
        // Regenerate session ID on login to prevent fixation
        session_regenerate_id(true);
        
        // Set user data
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        
        // Add additional data
        foreach ($additionalData as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }
    
    /**
     * Logout user
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            self::startSession();
        }
        
        // Clear all session data
        $_SESSION = [];
        
        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        
        // Destroy session
        session_destroy();
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            self::startSession();
        }
        
        if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
            return false;
        }
        
        // Check session timeout using config
        $config = self::getSessionConfig();
        $lifetime = $config['lifetime'] ?? 86400; // Default 24 hours
        
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity']) > $lifetime) {
            self::logout();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return true;
    }
    
    /**
     * Check authentication for API requests
     */
    public static function apiAuthenticate(): ?array
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => self::getUserId(),
            'email' => self::getUserEmail(),
            'login_time' => $_SESSION['login_time'] ?? null,
            'last_activity' => $_SESSION['last_activity'] ?? null
        ];
    }
    
    /**
     * Require authentication for API with enhanced JSON response
     */
    public static function requireApiAuth(): array
    {
        $user = self::apiAuthenticate();
        
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            
            // Enhanced response for frontend handling
            echo json_encode([
                'error' => 'Authentication required',
                'message' => 'Your session has expired. Please login again.',
                'code' => 'SESSION_EXPIRED',
                'redirect' => '/login.php',
                'timestamp' => time(),
                'requires_login' => true
            ]);
            exit;
        }
        
        return $user;
    }
    
    /**
     * Get current user ID
     */
    public static function getUserId(): ?int
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        return $_SESSION['user_id'] ?? null;
    }
    
    /**
     * Get current user email
     */
    public static function getUserEmail(): ?string
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        return $_SESSION['user_email'] ?? null;
    }
    
    /**
     * Get current user data
     */
    public static function getUserData(): ?array
    {
        if (!self::isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'login_time' => $_SESSION['login_time'] ?? null,
            'last_activity' => $_SESSION['last_activity'] ?? null
        ];
    }
    
    /**
     * Extend session timeout
     */
    public static function extendSession(): void
    {
        self::startSession();
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Verify user credentials
     */
    public static function verifyCredentials(string $email, string $password): ?array
    {
        try {
            $db = Database::getInstance();
            
            // Sanitize email
            $email = Validator::emailSanitized($email);
            if (!$email) {
                return null;
            }
            
            // Find user
            $user = $db->queryOne(
                "SELECT id, email, password_hash, is_active FROM users WHERE email = :email",
                ['email' => $email]
            );
            
            if (!$user) {
                return null;
            }
            
            // Check if user is active
            if (!($user['is_active'] ?? false)) {
                return null;
            }
            
            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                return null;
            }
            
            // Update last login (optional)
            $db->execute(
                "UPDATE users SET updated_at = NOW() WHERE id = :id",
                ['id' => $user['id']]
            );
            
            return [
                'id' => (int)$user['id'],
                'email' => $user['email'],
            ];
            
        } catch (Exception $e) {
            error_log('Authentication error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Register new user
     */
    public static function register(string $email, string $password): ?array
    {
        try {
            $db = Database::getInstance();
            
            // Validate email
            $email = Validator::emailSanitized($email);
            if (!$email) {
                throw new Exception('Invalid email address');
            }
            
            // Validate password
            if (!Validator::password($password)) {
                throw new Exception('Password must be at least 6 characters');
            }
            
            // Check if user already exists
            $existing = $db->queryOne(
                "SELECT id FROM users WHERE email = :email",
                ['email' => $email]
            );
            
            if ($existing) {
                throw new Exception('Email already registered');
            }
            
            // Hash password
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            // Create user
            $userId = $db->insert('users', [
                'email' => $email,
                'password_hash' => $hashedPassword,
                'is_active' => true,
            ]);
            
            return [
                'id' => $userId,
                'email' => $email,
            ];
            
        } catch (Exception $e) {
            error_log('Registration error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Require authentication (redirect or return error if not logged in)
     */
    public static function requireAuth(bool $jsonResponse = false): void
    {
        if (!self::isLoggedIn()) {
            if ($jsonResponse) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            } else {
                header('Location: /login.php');
                exit;
            }
        }
    }
}
