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
     * Start secure session with fallback for XAMPP permission issues
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        
        // Configure session security
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', ($_ENV['APP_ENV'] ?? 'development') === 'production' ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Strict');
        
        // Set session name
        session_name('VTMOPTION_SESSION');
        
        // Handle XAMPP session path permission issues
        $defaultSessionPath = session_save_path();
        $fallbackPaths = [
            __DIR__ . '/../../storage/sessions', // Custom storage directory
            sys_get_temp_dir() . '/vtmoption_sessions', // System temp with prefix
            $defaultSessionPath // Default path as last resort
        ];
        
        // Try to set session path with fallbacks
        foreach ($fallbackPaths as $path) {
            // Create directory if it doesn't exist
            if (!is_dir($path)) {
                @mkdir($path, 0755, true);
            }
            
            // Check if directory is writable
            if (is_dir($path) && is_writable($path)) {
                @session_save_path($path);
                break;
            }
        }
        
        // Start session with error suppression and fallback
        try {
            @session_start();
        } catch (Exception $e) {
            // If session start fails, try with default path
            error_log('Session start failed: ' . $e->getMessage());
            @session_save_path($defaultSessionPath);
            @session_start();
        }
        
        // Regenerate session ID periodically to prevent fixation attacks
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } elseif (time() - $_SESSION['created'] > 1800) {
            // Regenerate every 30 minutes
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
            session_start();
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
            session_start();
        }
        
        if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
            return false;
        }
        
        // Check session timeout (24 hours)
        if (isset($_SESSION['last_activity']) && 
            (time() - $_SESSION['last_activity']) > 86400) {
            self::logout();
            return false;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
        
        return true;
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
        ];
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

