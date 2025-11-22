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
                throw new Exception($validation['error']);
            }
            
            // Extract signal information
            $signalType = strtoupper($signalData['type'] ?? $signalData['signalType'] ?? '');
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
            
            // Process signal immediately
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
            throw $e;
        }
    }
    
    /**
     * Validate signal data
     */
    private function validateSignal(array $signalData): array
    {
        // Check required fields
        $type = strtoupper($signalData['type'] ?? $signalData['signalType'] ?? '');
        
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
            
            // Process users in batches
            $batches = array_chunk($activeUsers, self::BATCH_SIZE);
            
            foreach ($batches as $batch) {
                foreach ($batch as $user) {
                    try {
                        $result = $this->executeSignalForUser(
                            (int)$user['id'],
                            $signalType,
                            $asset
                        );
                        
                        $executionResults[] = $result;
                        
                        if ($result['success']) {
                            $successfulExecutions++;
                        } else {
                            $failedExecutions++;
                        }
                        
                    } catch (Exception $e) {
                        error_log("Signal execution error for user {$user['id']}: " . $e->getMessage());
                        $executionResults[] = [
                            'user_id' => (int)$user['id'],
                            'success' => false,
                            'error' => $e->getMessage(),
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
            
            error_log("Signal executed: {$signalType}" . ($asset ? " on {$asset}" : "") . " - Users: {$totalUsers}, Successful: {$successfulExecutions}, Failed: {$failedExecutions}");
            
            return [
                'total_users' => $totalUsers,
                'successful' => $successfulExecutions,
                'failed' => $failedExecutions,
                'execution_time' => $executionTime,
                'results' => $executionResults,
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
        
        return $this->db->query($sql);
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
        try {
            // Check if user has active trading session
            if (!$this->tradingBot->isTradingActive($userId)) {
                return [
                    'user_id' => $userId,
                    'success' => false,
                    'error' => 'No active trading session',
                ];
            }
            
            // Execute trade via TradingBotService
            $result = $this->tradingBot->executeSignalTrade($userId, $signalType, $asset);
            
            return [
                'user_id' => $userId,
                'success' => true,
                'trade_id' => $result['trade_id'],
                'contract_id' => $result['contract_id'],
            ];
            
        } catch (Exception $e) {
            error_log("Signal execution error for user {$userId}: " . $e->getMessage());
            
            return [
                'user_id' => $userId,
                'success' => false,
                'error' => $e->getMessage(),
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

