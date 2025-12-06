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
        return self::resolveKeyFromEnv('ENCRYPTION_KEY');
    }

    /**
     * Resolve key material from environment
     */
    private static function resolveKeyFromEnv(string $envKey): string
    {
        $rawKey = $_ENV[$envKey] ?? '';
        
        if (empty($rawKey)) {
            throw new Exception("{$envKey} is not set in environment");
        }
        
        return self::normalizeKeyMaterial($rawKey, $envKey);
    }

    /**
     * Normalize raw key material to 32 bytes
     */
    private static function normalizeKeyMaterial(string $keyMaterial, string $label): string
    {
        if (strlen($keyMaterial) === 64 && ctype_xdigit($keyMaterial)) {
            $binary = @hex2bin($keyMaterial);
            if ($binary === false) {
                throw new Exception("{$label} contains invalid hex characters");
            }
            return $binary;
        }
        
        return hash('sha256', $keyMaterial, true);
    }

    /**
     * Core decrypt implementation reused by all code paths
     */
    private static function decryptPayload(string $encryptedData, string $key, string $label = 'ENCRYPTION_KEY'): string
    {
        $logPrefix = "[EncryptionService::decrypt][{$label}]";
        error_log("{$logPrefix} START - Encrypted data length: " . strlen($encryptedData));
        
        // Step 1: Validate format
        error_log("{$logPrefix} Step 1: Validating encrypted data format");
        if (empty($encryptedData)) {
            $error = 'Encrypted data is empty';
            error_log("{$logPrefix} ERROR: {$error}");
            throw new Exception($error);
        }
        
        // Step 2: Split the encrypted data
        error_log("{$logPrefix} Step 2: Splitting encrypted data");
        $parts = explode(':', $encryptedData);
        
        if (count($parts) !== 3) {
            $error = 'Invalid encrypted data format - expected 3 parts separated by :, got ' . count($parts);
            error_log("{$logPrefix} ERROR: {$error}");
            error_log("{$logPrefix} Data preview: " . substr($encryptedData, 0, 100) . '...');
            throw new Exception($error);
        }
        
        [$ivHex, $tagHex, $encryptedHex] = $parts;
        error_log("{$logPrefix} Step 2: OK - IV length: " . strlen($ivHex) . ", Tag length: " . strlen($tagHex) . ", Encrypted length: " . strlen($encryptedHex));
        
        // Step 3: Convert from hex
        error_log("{$logPrefix} Step 3: Converting hex to binary");
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
        
        error_log("{$logPrefix} Step 3: OK - IV: " . strlen($iv) . " bytes, Tag: " . strlen($tag) . " bytes, Encrypted: " . strlen($encrypted) . " bytes");
        
        // Step 4: Decrypt with authentication tag
        error_log("{$logPrefix} Step 4: Decrypting with OpenSSL");
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
            throw new Exception($error);
        }
        
        if (empty($decrypted)) {
            $error = 'Decryption succeeded but result is empty';
            error_log("{$logPrefix} ERROR: {$error}");
            throw new Exception($error);
        }
        
        error_log("{$logPrefix} Step 4: OK - Decrypted length: " . strlen($decrypted));
        error_log("{$logPrefix} SUCCESS - Decryption completed");
        
        return $decrypted;
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
        [$token] = self::decryptWithLegacySupport($encryptedData);
        return $token;
    }

    /**
     * Decrypt data with automatic legacy key fallback.
     *
     * @return array{string,bool} [token, usedLegacyKey]
     * @throws Exception
     */
    public static function decryptWithLegacySupport(string $encryptedData): array
    {
        $primaryKey = self::getEncryptionKey();
        
        try {
            $token = self::decryptPayload($encryptedData, $primaryKey, 'ENCRYPTION_KEY');
            return [$token, false];
        } catch (Exception $primaryException) {
            $legacyRaw = $_ENV['LEGACY_ENCRYPTION_KEY'] ?? '';
            if (empty($legacyRaw)) {
                throw $primaryException;
            }
            
            try {
                $legacyKey = self::normalizeKeyMaterial($legacyRaw, 'LEGACY_ENCRYPTION_KEY');
                $token = self::decryptPayload($encryptedData, $legacyKey, 'LEGACY_ENCRYPTION_KEY');
                error_log('[EncryptionService] Legacy encryption key used to decrypt data');
                return [$token, true];
            } catch (Exception $legacyException) {
                error_log('[EncryptionService] Legacy key fallback failed: ' . $legacyException->getMessage());
                throw $primaryException;
            }
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
