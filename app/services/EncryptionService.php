<?php

/**
 * Encryption Service
 * 
 * Handles encryption and decryption of sensitive data (API tokens)
 * Uses AES-256-GCM to match Node.js implementation
 */

namespace App\Services;

use Exception;

class EncryptionService
{
    private const ALGORITHM = 'aes-256-gcm';
    private const IV_LENGTH = 16;
    private const TAG_LENGTH = 16;
    private const KEY_LENGTH = 32;
    
    /**
     * Get encryption key from environment
     */
    private static function getEncryptionKey(): string
    {
        $key = $_ENV['ENCRYPTION_KEY'] ?? '';
        
        if (empty($key)) {
            throw new Exception('ENCRYPTION_KEY is not set in environment');
        }
        
        // If key is hex string (64 chars = 32 bytes), convert it
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            return hex2bin($key);
        }
        
        // Otherwise, hash the key to get 32 bytes
        return hash('sha256', $key, true);
    }
    
    /**
     * Encrypt data using AES-256-GCM
     * 
     * Format: iv:tag:encrypted (all hex encoded)
     * 
     * @param string $text Plain text to encrypt
     * @return string Encrypted data in format "iv:tag:encrypted"
     * @throws Exception
     */
    public static function encrypt(string $text): string
    {
        try {
            $key = self::getEncryptionKey();
            $iv = random_bytes(self::IV_LENGTH);
            
            // Encrypt with authentication tag
            $encrypted = openssl_encrypt(
                $text,
                self::ALGORITHM,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($encrypted === false) {
                throw new Exception('Encryption failed');
            }
            
            // Combine iv + tag + encrypted data (all hex encoded)
            return bin2hex($iv) . ':' . bin2hex($tag) . ':' . bin2hex($encrypted);
            
        } catch (Exception $e) {
            error_log('Encryption error: ' . $e->getMessage());
            throw new Exception('Failed to encrypt data');
        }
    }
    
    /**
     * Decrypt data using AES-256-GCM
     * 
     * @param string $encryptedData Encrypted data in format "iv:tag:encrypted"
     * @return string Decrypted plain text
     * @throws Exception
     */
    public static function decrypt(string $encryptedData): string
    {
        try {
            $key = self::getEncryptionKey();
            
            // Split the encrypted data
            $parts = explode(':', $encryptedData);
            
            if (count($parts) !== 3) {
                throw new Exception('Invalid encrypted data format');
            }
            
            [$ivHex, $tagHex, $encryptedHex] = $parts;
            
            // Convert from hex
            $iv = hex2bin($ivHex);
            $tag = hex2bin($tagHex);
            $encrypted = hex2bin($encryptedHex);
            
            if ($iv === false || $tag === false || $encrypted === false) {
                throw new Exception('Invalid hex encoding in encrypted data');
            }
            
            // Decrypt with authentication tag
            $decrypted = openssl_decrypt(
                $encrypted,
                self::ALGORITHM,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($decrypted === false) {
                throw new Exception('Decryption failed - invalid key or corrupted data');
            }
            
            return $decrypted;
            
        } catch (Exception $e) {
            error_log('Decryption error: ' . $e->getMessage());
            throw new Exception('Failed to decrypt data');
        }
    }
    
    /**
     * Check if encrypted data format is valid
     */
    public static function isValidFormat(string $encryptedData): bool
    {
        $parts = explode(':', $encryptedData);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        [$ivHex, $tagHex, $encryptedHex] = $parts;
        
        // Check if all parts are valid hex
        return ctype_xdigit($ivHex) && ctype_xdigit($tagHex) && ctype_xdigit($encryptedHex);
    }
}
