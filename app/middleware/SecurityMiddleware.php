<?php

/**
 * Security Middleware
 * 
 * Provides security headers, CORS, and other security features
 */

namespace App\Middleware;

class SecurityMiddleware
{
    /**
     * Apply security headers
     */
    public static function applySecurityHeaders(): void
    {
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Prevent clickjacking
        header('X-Frame-Options: DENY');
        
        // Content Security Policy
        $csp = "default-src 'self'; " .
               "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com; " .
               "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " .
               "img-src 'self' data: https:; " .
               "connect-src 'self' wss: https:; " .
               "frame-src 'self'; " .
               "object-src 'none';";
        header("Content-Security-Policy: $csp");
        
        // Strict Transport Security (HSTS) - only in production
        if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // Remove server information
        header_remove('X-Powered-By');
    }
    
    /**
     * Handle CORS
     */
    public static function handleCORS(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Get allowed origins
        $allowedOrigins = self::getAllowedOrigins();
        
        // Check if origin is allowed
        $isAllowed = false;
        if (empty($origin)) {
            // Allow requests with no origin (like mobile apps, curl, server-to-server)
            $isAllowed = true;
        } else {
            foreach ($allowedOrigins as $allowedOrigin) {
                if (strpos($origin, $allowedOrigin) === 0) {
                    $isAllowed = true;
                    break;
                }
            }
        }
        
        if ($isAllowed) {
            header("Access-Control-Allow-Origin: $origin");
            header('Access-Control-Allow-Credentials: true');
        }
        
        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
            header('Access-Control-Max-Age: 86400'); // 24 hours
            http_response_code(200);
            exit;
        }
    }
    
    /**
     * Get allowed origins from environment
     */
    private static function getAllowedOrigins(): array
    {
        $origins = [
            $_ENV['FRONTEND_URL'] ?? null,
            $_ENV['CUSTOM_DOMAIN'] ?? null,
            'http://localhost:3000',
            'http://localhost:3001',
            'http://localhost:5173',
            'https://vtmoption.com',
        ];
        
        return array_filter($origins);
    }
    
    /**
     * Require HTTPS in production
     */
    public static function requireHTTPS(): void
    {
        // Skip HTTPS redirect in development
        if (($_ENV['APP_ENV'] ?? 'development') === 'development') {
            return;
        }
        
        // Check if request is already HTTPS
        $isHTTPS = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        );
        
        if (!$isHTTPS) {
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $uri = $_SERVER['REQUEST_URI'] ?? '/';
            header("Location: https://$host$uri", true, 301);
            exit;
        }
    }
    
    /**
     * Apply all security measures
     */
    public static function applyAll(): void
    {
        self::requireHTTPS();
        self::applySecurityHeaders();
        self::handleCORS();
    }
}

