<?php

/**
 * Admin Authentication Middleware
 * 
 * Verifies that the current user has admin privileges before allowing access
 * to admin-only pages and API endpoints.
 */

namespace App\Middleware;

use App\Config\Database;
use Exception;

class AdminMiddleware
{
    /**
     * Check if current user is an admin
     * 
     * @return array|null Returns admin user data if authenticated, null otherwise
     */
    public static function checkAdmin(): ?array
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        // Get database instance
        $db = Database::getInstance();
        
        // Check if user exists and is an admin
        $user = $db->queryOne(
            "SELECT id, email, is_active, is_admin 
             FROM users 
             WHERE id = :id AND is_active = 1 AND is_admin = 1",
            ['id' => $_SESSION['user_id']]
        );
        
        if (!$user) {
            return null;
        }
        
        return [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'is_admin' => true,
        ];
    }
    
    /**
     * Require admin access - redirect or return error if not admin
     * 
     * @param bool $jsonResponse If true, returns JSON error. If false, redirects to homepage.
     * @return array Admin user data
     * @throws Exception If not admin
     */
    public static function requireAdmin(bool $jsonResponse = false): array
    {
        $admin = self::checkAdmin();
        
        if (!$admin) {
            if ($jsonResponse) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Admin access required',
                    'message' => 'You do not have permission to access this resource'
                ]);
                exit;
            } else {
                // Redirect to homepage with error message
                if (!function_exists('url')) {
                    require_once __DIR__ . '/../helpers.php';
                }
                
                $_SESSION['error'] = 'Access denied. Admin privileges required.';
                header('Location: ' . url('index.php'));
                exit;
            }
        }
        
        return $admin;
    }
    
    /**
     * Check if current user is admin (boolean)
     * 
     * @return bool
     */
    public static function isAdmin(): bool
    {
        return self::checkAdmin() !== null;
    }
    
    /**
     * Get admin user ID
     * 
     * @return int|null
     */
    public static function getAdminId(): ?int
    {
        $admin = self::checkAdmin();
        return $admin ? (int)$admin['id'] : null;
    }
}

