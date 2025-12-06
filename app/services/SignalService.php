<?php

/**
 * Signal Service
 * 
 * Handles signal reception, validation, queue management, and bulk execution
 * for all active trading users
 */

namespace App\Services;

use App\Config\Database;
use App\Utils\DatabaseHelper;
use App\Services\TradingBotService;
use App\Services\EncryptionService;
use Exception;

class SignalService
{
    private static ?SignalService $instance = null;
    private Database $db;
    private DatabaseHelper $helper;
    private TradingBotService $tradingBot;
    
    // Configuration constants
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY_SECONDS = 5;
    private const MAX_SIGNAL_AGE_SECONDS = 300; // 5 minutes
    private const BATCH_SIZE = 50; // Process 50 users at a time
    private const EXECUTION_TIMEOUT = 30; // 30 seconds per user
    
    // Signal sources
    private const SOURCE_API = 'api';
    private const SOURCE_FILE = 'file';
    private const SOURCE_MANUAL = 'manual';
    
    // Signal types
    private const TYPE_RISE = 'RISE';
    private const TYPE_FALL = 'FALL';
    
    /**
     * Expose signal storage paths for diagnostics
     */
    public static function getSignalStoragePaths(): array
    {
        vtm_signal_ensure_paths();
        return [
            'primary' => vtm_signal_primary_path(),
            'public' => vtm_signal_public_path(),
        ];
    }
    
