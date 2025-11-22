<?php

/**
 * Rate Limiter
 * 
 * Provides rate limiting to prevent abuse
 */

namespace App\Middleware;

use App\Config\Database;
use Exception;

class RateLimiter
{
    private const DEFAULT_WINDOW = 900; // 15 minutes in seconds
    private const DEFAULT_MAX_REQUESTS = 100;
    
    /**
     * Check rate limit
     * 
     * @param string $identifier Unique identifier (IP address, user ID, etc.)
     * @param int $maxRequests Maximum requests allowed
     * @param int $windowSeconds Time window in seconds
     * @return array ['allowed' => bool, 'remaining' => int, 'reset' => int]
     */
    public static function check(
        string $identifier,
        int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        int $windowSeconds = self::DEFAULT_WINDOW
    ): array {
        try {
            $db = Database::getInstance();
            
            // Create rate limit table if it doesn't exist
            self::ensureTableExists($db);
            
            $now = time();
            $windowStart = $now - $windowSeconds;
            
            // Clean up old entries
            $db->execute(
                "DELETE FROM rate_limits WHERE expires_at < :now",
                ['now' => date('Y-m-d H:i:s', $windowStart)]
            );
            
            // Count requests in current window
            $count = $db->queryValue(
                "SELECT COUNT(*) FROM rate_limits 
                 WHERE identifier = :identifier 
                 AND created_at > :window_start",
                [
                    'identifier' => $identifier,
                    'window_start' => date('Y-m-d H:i:s', $windowStart)
                ]
            ) ?? 0;
            
            $allowed = $count < $maxRequests;
            $remaining = max(0, $maxRequests - $count);
            $reset = $now + $windowSeconds;
            
            // Record this request
            if ($allowed) {
                $db->insert('rate_limits', [
                    'identifier' => $identifier,
                    'created_at' => date('Y-m-d H:i:s'),
                    'expires_at' => date('Y-m-d H:i:s', $reset),
                ]);
            }
            
            return [
                'allowed' => $allowed,
                'remaining' => $remaining,
                'reset' => $reset,
                'limit' => $maxRequests,
            ];
            
        } catch (Exception $e) {
            error_log('Rate limit error: ' . $e->getMessage());
            // On error, allow the request (fail open)
            return [
                'allowed' => true,
                'remaining' => $maxRequests,
                'reset' => time() + $windowSeconds,
                'limit' => $maxRequests,
            ];
        }
    }
    
    /**
     * Require rate limit (throws exception if exceeded)
     */
    public static function require(
        string $identifier,
        int $maxRequests = self::DEFAULT_MAX_REQUESTS,
        int $windowSeconds = self::DEFAULT_WINDOW
    ): void {
        $result = self::check($identifier, $maxRequests, $windowSeconds);
        
        if (!$result['allowed']) {
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: ' . ($result['reset'] - time()));
            header('X-RateLimit-Limit: ' . $result['limit']);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $result['reset']);
            echo json_encode([
                'error' => 'Too many requests, please try again later.',
                'retry_after' => $result['reset'] - time(),
            ]);
            exit;
        }
        
        // Set rate limit headers
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset']);
    }
    
    /**
     * Get client identifier (IP address)
     */
    public static function getClientIdentifier(): string
    {
        // Check for forwarded IP (from proxy/load balancer)
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 
              $_SERVER['HTTP_X_REAL_IP'] ?? 
              $_SERVER['REMOTE_ADDR'] ?? 
              'unknown';
        
        // If X-Forwarded-For contains multiple IPs, take the first one
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        
        return $ip;
    }
    
    /**
     * Ensure rate_limits table exists
     */
    private static function ensureTableExists(Database $db): void
    {
        try {
            $db->execute("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    identifier VARCHAR(255) NOT NULL,
                    created_at DATETIME NOT NULL,
                    expires_at DATETIME NOT NULL,
                    INDEX idx_identifier (identifier),
                    INDEX idx_expires (expires_at),
                    INDEX idx_identifier_created (identifier, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            // Table might already exist, ignore
        }
    }
    
    /**
     * Predefined rate limiters
     */
    public static function api(): void
    {
        self::require(
            self::getClientIdentifier(),
            100, // 100 requests
            900  // per 15 minutes
        );
    }
    
    public static function auth(): void
    {
        self::require(
            self::getClientIdentifier(),
            5,   // 5 requests
            900  // per 15 minutes
        );
    }
    
    public static function strict(): void
    {
        self::require(
            self::getClientIdentifier(),
            3,   // 3 requests
            3600 // per hour
        );
    }
}

