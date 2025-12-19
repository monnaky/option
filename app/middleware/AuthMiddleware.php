<?php

/**
 * Authentication Middleware
 * 
 * Wrapper for Authentication class - used by API endpoints
 * This ensures consistency between web and API authentication
 */

namespace App\Middleware;

use App\Middleware\Authentication;

class AuthMiddleware
{
    /**
     * Authenticate user request (compatible with old code)
     */
    public static function authenticate(): ?array
    {
        return Authentication::apiAuthenticate();
    }
    
    /**
     * Require authentication - send error if not authenticated
     * Compatible with existing API calls
     */
    public static function requireAuth(): array
    {
        return Authentication::requireApiAuth();
    }
    
    /**
     * Get current user ID
     */
    public static function getUserId(): ?int
    {
        return Authentication::getUserId();
    }
    
    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool
    {
        return Authentication::isLoggedIn();
    }
    
    /**
     * NEW: Handle API auth with custom response
     * Useful for endpoints that need specific behavior
     */
    public static function requireAuthWithResponse(callable $onFailure = null): array
    {
        $user = self::authenticate();
        
        if (!$user) {
            if ($onFailure) {
                return $onFailure();
            }
            
            // Default failure response
            return Authentication::requireApiAuth();
        }
        
        return $user;
    }
    
    /**
     * NEW: Check auth without exiting (for conditional logic)
     */
    public static function checkAuth(): array
    {
        $user = self::authenticate();
        
        if (!$user) {
            throw new \Exception('Authentication required', 401);
        }
        
        return $user;
    }
}
