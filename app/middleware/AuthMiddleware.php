<?php

/**
 * Authentication Middleware
 * 
 * Handles session-based authentication for API endpoints
 */

namespace App\Middleware;

use App\Config\Database;
use App\Utils\DatabaseHelper;

class AuthMiddleware
{
    /**
     * Authenticate user request
     * 
     * @return array|null Returns user data if authenticated, null otherwise
     */
    public static function authenticate(): ?array
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if user is logged in
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_email'])) {
            return null;
        }
        
        // Verify user still exists and is active
        $db = Database::getInstance();
        $user = $db->queryOne(
            "SELECT id, email, is_active FROM users WHERE id = :id AND is_active = 1",
            ['id' => $_SESSION['user_id']]
        );
        
        if (!$user) {
            // Clear invalid session
            session_destroy();
            return null;
        }
        
        return [
            'id' => $user['id'],
            'email' => $user['email'],
        ];
    }
    
    /**
     * Require authentication - send error if not authenticated
     */
    public static function requireAuth(): array
    {
        $user = self::authenticate();
        
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        
        return $user;
    }
    
    /**
     * Get current user ID
     */
    public static function getUserId(): ?int
    {
        $user = self::authenticate();
        return $user ? (int)$user['id'] : null;
    }
    
    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool
    {
        return self::authenticate() !== null;
    }
}