    /**
     * Private constructor (singleton pattern)
     */
    private function __construct()
    {
        $this->db = Database::getInstance();
        $this->helper = new DatabaseHelper();
        $this->tradingBot = TradingBotService::getInstance();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): SignalService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Receive and process a signal from external source
     * 
     * @param array $signalData Signal data (type, asset, source, etc.)
     * @return array Signal processing result
     */
    public function receiveSignal(array $signalData): array
    {
        try {
            // Validate signal
            $validation = $this->validateSignal($signalData);
            if (!$validation['valid']) {
                // If type is UNKNOWN but we have raw text, we might want to save it anyway for debugging?
                // For now, strict validation.
                if (($signalData['type'] ?? '') === 'UNKNOWN') {
                     // Try to parse from raw text if type is unknown
                     // (Simple fallback logic could go here)
                }
                
                if (!$validation['valid']) {
                     throw new Exception($validation['error']);
                }
            }
            
            // Extract signal information
            $signalType = $this->normalizeSignalType($signalData['type'] ?? $signalData['signalType'] ?? '');
            $asset = $signalData['asset'] ?? null;
            $rawText = $signalData['rawText'] ?? $signalData['raw_text'] ?? ($asset ? "{$signalType} {$asset}" : $signalType);
            $source = $signalData['source'] ?? self::SOURCE_API;
            $sourceIp = $signalData['sourceIp'] ?? $signalData['source_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            
            // Check for duplicate signals (within last 5 seconds)
            $duplicate = $this->checkDuplicateSignal($signalType, $asset, $rawText);
            if ($duplicate) {
                error_log("Duplicate signal detected: {$rawText}");
                return [
                    'success' => false,
                    'error' => 'Duplicate signal detected',
                    'signal_id' => $duplicate['id'],
                ];
            }
            
            // Create signal record
            $signalId = $this->helper->createSignal([
                'signal_type' => $signalType,
                'asset' => $asset,
                'raw_text' => $rawText,
                'source' => $source,
                'source_ip' => $sourceIp,
            ]);
            
            // Check if we should skip immediate execution (e.g. if using queue)
            if (!empty($signalData['skip_execution'])) {
                return [
                    'success' => true,
                    'signal_id' => $signalId,
                    'signal_type' => $signalType,
                    'asset' => $asset,
                    'queued' => true,
                ];
            }
            
            // Process signal immediately (Legacy/Direct mode)
            $executionResult = $this->executeSignalForAllUsers($signalId, $signalType, $asset);
            
            return [
                'success' => true,
                'signal_id' => $signalId,
                'signal_type' => $signalType,
                'asset' => $asset,
                'execution' => $executionResult,
            ];
            
        } catch (Exception $e) {
            error_log("Signal reception error: " . $e->getMessage());
            throw $e; // Re-throw or return error array
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Validate signal data
     */
    private function validateSignal(array $signalData): array
    {
        // Check required fields
        $type = $this->normalizeSignalType($signalData['type'] ?? $signalData['signalType'] ?? '');
        
        if (empty($type)) {
            return [
                'valid' => false,
                'error' => 'Signal type is required',
            ];
        }
        
        if (!in_array($type, [self::TYPE_RISE, self::TYPE_FALL])) {
            return [
                'valid' => false,
                'error' => 'Signal type must be RISE or FALL',
            ];
        }
        
        // Validate asset if provided
        if (isset($signalData['asset']) && !empty($signalData['asset'])) {
            $asset = $signalData['asset'];
            // Basic validation - should be a valid asset symbol
            if (strlen($asset) > 50) {
                return [
                    'valid' => false,
                    'error' => 'Asset symbol too long',
                ];
            }
        }
        
        return ['valid' => true];
    }

    /**
     * Normalize various direction keywords to internal signal types.
     */
    private function normalizeSignalType(?string $type): string
    {
        if ($type === null) {
            return '';
        }

        $normalized = strtoupper(trim($type));
        if ($normalized === '') {
            return '';
        }

        $buyMap = ['BUY', 'CALL', 'RISE', 'LONG', 'UP', 'BULL'];
        $sellMap = ['SELL', 'PUT', 'FALL', 'SHORT', 'DOWN', 'BEAR'];

        if (in_array($normalized, $buyMap, true)) {
            return self::TYPE_RISE;
        }

        if (in_array($normalized, $sellMap, true)) {
            return self::TYPE_FALL;
        }

        return $normalized;
    }
    
    /**
     * Check for duplicate signals (within last 5 seconds)
     */
    private function checkDuplicateSignal(string $signalType, ?string $asset, string $rawText): ?array
    {
        $sql = "
            SELECT id FROM signals 
            WHERE signal_type = :type
            AND raw_text = :raw_text
            AND timestamp > DATE_SUB(NOW(), INTERVAL 5 SECOND)
            ORDER BY timestamp DESC
            LIMIT 1
        ";
        
        $params = [
            'type' => $signalType,
            'raw_text' => $rawText,
        ];
        
        return $this->db->queryOne($sql, $params);
    }
    
    /**
     * Execute signal for all active users
     * 
     * @param int $signalId Signal database ID
     * @param string $signalType RISE or FALL
     * @param string|null $asset Optional asset symbol
     * @return array Execution summary
     */
    public function executeSignalForAllUsers(int $signalId, string $signalType, ?string $asset = null): array
    {
        $startTime = microtime(true);
        
        try {
            // Get all active users with bot active
            $activeUsers = $this->getActiveTradingUsers();
            
            $totalUsers = count($activeUsers);
            $successfulExecutions = 0;
            $failedExecutions = 0;
            $executionResults = [];
            
            if ($totalUsers === 0) {
                error_log("No active users found for signal execution");
                
                // Mark signal as processed with zero users
                $this->markSignalAsProcessed($signalId, 0, 0, 0, 0);
                
                return [
                    'total_users' => 0,
                    'successful' => 0,
                    'failed' => 0,
                    'execution_time' => 0,
                    'results' => [],
                ];
            }
            
            // Pre-validate users: Check API tokens before attempting trades
            error_log("Pre-validating {$totalUsers} users for API token availability");
            $validUsers = [];
            $skippedUsers = [];
            
            foreach ($activeUsers as $user) {
                $userId = (int)$user['id'];
                $userEmail = $user['email'] ?? 'unknown';
                $encryptedToken = $user['encrypted_api_token'] ?? null;
                
                // Check if token exists
                if (empty($encryptedToken)) {
                    $error = "User {$userId} ({$userEmail}) has no encrypted API token - skipping";
                    error_log($error);
                    $skippedUsers[] = [
                        'user_id' => $userId,
                        'email' => $userEmail,
                        'reason' => 'No encrypted API token',
                    ];
                    continue;
                }
                
                // Validate token format
                if (!EncryptionService::isValidFormat($encryptedToken)) {
                    $error = "User {$userId} ({$userEmail}) has invalid token format - skipping";
                    error_log($error);
                    $skippedUsers[] = [
                        'user_id' => $userId,
                        'email' => $userEmail,
                        'reason' => 'Invalid token format',
                    ];
                    continue;
                }
                
                // Try to decrypt token to verify it's valid
                try {
                    $decryptedToken = EncryptionService::decrypt($encryptedToken);
                    if (empty($decryptedToken)) {
                        $error = "User {$userId} ({$userEmail}) token decrypts to empty string - skipping";
                        error_log($error);
                        $skippedUsers[] = [
                            'user_id' => $userId,
                            'email' => $userEmail,
                            'reason' => 'Token decrypts to empty',
                        ];
                        continue;
                    }
                    error_log("User {$userId} ({$userEmail}) token validation OK - length: " . strlen($decryptedToken));
                } catch (Exception $e) {
                    $error = "User {$userId} ({$userEmail}) token decryption failed: " . $e->getMessage() . " - skipping";
                    error_log($error);
                    $skippedUsers[] = [
                        'user_id' => $userId,
                        'email' => $userEmail,
                        'reason' => 'Token decryption failed: ' . $e->getMessage(),
                    ];
                    continue;
                }
                
                // User passed all validation checks
                $validUsers[] = $user;
            }
            
            $validUserCount = count($validUsers);
            $skippedCount = count($skippedUsers);
            error_log("User validation complete - Valid: {$validUserCount}, Skipped: {$skippedCount}");
            
            if ($validUserCount === 0) {
                error_log("No users with valid API tokens found - cannot execute trades");
                $this->markSignalAsProcessed($signalId, $totalUsers, 0, 0, 0);
                return [
                    'total_users' => $totalUsers,
                    'valid_users' => 0,
                    'skipped_users' => $skippedCount,
                    'successful' => 0,
                    'failed' => 0,
                    'execution_time' => 0,
                    'results' => [],
                    'skipped' => $skippedUsers,
                ];
            }
            
            // Process users in batches (only valid users)
            $batches = array_chunk($validUsers, self::BATCH_SIZE);
            error_log("Processing {$validUserCount} valid users in " . count($batches) . " batch(es)");
            
            foreach ($batches as $batchIndex => $batch) {
                error_log("Processing batch " . ($batchIndex + 1) . " of " . count($batches) . " (" . count($batch) . " users)");
                
                foreach ($batch as $userIndex => $user) {
                    $userId = (int)$user['id'];
                    $userEmail = $user['email'] ?? 'unknown';
                    error_log("Processing user {$userId} ({$userEmail}) - " . ($userIndex + 1) . " of " . count($batch) . " in batch");
                    
                    try {
                        $result = $this->executeSignalForUser(
                            $userId,
                            $signalType,
                            $asset
                        );
                        
                        $executionResults[] = $result;
                        
                        if ($result['success']) {
                            $successfulExecutions++;
                            error_log("User {$userId} trade execution SUCCESS - trade_id: " . ($result['trade_id'] ?? 'N/A'));
                        } else {
                            $failedExecutions++;
                            $errorMsg = $result['error'] ?? 'Unknown error';
                            error_log("User {$userId} trade execution FAILED - Error: {$errorMsg}");
                            if (isset($result['exception_type'])) {
                                error_log("User {$userId} Exception type: {$result['exception_type']}");
                            }
                        }
                        
                    } catch (Exception $e) {
                        $error = "Unexpected exception for user {$userId}: " . $e->getMessage();
                        error_log("Signal execution EXCEPTION for user {$userId}: {$error}");
                        error_log("Exception type: " . get_class($e));
                        error_log("Exception trace: " . $e->getTraceAsString());
                        
                        $executionResults[] = [
                            'user_id' => $userId,
                            'success' => false,
                            'error' => $error,
                            'exception_type' => get_class($e),
                        ];
                        $failedExecutions++;
                    }
                }
            }
            
            $executionTime = (int)((microtime(true) - $startTime) * 1000); // milliseconds
            
            // Mark signal as processed
            $this->markSignalAsProcessed(
                $signalId,
                $totalUsers,
                $successfulExecutions,
                $failedExecutions,
                $executionTime
            );
            
            $summary = "Signal executed: {$signalType}" . ($asset ? " on {$asset}" : "") . " - Total: {$totalUsers}, Valid: {$validUserCount}, Skipped: {$skippedCount}, Successful: {$successfulExecutions}, Failed: {$failedExecutions}";
            error_log($summary);
            
            $this->writeTradeLog("=== SIGNAL EXECUTION SUMMARY ===", [
                'signal_type' => $signalType,
                'asset' => $asset,
                'total_users' => $totalUsers,
                'valid_users' => $validUserCount,
                'skipped_users' => $skippedCount,
                'successful_executions' => $successfulExecutions,
                'failed_executions' => $failedExecutions,
                'execution_time_ms' => $executionTime,
                'results' => $executionResults,
                'skipped_users_details' => $skippedUsers,
            ]);
            
            return [
                'total_users' => $totalUsers,
                'valid_users' => $validUserCount,
                'skipped_users' => $skippedCount,
                'successful' => $successfulExecutions,
                'failed' => $failedExecutions,
                'execution_time' => $executionTime,
                'results' => $executionResults,
                'skipped' => $skippedUsers,
            ];
            
        } catch (Exception $e) {
            error_log("Execute signal for all users error: " . $e->getMessage());
            
            // Mark signal as processed with error
            $this->markSignalAsProcessed($signalId, 0, 0, 0, 0);
            
            throw $e;
        }
    }
    
    /**
     * Get all active trading users
     */
    private function getActiveTradingUsers(): array
    {
        // First, get all potentially active users for diagnostics
        $diagnosticSql = "
            SELECT DISTINCT u.id, u.email, 
                   u.encrypted_api_token,
                   CASE 
                       WHEN u.encrypted_api_token IS NULL THEN 'NULL'
                       WHEN u.encrypted_api_token = '' THEN 'EMPTY'
                       ELSE 'HAS_TOKEN'
                   END as token_status,
                   s.is_bot_active,
                   ts.state,
                   ts.end_time
            FROM users u
            LEFT JOIN settings s ON u.id = s.user_id
            LEFT JOIN trading_sessions ts ON u.id = ts.user_id AND ts.end_time IS NULL
            WHERE u.is_active = 1
            ORDER BY u.id
        ";
        
        $allUsers = $this->db->query($diagnosticSql);
        error_log("[SignalService] Diagnostic: Found " . count($allUsers) . " active users");
        
        foreach ($allUsers as $user) {
            $status = $user['token_status'] ?? 'UNKNOWN';
            $botActive = $user['is_bot_active'] ?? 0;
            $sessionState = $user['state'] ?? 'NONE';
            error_log("[SignalService] User {$user['id']} ({$user['email']}): token={$status}, bot_active={$botActive}, session_state={$sessionState}");
        }
        
        // Now get the actual eligible users
        $sql = "
            SELECT DISTINCT u.id, u.email, u.encrypted_api_token
            FROM users u
            INNER JOIN settings s ON u.id = s.user_id
            INNER JOIN trading_sessions ts ON u.id = ts.user_id
            WHERE u.is_active = 1
            AND s.is_bot_active = 1
            AND u.encrypted_api_token IS NOT NULL
            AND u.encrypted_api_token != ''
            AND ts.state = 'active'
            AND ts.end_time IS NULL
            ORDER BY u.id
        ";
        
        $eligibleUsers = $this->db->query($sql);
        error_log("[SignalService] Found " . count($eligibleUsers) . " eligible users with valid tokens and active sessions");
        
        return $eligibleUsers;
    }
    
    /**
     * Write to dedicated trade execution log file
     */
    private function writeTradeLog(string $message, array $context = []): void
    {
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/trade_execution.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $logEntry = "[{$timestamp}] {$message}{$contextStr}" . PHP_EOL;
        
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        error_log($message . $contextStr);
    }
    
    /**
     * Execute signal for a specific user
     * 
     * @param int $userId User ID
     * @param string $signalType RISE or FALL
     * @param string|null $asset Optional asset symbol
     * @return array Execution result
     */
    private function executeSignalForUser(int $userId, string $signalType, ?string $asset = null): array
    {
        $logPrefix = "[SignalService::executeSignalForUser] user={$userId}";
        $this->writeTradeLog("{$logPrefix} START", [
            'signalType' => $signalType,
            'asset' => $asset ?? 'null',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        error_log("{$logPrefix} START - signalType={$signalType} asset=" . ($asset ?? 'null'));
        
        try {
            // Check if user has active trading session
            $this->writeTradeLog("{$logPrefix} Checking if trading is active");
            error_log("{$logPrefix} Checking if trading is active");
            
            $isActive = $this->tradingBot->isTradingActive($userId);
            $this->writeTradeLog("{$logPrefix} Trading active check result", ['is_active' => $isActive]);
            
            if (!$isActive) {
                $error = 'No active trading session';
                $this->writeTradeLog("{$logPrefix} ERROR", ['error' => $error, 'user_id' => $userId]);
                error_log("{$logPrefix} ERROR: {$error}");
                return [
                    'user_id' => $userId,
                    'success' => false,
                    'error' => $error,
                ];
            }
            
            $this->writeTradeLog("{$logPrefix} Trading is active - proceeding with trade execution");
            error_log("{$logPrefix} Trading is active");
            
            // Execute trade via TradingBotService
            $this->writeTradeLog("{$logPrefix} Calling TradingBotService::executeSignalTrade");
            error_log("{$logPrefix} Calling TradingBotService::executeSignalTrade");
            
            try {
                $startTime = microtime(true);
                $result = $this->tradingBot->executeSignalTrade($userId, $signalType, $asset);
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);
                
                $this->writeTradeLog("{$logPrefix} TradingBotService returned", [
                    'result_keys' => array_keys($result ?? []),
                    'has_trade_id' => isset($result['trade_id']),
                    'has_contract_id' => isset($result['contract_id']),
                    'execution_time_ms' => $executionTime,
                ]);
                
                if (empty($result)) {
                    $error = 'TradingBotService returned empty result';
                    $this->writeTradeLog("{$logPrefix} ERROR", ['error' => $error, 'result' => $result]);
                    error_log("{$logPrefix} ERROR: {$error}");
                    return [
                        'user_id' => $userId,
                        'success' => false,
                        'error' => $error,
                    ];
                }
                
                if (empty($result['trade_id'])) {
                    $error = 'Invalid trade result - missing trade_id';
                    $this->writeTradeLog("{$logPrefix} ERROR", [
                        'error' => $error,
                        'result' => $result,
                        'result_keys' => array_keys($result),
                    ]);
                    error_log("{$logPrefix} ERROR: {$error}");
                    error_log("{$logPrefix} Result: " . json_encode($result));
                    return [
                        'user_id' => $userId,
                        'success' => false,
                        'error' => $error,
                    ];
                }
                
                $this->writeTradeLog("{$logPrefix} SUCCESS", [
                    'trade_id' => $result['trade_id'],
                    'contract_id' => $result['contract_id'] ?? 'N/A',
                    'execution_time_ms' => $executionTime,
                ]);
                error_log("{$logPrefix} SUCCESS - Trade executed: trade_id={$result['trade_id']} contract_id={$result['contract_id']}");
                
                return [
                    'user_id' => $userId,
                    'success' => true,
                    'trade_id' => $result['trade_id'],
                    'contract_id' => $result['contract_id'],
                ];
                
            } catch (Exception $e) {
                $error = "TradingBotService execution failed: " . $e->getMessage();
                $this->writeTradeLog("{$logPrefix} EXCEPTION", [
                    'error' => $error,
                    'exception_type' => get_class($e),
                    'exception_message' => $e->getMessage(),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString()),
                ]);
                error_log("{$logPrefix} ERROR: {$error}");
                error_log("{$logPrefix} Exception type: " . get_class($e));
                error_log("{$logPrefix} Exception trace: " . $e->getTraceAsString());
                
                return [
                    'user_id' => $userId,
                    'success' => false,
                    'error' => $error,
                    'exception_type' => get_class($e),
                    'exception_file' => $e->getFile(),
                    'exception_line' => $e->getLine(),
                ];
            }
            
        } catch (Exception $e) {
            $error = "Unexpected error in executeSignalForUser: " . $e->getMessage();
            $this->writeTradeLog("{$logPrefix} FATAL EXCEPTION", [
                'error' => $error,
                'exception_type' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ]);
            error_log("{$logPrefix} FATAL ERROR: {$error}");
            error_log("{$logPrefix} Fatal exception trace: " . $e->getTraceAsString());
            
            return [
                'user_id' => $userId,
                'success' => false,
                'error' => $error,
                'exception_type' => get_class($e),
            ];
        }
    }
    
    /**
     * Mark signal as processed
     */
    private function markSignalAsProcessed(
        int $signalId,
        int $totalUsers,
        int $successfulExecutions,
        int $failedExecutions,
        int $executionTime
    ): void {
        try {
            $this->db->execute(
                "UPDATE signals 
                 SET processed = 1,
                     total_users = :total_users,
                     successful_executions = :successful,
                     failed_executions = :failed,
                     execution_time = :execution_time,
                     processed_at = NOW()
                 WHERE id = :id",
                [
                    'total_users' => $totalUsers,
                    'successful' => $successfulExecutions,
                    'failed' => $failedExecutions,
                    'execution_time' => $executionTime,
                    'id' => $signalId,
                ]
            );
        } catch (Exception $e) {
            error_log("Error marking signal as processed: " . $e->getMessage());
        }
    }
    
    /**
     * Process unprocessed signals (for queue processing)
     */
    public function processUnprocessedSignals(int $limit = 10): array
    {
        try {
            $unprocessedSignals = $this->helper->getUnprocessedSignals($limit);
            
            $processed = [];
            
            foreach ($unprocessedSignals as $signal) {
                try {
                    // Check signal age
                    $signalAge = time() - strtotime($signal['timestamp']);
                    if ($signalAge > self::MAX_SIGNAL_AGE_SECONDS) {
                        error_log("Signal {$signal['id']} is too old, skipping");
                        // Mark as processed to avoid reprocessing
                        $this->markSignalAsProcessed(
                            (int)$signal['id'],
                            0,
                            0,
                            0,
                            0
                        );
                        continue;
                    }
                    
                    // Execute signal
                    $result = $this->executeSignalForAllUsers(
                        (int)$signal['id'],
                        $signal['signal_type'],
                        $signal['asset']
                    );
                    
                    $processed[] = [
                        'signal_id' => $signal['id'],
                        'result' => $result,
                    ];
                    
                } catch (Exception $e) {
                    error_log("Error processing signal {$signal['id']}: " . $e->getMessage());
                }
            }
            
            return [
                'processed' => count($processed),
                'results' => $processed,
            ];
            
        } catch (Exception $e) {
            error_log("Process unprocessed signals error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Retry failed signal executions
     */
    public function retryFailedExecutions(int $signalId, int $maxRetries = self::MAX_RETRY_ATTEMPTS): array
    {
        try {
            $signal = $this->db->queryOne(
                "SELECT * FROM signals WHERE id = :id",
                ['id' => $signalId]
            );
            
            if (!$signal) {
                throw new Exception('Signal not found');
            }
            
            if ($signal['processed'] == 1) {
                throw new Exception('Signal already processed');
            }
            
            // Get users who failed
            // Note: We'll need to track individual user failures in a separate table
            // For now, we'll retry the entire signal
            
            $result = $this->executeSignalForAllUsers(
                $signalId,
                $signal['signal_type'],
                $signal['asset']
            );
            
            return [
                'success' => true,
                'signal_id' => $signalId,
                'retry_result' => $result,
            ];
            
        } catch (Exception $e) {
            error_log("Retry failed executions error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get signal execution history
     */
    public function getSignalHistory(int $limit = 50, int $offset = 0): array
    {
        try {
            $signals = $this->db->query(
                "SELECT * FROM signals 
                 ORDER BY timestamp DESC 
                 LIMIT :limit OFFSET :offset",
                [
                    'limit' => $limit,
                    'offset' => $offset,
                ]
            );
            
            // Format signals
            $formatted = array_map(function($signal) {
                return [
                    'id' => (int)$signal['id'],
                    'signal_type' => $signal['signal_type'],
                    'asset' => $signal['asset'],
                    'raw_text' => $signal['raw_text'],
                    'source' => $signal['source'],
                    'processed' => (bool)$signal['processed'],
                    'total_users' => (int)$signal['total_users'],
                    'successful_executions' => (int)$signal['successful_executions'],
                    'failed_executions' => (int)$signal['failed_executions'],
                    'execution_time' => (int)$signal['execution_time'],
                    'timestamp' => $signal['timestamp'],
                    'processed_at' => $signal['processed_at'],
                ];
            }, $signals);
            
            return $formatted;
            
        } catch (Exception $e) {
            error_log("Get signal history error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get signal statistics
     */
    public function getSignalStatistics(?string $dateFrom = null, ?string $dateTo = null): array
    {
        try {
            $whereClause = "WHERE 1=1";
            $params = [];
            
            if ($dateFrom) {
                $whereClause .= " AND timestamp >= :date_from";
                $params['date_from'] = $dateFrom;
            }
            
            if ($dateTo) {
                $whereClause .= " AND timestamp <= :date_to";
                $params['date_to'] = $dateTo;
            }
            
            // Total signals
            $totalSignals = $this->db->queryValue(
                "SELECT COUNT(*) FROM signals {$whereClause}",
                $params
            );
            
            // Processed signals
            $processedSignals = $this->db->queryValue(
                "SELECT COUNT(*) FROM signals {$whereClause} AND processed = 1",
                $params
            );
            
            // Total executions
            $totalExecutions = $this->db->queryValue(
                "SELECT SUM(total_users) FROM signals {$whereClause} AND processed = 1",
                $params
            ) ?? 0;
            
            // Successful executions
            $successfulExecutions = $this->db->queryValue(
                "SELECT SUM(successful_executions) FROM signals {$whereClause} AND processed = 1",
                $params
            ) ?? 0;
            
            // Failed executions
            $failedExecutions = $this->db->queryValue(
                "SELECT SUM(failed_executions) FROM signals {$whereClause} AND processed = 1",
                $params
            ) ?? 0;
            
            // Average execution time
            $avgExecutionTime = $this->db->queryValue(
                "SELECT AVG(execution_time) FROM signals {$whereClause} AND processed = 1 AND execution_time > 0",
                $params
            ) ?? 0;
            
            // Signals by type
            $signalsByType = $this->db->query(
                "SELECT signal_type, COUNT(*) as count 
                 FROM signals {$whereClause}
                 GROUP BY signal_type",
                $params
            );
            
            $typeStats = [];
            foreach ($signalsByType as $stat) {
                $typeStats[$stat['signal_type']] = (int)$stat['count'];
            }
            
            return [
                'total_signals' => (int)$totalSignals,
                'processed_signals' => (int)$processedSignals,
                'pending_signals' => (int)$totalSignals - (int)$processedSignals,
                'total_executions' => (int)$totalExecutions,
                'successful_executions' => (int)$successfulExecutions,
                'failed_executions' => (int)$failedExecutions,
                'success_rate' => $totalExecutions > 0 
                    ? round(($successfulExecutions / $totalExecutions) * 100, 2) 
                    : 0,
                'avg_execution_time_ms' => round((float)$avgExecutionTime, 2),
                'signals_by_type' => $typeStats,
            ];
            
        } catch (Exception $e) {
            error_log("Get signal statistics error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get signal by ID
     */
    public function getSignalById(int $signalId): ?array
    {
        try {
            $signal = $this->db->queryOne(
                "SELECT * FROM signals WHERE id = :id",
                ['id' => $signalId]
            );
            
            if (!$signal) {
                return null;
            }
            
            return [
                'id' => (int)$signal['id'],
                'signal_type' => $signal['signal_type'],
                'asset' => $signal['asset'],
                'raw_text' => $signal['raw_text'],
                'source' => $signal['source'],
                'source_ip' => $signal['source_ip'],
                'processed' => (bool)$signal['processed'],
                'total_users' => (int)$signal['total_users'],
                'successful_executions' => (int)$signal['successful_executions'],
                'failed_executions' => (int)$signal['failed_executions'],
                'execution_time' => (int)$signal['execution_time'],
                'timestamp' => $signal['timestamp'],
                'processed_at' => $signal['processed_at'],
            ];
            
        } catch (Exception $e) {
            error_log("Get signal by ID error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Cleanup old processed signals (older than 30 days)
     */
    public function cleanupOldSignals(int $daysOld = 30): int
    {
        try {
            $deleted = $this->db->execute(
                "DELETE FROM signals 
                 WHERE processed = 1 
                 AND timestamp < DATE_SUB(NOW(), INTERVAL :days DAY)",
                ['days' => $daysOld]
            );
            
            error_log("Cleaned up {$deleted} old signals (older than {$daysOld} days)");
            
            return $deleted;
            
        } catch (Exception $e) {
            error_log("Cleanup old signals error: " . $e->getMessage());
            return 0;
        }
    }
}

