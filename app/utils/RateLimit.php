<?php

/**
 * Rate Limiting Utility
 * 
 * Provides rate limiting for API endpoints to prevent abuse
 */

namespace App\Utils;

use App\Config\Database;

class RateLimit
{
    /**
     * Check if request should be rate limited
     * 
     * @param string $identifier Unique identifier (IP, user ID, API key, etc.)
     * @param string $action Action being rate limited
     * @param int $maxRequests Maximum requests allowed
     * @param int $windowSeconds Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    public static function check(
        string $identifier,
        string $action,
        int $maxRequests = 60,
        int $windowSeconds = 60
    ): array {
        // Check if rate limiting is enabled
        $rateLimitEnabled = $_ENV['RATE_LIMIT_ENABLED'] ?? 
                           $_SERVER['RATE_LIMIT_ENABLED'] ?? 
                           getenv('RATE_LIMIT_ENABLED') ?? 
                           'true';
                           
        if (strtolower((string)$rateLimitEnabled) === 'false') {
            // Rate limiting disabled - allow all requests
            return [
                'allowed' => true,
                'remaining' => $maxRequests,
                'reset_at' => time() + $windowSeconds,
                'limit' => $maxRequests,
            ];
        }
        
        $db = Database::getInstance();
        
        // Create rate_limits table if not exists
        self::ensureTable($db);
        
        $now = time();
        $windowStart = $now - $windowSeconds;
        
        // Clean up old entries
        $db->execute(
            "DELETE FROM rate_limits WHERE created_at < :window_start",
            ['window_start' => date('Y-m-d H:i:s', $windowStart)]
        );
        
        // Count requests in current window
        $count = $db->queryValue(
            "SELECT COUNT(*) FROM rate_limits 
             WHERE identifier = :identifier 
             AND action = :action 
             AND created_at >= :window_start",
            [
                'identifier' => $identifier,
                'action' => $action,
                'window_start' => date('Y-m-d H:i:s', $windowStart),
            ]
        );
        
        $remaining = max(0, $maxRequests - $count);
        $allowed = $count < $maxRequests;
        
        if ($allowed) {
            // Record this request
            $db->insert('rate_limits', [
                'identifier' => $identifier,
                'action' => $action,
                'created_at' => date('Y-m-d H:i:s', $now),
            ]);
        }
        
        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $now + $windowSeconds,
            'limit' => $maxRequests,
        ];
    }
    
    /**
     * Require rate limit check (throw exception if exceeded)
     * 
     * @param string $identifier Unique identifier
     * @param string $action Action being rate limited
     * @param int $maxRequests Maximum requests allowed
     * @param int $windowSeconds Time window in seconds
     * @throws \Exception If rate limit exceeded
     */
    public static function require(
        string $identifier,
        string $action,
        int $maxRequests = 60,
        int $windowSeconds = 60
    ): void {
        $result = self::check($identifier, $action, $maxRequests, $windowSeconds);
        
        if (!$result['allowed']) {
            http_response_code(429);
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $result['reset_at']);
            header('Retry-After: ' . ($result['reset_at'] - time()));
            
            throw new \Exception('Rate limit exceeded. Please try again later.');
        }
        
        // Add rate limit headers
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset_at']);
    }
    
    /**
     * Get identifier from request (IP address or user ID)
     * 
     * @param int|null $userId User ID if authenticated
     * @return string Identifier
     */
    public static function getIdentifier(?int $userId = null): string
    {
        if ($userId !== null) {
            return 'user_' . $userId;
        }
        
        // Get IP address
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_X_REAL_IP'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? 
              'unknown';
        
        // Handle multiple IPs in X-Forwarded-For
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        
        return 'ip_' . $ip;
    }
    
    /**
     * Ensure rate_limits table exists
     */
    private static function ensureTable(Database $db): void
    {
        static $tableChecked = false;
        
        if ($tableChecked) {
            return;
        }
        
        try {
            $db->execute("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(255) NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    created_at DATETIME NOT NULL,
                    INDEX idx_identifier_action (identifier, action),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $tableChecked = true;
        } catch (\Exception $e) {
            error_log('Failed to create rate_limits table: ' . $e->getMessage());
        }
    }
    
    /**
     * Clear rate limit for identifier
     * 
     * @param string $identifier Identifier to clear
     * @param string|null $action Specific action to clear (null for all)
     */
    public static function clear(string $identifier, ?string $action = null): void
    {
        $db = Database::getInstance();
        
        if ($action === null) {
            $db->delete('rate_limits', ['identifier' => $identifier]);
        } else {
            $db->delete('rate_limits', [
                'identifier' => $identifier,
                'action' => $action,
            ]);
        }
    }
}
