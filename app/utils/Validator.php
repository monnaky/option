<?php

/**
 * Input Validation and Sanitization Utility
 * 
 * Comprehensive input validation and sanitization functions
 */

namespace App\Utils;

class Validator
{
    /**
     * Validate email
     */
    public static function email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate and sanitize email
     */
    public static function emailSanitized(string $email): ?string
    {
        $email = trim($email);
        $email = filter_var($email, FILTER_SANITIZE_EMAIL);
        
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return strtolower($email);
        }
        
        return null;
    }
    
    /**
     * Validate password (min 6 characters, recommended: 8+ with complexity)
     */
    public static function password(string $password, bool $strict = false): bool
    {
        if (strlen($password) < 6) {
            return false;
        }
        
        if ($strict) {
            // Require at least one uppercase, one lowercase, one number
            return preg_match('/[A-Z]/', $password) &&
                   preg_match('/[a-z]/', $password) &&
                   preg_match('/[0-9]/', $password);
        }
        
        return true;
    }
    
    /**
     * Sanitize string input
     */
    public static function sanitize(string $input, int $maxLength = null): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Remove HTML tags
        $input = strip_tags($input);
        
        // Escape special characters
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        // Limit length if specified
        if ($maxLength !== null && strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        
        return $input;
    }
    
    /**
     * Sanitize for database (allows some HTML but removes dangerous tags)
     */
    public static function sanitizeForDatabase(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Remove dangerous tags and attributes
        $dangerousTags = ['script', 'iframe', 'object', 'embed', 'form', 'input', 'button'];
        foreach ($dangerousTags as $tag) {
            $input = preg_replace('/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is', '', $input);
            $input = preg_replace('/<' . $tag . '[^>]*>/i', '', $input);
        }
        
        // Remove dangerous attributes
        $dangerousAttributes = ['onerror', 'onclick', 'onload', 'onmouseover', 'onfocus'];
        foreach ($dangerousAttributes as $attr) {
            $input = preg_replace('/\s*' . $attr . '\s*=\s*["\'][^"\']*["\']/i', '', $input);
        }
        
        return $input;
    }
    
    /**
     * Validate numeric value
     */
    public static function numeric($value, ?float $min = null, ?float $max = null): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        
        $num = (float)$value;
        
        if ($min !== null && $num < $min) {
            return false;
        }
        
        if ($max !== null && $num > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Sanitize numeric value
     */
    public static function numericSanitized($value, ?float $min = null, ?float $max = null): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        
        $num = (float)$value;
        
        if ($min !== null && $num < $min) {
            return null;
        }
        
        if ($max !== null && $num > $max) {
            return null;
        }
        
        return $num;
    }
    
    /**
     * Validate required fields
     */
    public static function required(array $data, array $fields): array
    {
        $errors = [];
        
        foreach ($fields as $field) {
            if (!isset($data[$field]) || 
                (is_string($data[$field]) && trim($data[$field]) === '') ||
                $data[$field] === null) {
                $errors[] = "Field '{$field}' is required";
            }
        }
        
        return $errors;
    }
    
    /**
     * Validate string length
     */
    public static function length(string $value, ?int $min = null, ?int $max = null): bool
    {
        $len = mb_strlen($value, 'UTF-8');
        
        if ($min !== null && $len < $min) {
            return false;
        }
        
        if ($max !== null && $len > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate URL
     */
    public static function url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Sanitize URL
     */
    public static function urlSanitized(string $url): ?string
    {
        $url = trim($url);
        $url = filter_var($url, FILTER_SANITIZE_URL);
        
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        
        return null;
    }
    
    /**
     * Validate integer
     */
    public static function integer($value, ?int $min = null, ?int $max = null): bool
    {
        if (!is_numeric($value) || (int)$value != $value) {
            return false;
        }
        
        $int = (int)$value;
        
        if ($min !== null && $int < $min) {
            return false;
        }
        
        if ($max !== null && $int > $max) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Sanitize integer
     */
    public static function integerSanitized($value, ?int $min = null, ?int $max = null): ?int
    {
        if (!is_numeric($value) || (int)$value != $value) {
            return null;
        }
        
        $int = (int)$value;
        
        if ($min !== null && $int < $min) {
            return null;
        }
        
        if ($max !== null && $int > $max) {
            return null;
        }
        
        return $int;
    }
    
    /**
     * Validate boolean
     */
    public static function boolean($value): bool
    {
        return in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', 'yes', 'no'], true);
    }
    
    /**
     * Sanitize boolean
     */
    public static function booleanSanitized($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_string($value)) {
            $value = strtolower(trim($value));
            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        }
        
        return (bool)$value;
    }
    
    /**
     * Validate array
     */
    public static function array($value, ?int $minItems = null, ?int $maxItems = null): bool
    {
        if (!is_array($value)) {
            return false;
        }
        
        $count = count($value);
        
        if ($minItems !== null && $count < $minItems) {
            return false;
        }
        
        if ($maxItems !== null && $count > $maxItems) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Sanitize all values in array
     */
    public static function sanitizeArray(array $data, array $rules = []): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            $key = self::sanitize($key);
            
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeArray($value, $rules[$key] ?? []);
            } else {
                // Apply specific rule if provided
                if (isset($rules[$key])) {
                    $rule = $rules[$key];
                    
                    if ($rule === 'email') {
                        $sanitized[$key] = self::emailSanitized($value) ?? '';
                    } elseif ($rule === 'int') {
                        $sanitized[$key] = self::integerSanitized($value) ?? 0;
                    } elseif ($rule === 'float') {
                        $sanitized[$key] = self::numericSanitized($value) ?? 0.0;
                    } elseif ($rule === 'bool') {
                        $sanitized[$key] = self::booleanSanitized($value);
                    } else {
                        $sanitized[$key] = self::sanitize($value);
                    }
                } else {
                    $sanitized[$key] = self::sanitize($value);
                }
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Validate API token format (Deriv API tokens are typically alphanumeric)
     */
    public static function apiToken(string $token): bool
    {
        // Deriv API tokens are typically 32+ character alphanumeric strings
        return preg_match('/^[a-zA-Z0-9]{32,}$/', $token) === 1;
    }
    
    /**
     * Sanitize API token
     */
    public static function apiTokenSanitized(string $token): ?string
    {
        $token = trim($token);
        
        // Remove any whitespace
        $token = preg_replace('/\s+/', '', $token);
        
        // Check if valid format
        if (self::apiToken($token)) {
            return $token;
        }
        
        return null;
    }
}
