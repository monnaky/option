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
        $logPrefix = "[EncryptionService::decrypt]";
        error_log("{$logPrefix} START - Encrypted data length: " . strlen($encryptedData));
        
        try {
            // Step 1: Get encryption key
            error_log("{$logPrefix} Step 1: Getting encryption key");
            try {
                $key = self::getEncryptionKey();
                error_log("{$logPrefix} Step 1: OK - Key length: " . strlen($key));
            } catch (Exception $e) {
                $error = "Failed to get encryption key: " . $e->getMessage();
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error, 0, $e);
            }
            
            // Step 2: Validate format
            error_log("{$logPrefix} Step 2: Validating encrypted data format");
            if (empty($encryptedData)) {
                $error = 'Encrypted data is empty';
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            // Step 3: Split the encrypted data
            error_log("{$logPrefix} Step 3: Splitting encrypted data");
            $parts = explode(':', $encryptedData);
            
            if (count($parts) !== 3) {
                $error = 'Invalid encrypted data format - expected 3 parts separated by :, got ' . count($parts);
                error_log("{$logPrefix} ERROR: {$error}");
                error_log("{$logPrefix} Data preview: " . substr($encryptedData, 0, 100) . '...');
                throw new Exception($error);
            }
            
            [$ivHex, $tagHex, $encryptedHex] = $parts;
            error_log("{$logPrefix} Step 3: OK - IV length: " . strlen($ivHex) . ", Tag length: " . strlen($tagHex) . ", Encrypted length: " . strlen($encryptedHex));
            
            // Step 4: Convert from hex
            error_log("{$logPrefix} Step 4: Converting hex to binary");
            $iv = hex2bin($ivHex);
            $tag = hex2bin($tagHex);
            $encrypted = hex2bin($encryptedHex);
            
            if ($iv === false) {
                $error = 'Invalid hex encoding in IV (length: ' . strlen($ivHex) . ')';
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            if ($tag === false) {
                $error = 'Invalid hex encoding in tag (length: ' . strlen($tagHex) . ')';
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            if ($encrypted === false) {
                $error = 'Invalid hex encoding in encrypted data (length: ' . strlen($encryptedHex) . ')';
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            if (strlen($iv) !== self::IV_LENGTH) {
                $error = "Invalid IV length: expected " . self::IV_LENGTH . ", got " . strlen($iv);
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            if (strlen($tag) !== self::TAG_LENGTH) {
                $error = "Invalid tag length: expected " . self::TAG_LENGTH . ", got " . strlen($tag);
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            error_log("{$logPrefix} Step 4: OK - IV: " . strlen($iv) . " bytes, Tag: " . strlen($tag) . " bytes, Encrypted: " . strlen($encrypted) . " bytes");
            
            // Step 5: Decrypt with authentication tag
            error_log("{$logPrefix} Step 5: Decrypting with OpenSSL");
            $decrypted = openssl_decrypt(
                $encrypted,
                self::ALGORITHM,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($decrypted === false) {
                $error = 'Decryption failed - invalid key or corrupted data';
                error_log("{$logPrefix} ERROR: {$error}");
                error_log("{$logPrefix} OpenSSL error: " . openssl_error_string());
                
                // Check if key might be wrong
                $keySource = $_ENV['ENCRYPTION_KEY'] ?? 'NOT SET';
                $keyLength = strlen($keySource);
                error_log("{$logPrefix} Encryption key source length: {$keyLength}");
                error_log("{$logPrefix} Encryption key preview: " . substr($keySource, 0, 20) . '...');
                
                throw new Exception($error);
            }
            
            if (empty($decrypted)) {
                $error = 'Decryption succeeded but result is empty';
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            error_log("{$logPrefix} Step 5: OK - Decrypted length: " . strlen($decrypted));
            error_log("{$logPrefix} SUCCESS - Decryption completed");
            
            return $decrypted;
            
        } catch (Exception $e) {
            $error = "Decryption failed: " . $e->getMessage();
            error_log("{$logPrefix} FATAL ERROR: {$error}");
            error_log("{$logPrefix} Exception type: " . get_class($e));
            error_log("{$logPrefix} Exception trace: " . $e->getTraceAsString());
            throw new Exception($error, 0, $e);
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
