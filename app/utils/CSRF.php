<?php

/**
 * CSRF Protection Helper
 * 
 * Provides Cross-Site Request Forgery protection for forms and AJAX requests
 */

namespace App\Utils;

class CSRF
{
    /**
     * Generate a CSRF token
     * 
     * @return string CSRF token
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Generate new token
        $token = bin2hex(random_bytes(32));
        
        // Store in session
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        
        return $token;
    }
    
    /**
     * Get current CSRF token (generate if not exists)
     * 
     * @return string CSRF token
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if token exists and is not expired
        if (isset($_SESSION['csrf_token']) && isset($_SESSION['csrf_token_time'])) {
            $tokenAge = time() - $_SESSION['csrf_token_time'];
            
            // Token expires after 1 hour
            if ($tokenAge < 3600) {
                return $_SESSION['csrf_token'];
            }
        }
        
        // Generate new token if not exists or expired
        return self::generateToken();
    }
    
    /**
     * Validate CSRF token
     * 
     * @param string|null $token Token to validate (if null, gets from request)
     * @return bool True if valid, false otherwise
     */
    public static function validateToken(?string $token = null): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Get token from request if not provided
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? 
                     $_SERVER['HTTP_X_CSRF_TOKEN'] ?? 
                     $_GET['csrf_token'] ?? 
                     '';
        }
        
        // Check if session token exists
        if (!isset($_SESSION['csrf_token'])) {
            error_log('CSRF validation failed: No session token');
            return false;
        }
        
        // Check if token is expired
        if (isset($_SESSION['csrf_token_time'])) {
            $tokenAge = time() - $_SESSION['csrf_token_time'];
            if ($tokenAge >= 3600) {
                error_log('CSRF validation failed: Token expired');
                return false;
            }
        }
        
        // Validate token using timing-safe comparison
        $valid = hash_equals($_SESSION['csrf_token'], $token);
        
        if (!$valid) {
            error_log('CSRF validation failed: Token mismatch');
        }
        
        return $valid;
    }
    
    /**
     * Require valid CSRF token (throw exception if invalid)
     * 
     * @param string|null $token Token to validate
     * @throws \Exception If token is invalid
     */
    public static function requireToken(?string $token = null): void
    {
        if (!self::validateToken($token)) {
            http_response_code(403);
            throw new \Exception('CSRF token validation failed');
        }
    }
    
    /**
     * Get CSRF token as hidden input field
     * 
     * @return string HTML hidden input field
     */
    public static function getTokenField(): string
    {
        $token = self::getToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Get CSRF token as meta tag
     * 
     * @return string HTML meta tag
     */
    public static function getTokenMeta(): string
    {
        $token = self::getToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Regenerate CSRF token
     * 
     * @return string New CSRF token
     */
    public static function regenerateToken(): string
    {
        return self::generateToken();
    }
}
