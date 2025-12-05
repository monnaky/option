<?php

/**
 * CSRF Protection
 * 
 * Provides Cross-Site Request Forgery protection
 */

namespace App\Middleware;

use Exception;

class CSRFProtection
{
    private const TOKEN_NAME = 'csrf_token';
    private const TOKEN_LENGTH = 32;
    
    /**
     * Generate CSRF token
     */
    public static function generateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        $_SESSION[self::TOKEN_NAME] = $token;
        
        return $token;
    }
    
    /**
     * Get current CSRF token (generate if doesn't exist)
     */
    public static function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return self::generateToken();
        }
        
        return $_SESSION[self::TOKEN_NAME];
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateToken(?string $token = null): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Get token from request if not provided
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? 
                     ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        }
        
        if (empty($token)) {
            return false;
        }
        
        if (!isset($_SESSION[self::TOKEN_NAME])) {
            return false;
        }
        
        // Use hash_equals for timing-safe comparison
        return hash_equals($_SESSION[self::TOKEN_NAME], $token);
    }
    
    /**
     * Require valid CSRF token (throws exception if invalid)
     */
    public static function requireToken(?string $token = null): void
    {
        if (!self::validateToken($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid CSRF token']);
            exit;
        }
    }
    
    /**
     * Regenerate CSRF token
     */
    public static function regenerateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        unset($_SESSION[self::TOKEN_NAME]);
        return self::generateToken();
    }
    
    /**
     * Get token name for form fields
     */
    public static function getTokenName(): string
    {
        return self::TOKEN_NAME;
    }
    
    /**
     * Generate hidden input field HTML
     */
    public static function getHiddenInput(): string
    {
        $token = self::getToken();
        $name = self::getTokenName();
        return sprintf('<input type="hidden" name="%s" value="%s">', 
                       htmlspecialchars($name), 
                       htmlspecialchars($token));
    }
}

