<?php

/**
 * Response Helper
 * 
 * Provides convenient methods for JSON responses
 */

namespace App\Utils;

class Response
{
    /**
     * Send JSON response
     */
    public static function json(array $data, int $statusCode = 200): void
    {
        // Clean ALL previous output (including any errors)
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Start fresh output buffer
        ob_start();
        
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        ob_end_flush();
        exit;
    }
    
    /**
     * Send success response
     */
    public static function success(array $data = [], string $message = null, int $statusCode = 200): void
    {
        $response = $data;
        if ($message) {
            $response['message'] = $message;
        }
        self::json($response, $statusCode);
    }
    
    /**
     * Send error response
     */
    public static function error(string $error, int $statusCode = 400, array $details = []): void
    {
        $response = ['error' => $error];
        if (!empty($details)) {
            $response['details'] = $details;
        }
        self::json($response, $statusCode);
    }
    
    /**
     * Send validation error response
     */
    public static function validationError(array $errors): void
    {
        self::json(['errors' => $errors], 400);
    }
}

