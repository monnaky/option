<?php

/**
 * Trading Bot Service
 * 
 * Core automated trading logic for VTM Option
 * Handles multi-user trading, risk management, and trade execution
 */

namespace App\Services;

use App\Config\Database;
use App\Utils\DatabaseHelper;
use App\Services\DerivAPI;
use App\Services\EncryptionService;
use Exception;

class TradingBotService
{
    private static ?TradingBotService $instance = null;
    private Database $db;
    private DatabaseHelper $helper;
    
    // Connection pool for DerivAPI instances (per user)
    private array $apiConnections = [];
    
    // Balance cache (userId => ['balance' => float, 'timestamp' => int, 'ttl' => int])
    private array $balanceCache = [];
    
    // Configuration constants
    private const MAX_ERROR_COUNT = 5;
    private const MAX_INACTIVE_TIME = 30 * 60; // 30 minutes in seconds
    private const MAX_ACTIVE_CONTRACTS = 50;
    private const CONTRACT_MONITOR_TIMEOUT = 30; // 30 seconds
    private const FALLBACK_CONTRACT_RESULT_DELAY = 7; // seconds to wait before inline result check
    private const API_REQUEST_TIMEOUT = 10; // 10 seconds
    private const SETTINGS_SYNC_INTERVAL = 60; // 1 minute
    private const HEALTH_CHECK_INTERVAL = 30; // 30 seconds
    private const MIN_TRADE_INTERVAL = 30; // Minimum 30 seconds between trades
    private const MAX_TRADE_INTERVAL = 120; // Maximum 120 seconds between trades
    
    // Balance cache TTL (5 seconds - optimized for fast updates)
    private const BALANCE_CACHE_TTL = 5;
    
    // Connection pool TTL (5 minutes - connections stay alive for reuse)
    private const CONNECTION_POOL_TTL = 300;
    private const DEFAULT_ASSETS = ['R_10', 'R_25', 'R_50', 'R_75', 'R_100'];
    
    // Retry configuration (optimized for speed)
    private const MAX_BALANCE_RETRIES = 2; // Reduced from 3
    private const RETRY_DELAY_BASE = 0.5; // Reduced from 1 second to 0.5 seconds
    
    // Session states
    private const STATE_INITIALIZING = 'initializing';
    private const STATE_ACTIVE = 'active';
    private const STATE_STOPPING = 'stopping';
    private const STATE_STOPPED = 'stopped';
    private const STATE_ERROR = 'error';
    private const STATE_RECOVERING = 'recovering';
    
    /**
     * Private constructor (singleton pattern)
     */
    private function __construct()
    {
        $this->db = Database::getInstance();
        $this->helper = new DatabaseHelper();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): TradingBotService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Start trading for a user
     */
    public function startTrading(int $userId): array
    {
        try {
            // Check if already trading
        $activeSession = $this->helper->getActiveTradingSession($userId);
        if ($activeSession) {
            // Check if session is stuck in INITIALIZING state (older than 5 seconds)
            if ($activeSession['state'] === self::STATE_INITIALIZING) {
                $startTime = strtotime($activeSession['start_time']);
                if (time() - $startTime > 5) {
                    // Mark as error and continue
                    $this->helper->updateTradingSessionState(
                        $activeSession['id'], 
                        self::STATE_ERROR, 
                        'system', 
                        'Session stuck in initialization'
                    );
                    error_log("Cleaned up stuck session {$activeSession['id']} for user {$userId}");
                } else {
                    throw new Exception('Trading is currently starting. Please wait...');
                }
            } elseif ($activeSession['state'] === self::STATE_ACTIVE) {
                throw new Exception('Trading already active for this user');
            }
        }
            
            // Get user settings
            $settings = $this->helper->getUserSettings($userId);
            if (!$settings) {
                throw new Exception('User settings not found');
            }
            
            // Check if user has API token
            $user = $this->db->queryOne(
                "SELECT encrypted_api_token FROM users WHERE id = :id",
                ['id' => $userId]
            );
            
            if (!$user || empty($user['encrypted_api_token'])) {
                throw new Exception('API token not found. Please connect your Deriv API token first.');
            }
            
            // Decrypt API token (supports legacy key fallback)
            try {
                $apiToken = $this->decryptUserApiToken($userId, $user['encrypted_api_token']);
            } catch (Exception $e) {
                throw new Exception('Failed to decrypt API token: ' . $e->getMessage());
            }
            
            // Generate unique session ID
            $sessionId = 'session_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4));
            
            // Hash API token for auditing
            $apiTokenHash = hash('sha256', $apiToken);
            
            // Create database session record
            $dbSessionId = $this->helper->createTradingSession([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'state' => self::STATE_INITIALIZING,
                'stake' => $settings['stake'],
                'target' => $settings['target'],
                'stop_limit' => $settings['stop_limit'],
                'max_active_contracts' => self::MAX_ACTIVE_CONTRACTS,
                'max_daily_trades' => 0, // Unlimited
                'started_by' => 'user',
            ]);
            
            // Initialize Deriv API
            $derivApi = new DerivAPI($apiToken, null, (string)$userId);
            
            // Verify connection (lightweight check only)
            try {
                // Just authorize, don't fetch balance (too slow)
                $derivApi->authorize();
            } catch (Exception $e) {
                $derivApi->close();
                $this->helper->updateTradingSessionState($dbSessionId, self::STATE_ERROR, 'user', 'Connection failed');
                throw new Exception('Failed to connect to Deriv API: ' . $e->getMessage());
            }
            
            // Update session to ACTIVE
            $this->helper->updateTradingSessionState($dbSessionId, self::STATE_ACTIVE);
            
            // Update settings to mark bot as active
            $this->helper->updateUserSettings($userId, ['is_bot_active' => true]);
            
            // Store session info in database for cron job to pick up
            // We'll use a flag in the database to indicate active trading
            $this->db->execute(
                "UPDATE settings SET is_bot_active = 1, last_active_at = NOW() WHERE user_id = :user_id",
                ['user_id' => $userId]
            );
            
            // Close API connection (will be reopened by cron job)
            $derivApi->close();
            
            return [
                'success' => true,
                'session_id' => $sessionId,
                'message' => 'Trading bot started successfully',
            ];
            
        } catch (Exception $e) {
            error_log("Start trading error for user {$userId}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Stop trading for a user
     */
    public function stopTrading(
        int $userId,
        string $stoppedBy = 'user',
        string $stopReason = 'User stopped',
        bool $disableBotFlag = true
    ): array
    {
        try {
            // Get active session
            $activeSession = $this->helper->getActiveTradingSession($userId);
            
            if ($activeSession) {
                // Update session state to STOPPED
                $this->helper->updateTradingSessionState(
                    $activeSession['id'],
                    self::STATE_STOPPED,
                    $stoppedBy,
                    $stopReason
                );
                
                // Update end time
                $this->db->execute(
                    "UPDATE trading_sessions SET end_time = NOW() WHERE id = :id",
                    ['id' => $activeSession['id']]
                );
            }

            // Only disable the bot flag for explicit/manual stops or hard-stop conditions.
            // For system recovery scenarios, keep is_bot_active = 1 so cron can auto-restart.
            if ($disableBotFlag) {
                // Update settings to mark bot as inactive
                $this->helper->updateUserSettings($userId, ['is_bot_active' => false]);
                
                // Update database
                $this->db->execute(
                    "UPDATE settings SET is_bot_active = 0 WHERE user_id = :user_id",
                    ['user_id' => $userId]
                );
            }
            
            return [
                'success' => true,
                'message' => 'Trading bot stopped successfully',
            ];
            
        } catch (Exception $e) {
            error_log("Stop trading error for user {$userId}: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Process trading loop for all active users
     * This should be called by a cron job every minute
     */
    public function processTradingLoop(): void
    {
        try {
            // Get all users with active trading
            $activeUsers = $this->db->query(
                "SELECT DISTINCT s.user_id 
                 FROM settings s
                 INNER JOIN users u ON s.user_id = u.id
                 WHERE s.is_bot_active = 1 
                 AND u.is_active = 1
                 AND u.encrypted_api_token IS NOT NULL
                 AND u.encrypted_api_token != ''"
            );
            
            foreach ($activeUsers as $userData) {
                $userId = (int)$userData['user_id'];
                
                try {
                    $this->processUserTrading($userId);
                } catch (Exception $e) {
                    error_log("Trading loop error for user {$userId}: " . $e->getMessage());
                    // Continue with other users
                }
            }
            
        } catch (Exception $e) {
            error_log("Trading loop error: " . $e->getMessage());
        }
    }
    
    /**
     * Process trading for a single user
     */
    private function processUserTrading(int $userId): void
    {
        // Get active session
        $activeSession = $this->helper->getActiveTradingSession($userId);
        
        if (!$activeSession) {
            // Create new session if settings indicate bot should be active
            $settings = $this->helper->getUserSettings($userId);
            if ($settings && $settings['is_bot_active']) {
                $this->startTrading($userId);
                $activeSession = $this->helper->getActiveTradingSession($userId);
            } else {
                return; // Bot not active
            }
        }
        
        if (!$activeSession || $activeSession['state'] !== self::STATE_ACTIVE) {
            return; // Session not active
        }
        
        // Check if it's time for next trade
        $lastActivity = strtotime($activeSession['last_activity_time'] ?? 'now');
        $timeSinceLastActivity = time() - $lastActivity;
        
        // Random interval between trades (30-120 seconds)
        $nextTradeInterval = rand(self::MIN_TRADE_INTERVAL, self::MAX_TRADE_INTERVAL);
        
        if ($timeSinceLastActivity < $nextTradeInterval) {
            return; // Not time for next trade yet
        }
        
        // Sync settings from database
        $settings = $this->helper->getUserSettings($userId);
        if (!$settings) {
            $this->stopTrading($userId, 'system', 'Settings not found', true);
            return;
        }
        
        // Check daily limits
        if ($this->checkDailyLimits($userId, $settings)) {
            return; // Limits reached, trading stopped
        }
        
        // Check active contracts count
        $activeContractsCount = $this->getActiveContractsCount($userId);
        if ($activeContractsCount >= self::MAX_ACTIVE_CONTRACTS) {
            error_log("Too many active contracts for user {$userId}. Waiting...");
            return; // Too many active contracts
        }
        
        // Execute trade
        try {
            $this->executeTrade($userId, $activeSession, $settings);
        } catch (Exception $e) {
            error_log("Trade execution error for user {$userId}: " . $e->getMessage());
            $this->handleTradingError($userId, $e);
        }
    }
    
    /**
     * Execute a trade
     */
    private function executeTrade(int $userId, array $session, array $settings): void
    {
        // Get user API token
        $user = $this->db->queryOne(
            "SELECT encrypted_api_token FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$user || empty($user['encrypted_api_token'])) {
            throw new Exception('API token not found');
        }
        
        // Decrypt token
        $apiToken = $this->decryptUserApiToken($userId, $user['encrypted_api_token']);
        
        // Create Deriv API instance
        $derivApi = new DerivAPI($apiToken, null, (string)$userId);
        $encryptedToken = $user['encrypted_api_token'];
        
        try {
            // OPTIMIZATION: Use predefined assets instead of API call
            // getAvailableAssets() takes 120+ seconds and kills the WebSocket connection
            // Use common volatile indices that are always available
            $asset = $this->getDefaultAsset();
            
            // Determine direction (RISE or FALL) - Random decision
            $direction = (rand(0, 1) === 1) ? 'CALL' : 'PUT';
            $directionLabel = $direction === 'CALL' ? 'RISE' : 'FALL';
            
            // Now log with all variables defined
            error_log("[TradingBot] executeTrade user={$userId} session={$session['id']} direction={$directionLabel} asset={$asset} stake={$settings['stake']}");
            
            // Duration: 5 ticks for faster results
            $duration = (int)($settings['trade_duration'] ?? 5);
            $durationUnit = strtolower((string)($settings['trade_duration_unit'] ?? 't'));

            // Validate duration configuration
            if (!in_array($durationUnit, ['t', 's'], true)) {
                $durationUnit = 't';
            }

            if ($duration < 1) {
                $duration = 1;
            }

            // Clamp to reasonable bounds to prevent accidental extreme contracts
            if ($durationUnit === 't') {
                $duration = min(10, $duration);
            } else {
                $duration = min(300, $duration);
            }
            
            // Place trade (proposal + buy handled by DerivAPI)
            $contract = $derivApi->buyContract(
                $asset,
                $direction,
                (float)$settings['stake'],
                $duration,
                $durationUnit
            );
            
            // Generate unique trade ID
            $tradeId = 'TRADE_' . time() . '_' . $contract['contract_id'];
            
            // Create trade record
            $tradeRecordId = $this->helper->createTrade([
                'user_id' => $userId,
                'session_id' => $session['id'],
                'trade_id' => $tradeId,
                'contract_id' => (string)$contract['contract_id'],
                'asset' => $asset,
                'direction' => $directionLabel,
                'stake' => (float)$settings['stake'],
                'payout' => $contract['buy_price'],
                'profit' => 0,
                'status' => 'pending',
            ]);
            
            // Update session statistics
            $this->db->execute(
                "UPDATE trading_sessions 
                 SET total_trades = total_trades + 1,
                     daily_trade_count = daily_trade_count + 1,
                     last_activity_time = NOW()
                 WHERE id = :id",
                ['id' => $session['id']]
            );
            
            // Verify contract was actually created
            $this->verifyContractCreation($userId, (string)$contract['contract_id'], $tradeRecordId);
            
            // Schedule contract monitoring (via cron) and inline fallback
            $this->scheduleContractMonitoring($userId, (string)$contract['contract_id'], $tradeRecordId);
            $this->fallbackProcessContractResult($userId, (string)$contract['contract_id'], $tradeRecordId, $user['encrypted_api_token']);
            $this->fallbackProcessContractResult($userId, (string)$contract['contract_id'], $tradeRecordId, $encryptedToken);
            
            error_log("[TradingBot] Trade placed user={$userId} contract={$contract['contract_id']} asset={$asset} dir={$directionLabel} stake={$settings['stake']}");
            
        } finally {
            // Close API connection
            $derivApi->close();
        }
    }

    /**
     * Decrypt a stored API token with legacy key fallback and rotation
     */
    private function decryptUserApiToken(int $userId, string $encryptedToken): string
    {
        [$token, $usedLegacyKey] = EncryptionService::decryptWithLegacySupport($encryptedToken);
        
        if ($usedLegacyKey) {
            try {
                $reEncrypted = EncryptionService::encrypt($token);
                $this->helper->rotateUserApiToken($userId, $reEncrypted);
                error_log("[TradingBotService] Rotated API token encryption for user {$userId} after legacy key fallback");
            } catch (Exception $rotateError) {
                error_log("[TradingBotService] Failed to rotate API token for user {$userId}: " . $rotateError->getMessage());
            }
        }
        
        return $token;
    }
    
    /**
     * Execute a trade with a specific signal (for signal service)
     */
    public function executeSignalTrade(int $userId, string $direction, ?string $asset = null): array
    {
        $logPrefix = "[TradingBot::executeSignalTrade] user={$userId}";
        error_log("{$logPrefix} START - direction={$direction} asset=" . ($asset ?? 'auto'));
        
        try {
            // Step 1: Get active session
            error_log("{$logPrefix} Step 1: Checking active trading session");
            $activeSession = $this->helper->getActiveTradingSession($userId);
            
            if (!$activeSession) {
                $error = "No trading session found for user {$userId}";
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            if ($activeSession['state'] !== self::STATE_ACTIVE) {
                $error = "Trading session not active (state: {$activeSession['state']})";
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            error_log("{$logPrefix} Step 1: OK - Session ID: {$activeSession['id']}, State: {$activeSession['state']}");
            
            // Step 2: Get settings
            error_log("{$logPrefix} Step 2: Loading user settings");
            $settings = $this->helper->getUserSettings($userId);
            
            if (!$settings) {
                $error = "User settings not found for user {$userId}";
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            if (empty($settings['stake']) || (float)$settings['stake'] <= 0) {
                $error = "Invalid stake amount: " . ($settings['stake'] ?? 'null');
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            error_log("{$logPrefix} Step 2: OK - Stake: {$settings['stake']}");
            
            // Step 3: Get user API token
            error_log("{$logPrefix} Step 3: Retrieving encrypted API token");
            $user = $this->db->queryOne(
                "SELECT encrypted_api_token FROM users WHERE id = :id",
                ['id' => $userId]
            );
            
            if (!$user) {
                $error = "User not found in database: {$userId}";
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            if (empty($user['encrypted_api_token'])) {
                $error = "Encrypted API token is empty for user {$userId}";
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            $encryptedTokenLength = strlen($user['encrypted_api_token']);
            error_log("{$logPrefix} Step 3: OK - Encrypted token length: {$encryptedTokenLength}");
            
            // Step 4: Validate encrypted token format
            error_log("{$logPrefix} Step 4: Validating encrypted token format");
            if (!EncryptionService::isValidFormat($user['encrypted_api_token'])) {
                $error = "Invalid encrypted token format for user {$userId}";
                error_log("{$logPrefix} ERROR: {$error}");
                error_log("{$logPrefix} Token preview: " . substr($user['encrypted_api_token'], 0, 50) . '...');
                throw new Exception($error);
            }
            error_log("{$logPrefix} Step 4: OK - Token format valid");
            
            // Step 5: Decrypt token
            error_log("{$logPrefix} Step 5: Decrypting API token");
            try {
                $apiToken = $this->decryptUserApiToken($userId, $user['encrypted_api_token']);
            } catch (Exception $e) {
                $error = "Token decryption failed for user {$userId}: " . $e->getMessage();
                error_log("{$logPrefix} ERROR: {$error}");
                error_log("{$logPrefix} Decryption exception: " . $e->getTraceAsString());
                throw new Exception($error, 0, $e);
            }
            
            if (empty($apiToken)) {
                $error = "Decrypted token is empty for user {$userId}";
                error_log("{$logPrefix} ERROR: {$error}");
                throw new Exception($error);
            }
            
            $decryptedTokenLength = strlen($apiToken);
            error_log("{$logPrefix} Step 5: OK - Decrypted token length: {$decryptedTokenLength}");
            error_log("{$logPrefix} Token preview: " . substr($apiToken, 0, 20) . '...' . substr($apiToken, -10));
            
            // Step 6: Create Deriv API instance
            error_log("{$logPrefix} Step 6: Creating DerivAPI instance");
            try {
                $derivApi = new DerivAPI($apiToken, null, (string)$userId);
                error_log("{$logPrefix} Step 6: OK - DerivAPI instance created");
            } catch (Exception $e) {
                $error = "Failed to create DerivAPI instance: " . $e->getMessage();
                error_log("{$logPrefix} ERROR: {$error}");
                error_log("{$logPrefix} DerivAPI exception: " . $e->getTraceAsString());
                throw new Exception($error, 0, $e);
            }
            
            try {
                // Step 7: Get asset if not specified or invalid
                $asset = $this->normalizeAsset($asset);
                if ($asset === null) {
                    error_log("{$logPrefix} Step 7: Using predefined assets (API call too slow)");
                    // OPTIMIZATION: Use predefined assets instead of API call
                    // getAvailableAssets() takes 120+ seconds and kills the WebSocket connection
                    $asset = $this->getDefaultAsset();
                    error_log("{$logPrefix} Step 7: OK - Selected default asset: {$asset}");
                } else {
                    error_log("{$logPrefix} Step 7: SKIP - Asset specified: {$asset}");
                }
                
                // Step 8: Convert direction to contract type
                $contractType = strtoupper($direction) === 'RISE' ? 'CALL' : 'PUT';
                error_log("{$logPrefix} Step 8: Contract type: {$contractType} (from direction: {$direction})");
                
                // Step 9: Place trade
                $duration = 5;
                error_log("{$logPrefix} Step 9: Placing trade - asset={$asset} type={$contractType} stake={$settings['stake']} duration={$duration}");
                
                try {
                    $contract = $derivApi->buyContract(
                        $asset,
                        $contractType,
                        (float)$settings['stake'],
                        $duration,
                        't'
                    );
                    
                    if (empty($contract) || empty($contract['contract_id'])) {
                        $error = "Invalid contract response - missing contract_id";
                        error_log("{$logPrefix} ERROR: {$error}");
                        error_log("{$logPrefix} Contract response: " . json_encode($contract));
                        throw new Exception($error);
                    }
                    
                    error_log("{$logPrefix} Step 9: OK - Contract placed: ID={$contract['contract_id']} buy_price={$contract['buy_price']}");
                } catch (Exception $e) {
                    $error = "Failed to place trade: " . $e->getMessage();
                    error_log("{$logPrefix} ERROR: {$error}");
                    error_log("{$logPrefix} buyContract exception: " . $e->getTraceAsString());
                    throw new Exception($error, 0, $e);
                }
                
                // Step 10: Create trade record
                error_log("{$logPrefix} Step 10: Creating trade record in database");
                $tradeId = 'TRADE_' . time() . '_' . $contract['contract_id'];
                
                $tradeData = [
                    'user_id' => $userId,
                    'session_id' => $activeSession['id'],
                    'trade_id' => $tradeId,
                    'contract_id' => (string)$contract['contract_id'],
                    'asset' => $asset,
                    'direction' => $direction,
                    'stake' => (float)$settings['stake'],
                    'payout' => $contract['buy_price'],
                    'profit' => 0,
                    'status' => 'pending',
                ];
                
                error_log("{$logPrefix} Trade data: " . json_encode($tradeData));
                
                try {
                    $tradeRecordId = $this->helper->createTrade($tradeData);
                    
                    if (empty($tradeRecordId) || $tradeRecordId <= 0) {
                        $error = "Trade record creation returned invalid ID: {$tradeRecordId}";
                        error_log("{$logPrefix} ERROR: {$error}");
                        throw new Exception($error);
                    }
                    
                    error_log("{$logPrefix} Step 10: OK - Trade record ID: {$tradeRecordId}");
                    
                    // Verify trade was actually inserted
                    $verifyTrade = $this->db->queryOne(
                        "SELECT id, trade_id, contract_id, status FROM trades WHERE id = :id",
                        ['id' => $tradeRecordId]
                    );
                    
                    if (!$verifyTrade) {
                        $error = "Trade record verification failed - trade not found in database";
                        error_log("{$logPrefix} ERROR: {$error}");
                        error_log("{$logPrefix} Attempted to insert trade_id: {$tradeId}");
                        throw new Exception($error);
                    }
                    
                    error_log("{$logPrefix} Trade verification OK - DB ID: {$verifyTrade['id']}, trade_id: {$verifyTrade['trade_id']}, status: {$verifyTrade['status']}");
                    
                } catch (Exception $e) {
                    $error = "Failed to create trade record: " . $e->getMessage();
                    error_log("{$logPrefix} ERROR: {$error}");
                    error_log("{$logPrefix} createTrade exception: " . $e->getTraceAsString());
                    error_log("{$logPrefix} Trade data that failed: " . json_encode($tradeData));
                    throw new Exception($error, 0, $e);
                }
                
                // Step 11: Update session statistics
                error_log("{$logPrefix} Step 11: Updating session statistics");
                try {
                    $this->db->execute(
                        "UPDATE trading_sessions 
                         SET total_trades = total_trades + 1,
                             daily_trade_count = daily_trade_count + 1,
                             last_activity_time = NOW()
                         WHERE id = :id",
                        ['id' => $activeSession['id']]
                    );
                    error_log("{$logPrefix} Step 11: OK - Session statistics updated");
                } catch (Exception $e) {
                    error_log("{$logPrefix} WARN: Failed to update session stats: " . $e->getMessage());
                    // Don't fail the trade if stats update fails
                }
                
                // Step 12: Schedule contract monitoring
                error_log("{$logPrefix} Step 12: Scheduling contract monitoring");
                try {
                    $this->scheduleContractMonitoring($userId, (string)$contract['contract_id'], $tradeRecordId);
                    error_log("{$logPrefix} Step 12: OK - Contract monitoring scheduled");
                } catch (Exception $e) {
                    error_log("{$logPrefix} WARN: Failed to schedule monitoring: " . $e->getMessage());
                    // Don't fail the trade if monitoring scheduling fails
                }
                
                error_log("{$logPrefix} SUCCESS - Trade executed: contract={$contract['contract_id']} asset={$asset} dir={$direction}");
                
                return [
                    'trade_id' => $tradeId,
                    'contract_id' => $contract['contract_id'],
                ];
                
            } catch (Exception $e) {
                error_log("{$logPrefix} EXCEPTION in trade execution: " . $e->getMessage());
                error_log("{$logPrefix} Exception trace: " . $e->getTraceAsString());
                throw $e;
            } finally {
                try {
                    if (isset($derivApi)) {
                        $derivApi->close();
                        error_log("{$logPrefix} DerivAPI connection closed");
                    }
                } catch (Exception $e) {
                    error_log("{$logPrefix} WARN: Error closing DerivAPI: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            error_log("{$logPrefix} FATAL ERROR: " . $e->getMessage());
            error_log("{$logPrefix} Fatal exception trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Check daily profit/loss limits
     */
    private function checkDailyLimits(int $userId, array $settings): bool
    {
        try {
            // Reset daily stats if needed
            $resetDate = strtotime($settings['reset_date'] ?? 'tomorrow');
            if (time() >= $resetDate) {
                $this->helper->resetUserDailyStats($userId);
                $settings = $this->helper->getUserSettings($userId);
            }
            
            // Check if target reached
            if ((float)$settings['daily_profit'] >= (float)$settings['target']) {
                $this->stopTrading($userId, 'system', 'Daily profit target reached', true);
                error_log("Daily profit target reached for user {$userId}. Stopping trading.");
                return true;
            }
            
            // Check if stop limit reached
            if (abs((float)$settings['daily_loss']) >= (float)$settings['stop_limit']) {
                $this->stopTrading($userId, 'system', 'Daily loss limit reached', true);
                error_log("Daily loss limit reached for user {$userId}. Stopping trading.");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Error checking daily limits for user {$userId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get count of active contracts for a user
     */
    private function getActiveContractsCount(int $userId): int
    {
        $result = $this->db->queryValue(
            "SELECT COUNT(*) FROM trades 
             WHERE user_id = :user_id 
             AND status = 'pending' 
             AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            ['user_id' => $userId]
        );
        
        return (int)($result ?? 0);
    }
    
    /**
     * Schedule contract monitoring
     * In a cron-based system, we'll check contracts periodically
     */
    private function verifyContractCreation(int $userId, string $contractId, int $tradeRecordId): void
    {
        $maxRetries = 3;
        $retryDelay = 1; // seconds
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Check if contract exists in database
                $contract = $this->db->queryOne(
                    "SELECT status FROM trades WHERE id = :id AND contract_id = :contract_id",
                    ['id' => $tradeRecordId, 'contract_id' => $contractId]
                );
                
                if ($contract && $contract['status'] !== 'pending') {
                    return; // Contract is confirmed
                }
                
                if ($attempt < $maxRetries) {
                    sleep($retryDelay);
                    $retryDelay *= 2; // Exponential backoff
                }
            } catch (Exception $e) {
                error_log("Error verifying contract {$contractId}: " . $e->getMessage());
                if ($attempt === $maxRetries) throw $e;
            }
        }
        
        throw new Exception("Failed to verify contract creation after {$maxRetries} attempts");
    }
    
    private function scheduleContractMonitoring(int $userId, string $contractId, int $tradeRecordId): void
    {
        // Store contract info for monitoring
        $this->db->execute(
            "INSERT INTO contract_monitor (user_id, contract_id, trade_id, status, created_at, updated_at)
             VALUES (:user_id, :contract_id, :trade_id, 'pending', NOW(), NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()",
            [
                'user_id' => $userId,
                'contract_id' => $contractId,
                'trade_id' => $tradeRecordId
            ]
        );
        
        error_log("Contract monitoring scheduled for user {$userId}, contract {$contractId}, trade {$tradeRecordId}");
    }
    
    /**
     * Process contract results (monitor pending contracts)
     * This should be called by a cron job every 10-15 seconds
     */
    public function processContractResults(): void
    {
        try {
            // Get all pending trades that are older than 6 seconds (for 5-tick contracts)
            // Exclude contracts that have exceeded max retries (5 retries)
            $pendingTrades = $this->db->query(
                "SELECT t.*, u.encrypted_api_token, ts.id as session_id
                 FROM trades t
                 INNER JOIN users u ON t.user_id = u.id
                 LEFT JOIN trading_sessions ts ON t.session_id = ts.id
                 LEFT JOIN contract_monitor cm ON t.contract_id = cm.contract_id
                 WHERE t.status = 'pending'
                 AND t.timestamp < DATE_SUB(NOW(), INTERVAL 6 SECOND)
                 AND u.encrypted_api_token IS NOT NULL
                 AND u.encrypted_api_token != ''
                 AND (cm.retry_count < 5 OR cm.retry_count IS NULL)
                 LIMIT 50"
            );
            
            foreach ($pendingTrades as $trade) {
                try {
                    try {
                        $this->scheduleContractMonitoring(
                            (int)$trade['user_id'],
                            (string)$trade['contract_id'],
                            (int)$trade['id']
                        );
                    } catch (Exception $e) {
                        error_log("Failed to ensure contract_monitor row for trade {$trade['id']} (contract {$trade['contract_id']}): " . $e->getMessage());
                    }

                    // Update contract_monitor to show we're checking this contract
                    $this->db->execute(
                        "UPDATE contract_monitor 
                         SET last_checked_at = NOW(),
                             updated_at = NOW()
                         WHERE contract_id = :contract_id",
                        [
                            'contract_id' => $trade['contract_id'],
                        ]
                    );
                    
                    $this->processContractResult(
                        (int)$trade['user_id'],
                        (string)$trade['contract_id'],
                        (int)$trade['id'],
                        $trade['encrypted_api_token']
                    );
                } catch (Exception $e) {
                    error_log("Contract processing error for trade {$trade['id']}: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            error_log("Process contract results error: " . $e->getMessage());
        }
    }
    
    /**
     * Process a single contract result
     */
    private function processContractResult(int $userId, string $contractId, int $tradeId, string $encryptedToken): void
    {
        try {
            // Decrypt token
            $apiToken = $this->decryptUserApiToken($userId, $encryptedToken);
            
            // Create Deriv API instance
            $derivApi = new DerivAPI($apiToken, null, (string)$userId);
            
            try {
                $contractInfo = $derivApi->getContractInfo((string)$contractId);
                
                $derivStatus = strtolower((string)($contractInfo['status'] ?? ''));
                $isTerminal = in_array($derivStatus, ['won', 'lost', 'sold'], true);
                $isSettleable = !empty($contractInfo['is_settleable']);

                // If the contract is still open/not settleable, keep trade pending and just update monitor retry count
                if (!$isTerminal && !$isSettleable) {
                    $this->db->execute(
                        "INSERT INTO contract_monitor (user_id, contract_id, trade_id, status, retry_count, last_checked_at, created_at, updated_at)
                         VALUES (:user_id, :contract_id, :trade_id, 'pending', 1, NOW(), NOW(), NOW())
                         ON DUPLICATE KEY UPDATE
                             status = 'pending',
                             user_id = VALUES(user_id),
                             trade_id = VALUES(trade_id),
                             last_checked_at = NOW(),
                             updated_at = NOW(),
                             retry_count = retry_count + 1",
                        [
                            'user_id' => $userId,
                            'contract_id' => $contractId,
                            'trade_id' => $tradeId,
                        ]
                    );

                    return;
                }

                // Terminal / settleable contract: finalize the trade
                $profit = (float)($contractInfo['profit'] ?? 0);
                $status = $profit > 0 ? 'won' : 'lost';

                $this->db->execute(
                    "UPDATE trades 
                     SET profit = :profit,
                         status = :status,
                         closed_at = NOW(),
                         payout = :payout
                     WHERE id = :id",
                    [
                        'profit' => $profit,
                        'status' => $status,
                        'payout' => $contractInfo['sell_price'] ?? 0,
                        'id' => $tradeId,
                    ]
                );

                $this->db->execute(
                    "INSERT INTO contract_monitor (user_id, contract_id, trade_id, status, retry_count, last_checked_at, created_at, updated_at)
                     VALUES (:user_id, :contract_id, :trade_id, :status, 1, NOW(), NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                         status = VALUES(status),
                         user_id = VALUES(user_id),
                         trade_id = VALUES(trade_id),
                         last_checked_at = NOW(),
                         updated_at = NOW(),
                         retry_count = retry_count + 1",
                    [
                        'user_id' => $userId,
                        'status' => $status,
                        'contract_id' => $contractId,
                        'trade_id' => $tradeId,
                    ]
                );
                
                // Update session statistics
                $session = $this->db->queryOne(
                    "SELECT id FROM trading_sessions 
                     WHERE user_id = :user_id 
                     AND state = 'active'
                     ORDER BY start_time DESC LIMIT 1",
                    ['user_id' => $userId]
                );
                
                if ($session) {
                    if ($profit > 0) {
                        $this->db->execute(
                            "UPDATE trading_sessions 
                             SET successful_trades = successful_trades + 1,
                                 total_profit = total_profit + :profit,
                                 daily_profit = daily_profit + :profit,
                                 last_activity_time = NOW()
                             WHERE id = :id",
                            [
                                'profit' => $profit,
                                'id' => $session['id'],
                            ]
                        );
                    } else {
                        $loss = abs($profit);
                        $this->db->execute(
                            "UPDATE trading_sessions 
                             SET failed_trades = failed_trades + 1,
                                 total_loss = total_loss + :loss,
                                 daily_loss = daily_loss + :loss,
                                 last_activity_time = NOW()
                             WHERE id = :id",
                            [
                                'loss' => $loss,
                                'id' => $session['id'],
                            ]
                        );
                    }
                }
                
                // Update daily stats in Settings
                $settings = $this->helper->getUserSettings($userId);
                if ($settings) {
                    if ($profit > 0) {
                        $this->db->execute(
                            "UPDATE settings 
                             SET daily_profit = daily_profit + :profit
                             WHERE user_id = :user_id",
                            [
                                'profit' => $profit,
                                'user_id' => $userId,
                            ]
                        );
                    } else {
                        $loss = abs($profit);
                        $this->db->execute(
                            "UPDATE settings 
                             SET daily_loss = daily_loss + :loss
                             WHERE user_id = :user_id",
                            [
                                'loss' => $loss,
                                'user_id' => $userId,
                            ]
                        );
                    }
                }
                
                // Check daily limits after update
                $updatedSettings = $this->helper->getUserSettings($userId);
                if ($updatedSettings) {
                    $this->checkDailyLimits($userId, $updatedSettings);
                }
                
                error_log("Trade completed for user {$userId}: {$status} - Profit: $" . number_format($profit, 2) . " (Contract: {$contractId})");
                
            } finally {
                $derivApi->close();
            }
            
        } catch (Exception $e) {
            error_log("Contract result processing error for user {$userId}, contract {$contractId}: " . $e->getMessage());
            
            $message = $e->getMessage();
            // If Deriv says input validation failed for proposal_open_contract,
            // the contract_id is not valid/accessible for this token. Retrying won't help,
            // so cancel the trade immediately instead of looping retries.
            if (stripos($message, 'Input validation failed') !== false || stripos($message, 'InputValidationFailed') !== false) {
                error_log("InputValidationFailed for contract {$contractId} - marking trade as cancelled without further retries");
                $this->db->execute(
                    "UPDATE trades SET status = 'cancelled' WHERE id = :id AND status = 'pending'",
                    ['id' => $tradeId]
                );
                $this->db->execute(
                    "INSERT INTO contract_monitor (user_id, contract_id, trade_id, status, retry_count, last_checked_at, created_at, updated_at)
                     VALUES (:user_id, :contract_id, :trade_id, 'cancelled', 1, NOW(), NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                         status = 'cancelled',
                         user_id = VALUES(user_id),
                         trade_id = VALUES(trade_id),
                         last_checked_at = NOW(),
                         updated_at = NOW()",
                    [
                        'user_id' => $userId,
                        'contract_id' => $contractId,
                        'trade_id' => $tradeId,
                    ]
                );
                return;
            }

            // Get current retry count from contract_monitor
            $monitor = $this->db->queryOne(
                "SELECT retry_count FROM contract_monitor WHERE contract_id = :contract_id",
                ['contract_id' => $contractId]
            );
            
            $retryCount = $monitor['retry_count'] ?? 0;
            $maxRetries = 5; // Allow more retries before cancelling
            
            if ($retryCount >= $maxRetries) {
                // Only mark as cancelled after max retries exceeded
                error_log("Max retries ({$maxRetries}) exceeded for contract {$contractId}, marking as cancelled");
                
                $this->db->execute(
                    "UPDATE trades SET status = 'cancelled' WHERE id = :id AND status = 'pending'",
                    ['id' => $tradeId]
                );
                
                // Update contract_monitor with error status
                $this->db->execute(
                    "INSERT INTO contract_monitor (user_id, contract_id, trade_id, status, retry_count, last_checked_at, created_at, updated_at)
                     VALUES (:user_id, :contract_id, :trade_id, 'cancelled', 1, NOW(), NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                         status = 'cancelled',
                         user_id = VALUES(user_id),
                         trade_id = VALUES(trade_id),
                         last_checked_at = NOW(),
                         updated_at = NOW(),
                         retry_count = retry_count + 1",
                    [
                        'contract_id' => $contractId,
                        'user_id' => $userId,
                        'trade_id' => $tradeId,
                    ]
                );
            } else {
                // Just update retry count, keep trade as pending
                error_log("Retry {$retryCount}/{$maxRetries} for contract {$contractId}, keeping as pending");
                
                // Update contract_monitor with error status but keep trade pending
                $this->db->execute(
                    "INSERT INTO contract_monitor (user_id, contract_id, trade_id, status, retry_count, last_checked_at, created_at, updated_at)
                     VALUES (:user_id, :contract_id, :trade_id, 'error', 1, NOW(), NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                         status = 'error',
                         user_id = VALUES(user_id),
                         trade_id = VALUES(trade_id),
                         last_checked_at = NOW(),
                         updated_at = NOW(),
                         retry_count = retry_count + 1",
                    [
                        'contract_id' => $contractId,
                        'user_id' => $userId,
                        'trade_id' => $tradeId,
                    ]
                );
                
                // Schedule another check sooner (exponential backoff)
                $nextCheckDelay = min(300, pow(2, $retryCount) * 10); // Max 5 minutes
                error_log("Scheduling next check for contract {$contractId} in {$nextCheckDelay} seconds");
            }
        }
    }

    /**
     * Inline fallback to process a contract result when cron is not running.
     * Sleeps briefly to allow 5-tick contracts to settle, then fetches the result.
     */
    private function fallbackProcessContractResult(int $userId, string $contractId, int $tradeId, string $encryptedToken): void
    {
        try {
            sleep(self::FALLBACK_CONTRACT_RESULT_DELAY);
            $this->processContractResult($userId, $contractId, $tradeId, $encryptedToken);
        } catch (Exception $e) {
            error_log("[TradingBotService] Fallback contract processing failed for user {$userId}, contract {$contractId}: " . $e->getMessage());
        }
    }
    
    /**
     * Handle trading errors
     */
    private function handleTradingError(int $userId, Exception $error): void
    {
        // Get active session
        $activeSession = $this->helper->getActiveTradingSession($userId);
        
        if (!$activeSession) {
            return;
        }
        
        // Update error count
        $this->db->execute(
            "UPDATE trading_sessions 
             SET error_count = error_count + 1,
                 consecutive_errors = consecutive_errors + 1,
                 last_error = :error,
                 last_error_time = NOW()
             WHERE id = :id",
            [
                'error' => $error->getMessage(),
                'id' => $activeSession['id'],
            ]
        );
        
        // Check if max errors reached
        $session = $this->db->queryOne(
            "SELECT error_count FROM trading_sessions WHERE id = :id",
            ['id' => $activeSession['id']]
        );
        
        if ($session && (int)$session['error_count'] >= self::MAX_ERROR_COUNT) {
            error_log("Max error count reached for user {$userId}. Stopping trading.");
            $this->helper->updateTradingSessionState(
                $activeSession['id'],
                self::STATE_ERROR,
                'system',
                'Max error count reached'
            );
            // System stop: keep is_bot_active = 1 so cron can auto-restart after transient issues
            $this->stopTrading($userId, 'system', 'Max error count reached', false);
        }
    }

    /**
     * Normalize asset strings and filter placeholders
     */
    private function normalizeAsset(?string $asset): ?string
    {
        if ($asset === null) {
            return null;
        }

        $normalized = strtoupper(trim($asset));

        if ($normalized === '' || $normalized === 'NULL' || $normalized === 'NONE' || $normalized === 'N/A') {
            return null;
        }

        if (!preg_match('/^[A-Z0-9_.-]{1,50}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Get default asset (random) from predefined list
     */
    private function getDefaultAsset(): string
    {
        return self::DEFAULT_ASSETS[array_rand(self::DEFAULT_ASSETS)];
    }
    
    /**
     * Get account balance for a user (with caching and connection pooling)
     */
    public function getAccountBalance(int $userId): float
    {
        $debugLog = [];
        $debugLog[] = "=== TradingBotService::getAccountBalance START for user {$userId} ===";
        $debugLog[] = "Timestamp: " . date('Y-m-d H:i:s');
        
        try {
            // Check balance cache first
            $debugLog[] = "Step 1: Checking balance cache";
            if (isset($this->balanceCache[$userId])) {
                $cache = $this->balanceCache[$userId];
                $age = time() - $cache['timestamp'];
                $debugLog[] = "  - Cache found, age: {$age}s, ttl: {$cache['ttl']}s";
                
                if ($age < $cache['ttl']) {
                    $debugLog[] = "  - Using cached balance: " . $cache['balance'];
                    @error_log("[TradingBotService::getAccountBalance] " . implode("\n", $debugLog));
                    return $cache['balance'];
                } else {
                    $debugLog[] = "  - Cache expired, clearing";
                    unset($this->balanceCache[$userId]);
                }
            } else {
                $debugLog[] = "  - No cache found";
            }
            
            // Get user API token
            $debugLog[] = "Step 2: Querying database for user token";
            $user = $this->db->queryOne(
                "SELECT id, email, encrypted_api_token, api_token_created_at FROM users WHERE id = :id",
                ['id' => $userId]
            );
            
            $debugLog[] = "  - User query result: " . ($user ? 'FOUND' : 'NOT FOUND');
            if ($user) {
                $debugLog[] = "  - Email: " . ($user['email'] ?? 'N/A');
                $debugLog[] = "  - Has encrypted_token: " . (!empty($user['encrypted_api_token']) ? 'YES' : 'NO');
                $debugLog[] = "  - Token length: " . (isset($user['encrypted_api_token']) ? strlen($user['encrypted_api_token']) : 0);
            }
            
            if (!$user || empty($user['encrypted_api_token'])) {
                $debugLog[] = "Step 2 FAILED: No encrypted token found";
                @error_log("[TradingBotService::getAccountBalance] " . implode("\n", $debugLog));
                throw new Exception('API token not found');
            }
            
            // Decrypt token
            $debugLog[] = "Step 3: Decrypting token";
            $debugLog[] = "  - Encrypted token length: " . strlen($user['encrypted_api_token']);
            $debugLog[] = "  - Encrypted token preview: " . substr($user['encrypted_api_token'], 0, 50) . '...';
            
            try {
                $apiToken = $this->decryptUserApiToken($userId, $user['encrypted_api_token']);
                $debugLog[] = "  - Decryption SUCCESS";
                $debugLog[] = "  - Decrypted token length: " . strlen($apiToken);
                $debugLog[] = "  - Decrypted token preview: " . substr($apiToken, 0, 20) . '...' . substr($apiToken, -10);
            } catch (Exception $decryptError) {
                $debugLog[] = "  - Decryption FAILED: " . $decryptError->getMessage();
                $debugLog[] = "  - Error type: " . get_class($decryptError);
                @error_log("[TradingBotService::getAccountBalance] " . implode("\n", $debugLog));
                throw new Exception('Failed to decrypt API token: ' . $decryptError->getMessage());
            }
            
            // Get or create DerivAPI instance from connection pool
            $debugLog[] = "Step 4: Getting or creating DerivAPI connection";
            $derivApi = $this->getOrCreateApiConnection($userId, $apiToken);
            $debugLog[] = "  - DerivAPI instance obtained";
            $debugLog[] = "  - Connection status: " . ($derivApi->isConnected() ? 'CONNECTED' : 'NOT CONNECTED');
            
            // Retry logic with exponential backoff
            $lastError = null;
            for ($attempt = 1; $attempt <= self::MAX_BALANCE_RETRIES; $attempt++) {
                $debugLog[] = "Step 5: Attempt {$attempt}/" . self::MAX_BALANCE_RETRIES . " to get balance";
                
                try {
                    // Check connection health before use
                    $isConnected = $derivApi->isConnected();
                    $debugLog[] = "  - Connection check: " . ($isConnected ? 'CONNECTED' : 'NOT CONNECTED');
                    
                    if (!$isConnected) {
                        $debugLog[] = "  - Connection not healthy, recreating";
                        $this->closeApiConnection($userId);
                        $derivApi = $this->getOrCreateApiConnection($userId, $apiToken);
                        $debugLog[] = "  - New connection status: " . ($derivApi->isConnected() ? 'CONNECTED' : 'NOT CONNECTED');
                    }
                    
                    $debugLog[] = "  - Calling derivApi->getBalance()";
                    $balance = $derivApi->getBalance();
                    $debugLog[] = "  - Balance retrieved: " . $balance;
                    $debugLog[] = "  - Balance type: " . gettype($balance);
                    $debugLog[] = "  - Balance value: " . var_export($balance, true);
                    
                    // Cache the balance
                    $this->balanceCache[$userId] = [
                        'balance' => $balance,
                        'timestamp' => time(),
                        'ttl' => self::BALANCE_CACHE_TTL,
                    ];
                    $debugLog[] = "  - Balance cached";
                    
                    $debugLog[] = "=== TradingBotService::getAccountBalance SUCCESS ===";
                    @error_log("[TradingBotService::getAccountBalance] " . implode("\n", $debugLog));
                    
                    return $balance;
                    
                } catch (Exception $balanceError) {
                    $lastError = $balanceError;
                    $debugLog[] = "  - Attempt {$attempt} FAILED: " . $balanceError->getMessage();
                    $debugLog[] = "  - Error type: " . get_class($balanceError);
                    $debugLog[] = "  - Error trace: " . substr($balanceError->getTraceAsString(), 0, 500);
                    
                    // If connection error, close and recreate
                    $isConnectionError = (
                        strpos($balanceError->getMessage(), 'connection') !== false || 
                        strpos($balanceError->getMessage(), 'WebSocket') !== false ||
                        strpos($balanceError->getMessage(), 'timeout') !== false
                    );
                    
                    if ($isConnectionError) {
                        $debugLog[] = "  - Connection error detected, closing connection";
                        $this->closeApiConnection($userId);
                        $derivApi = null; // Will be recreated on next attempt
                    }
                    
                    // If not last attempt, wait before retry
                    if ($attempt < self::MAX_BALANCE_RETRIES) {
                        $delay = self::RETRY_DELAY_BASE * pow(2, $attempt - 1); // Exponential backoff
                        $debugLog[] = "  - Waiting {$delay}s before retry";
                        sleep($delay);
                        
                        // Recreate connection if needed
                        if ($derivApi === null) {
                            $debugLog[] = "  - Recreating connection";
                            $derivApi = $this->getOrCreateApiConnection($userId, $apiToken);
                        }
                    }
                }
            }
            
            // All retries failed
            $debugLog[] = "Step 5 FAILED: All retries exhausted";
            $debugLog[] = "  - Last error: " . ($lastError ? $lastError->getMessage() : 'Unknown');
            $debugLog[] = "=== TradingBotService::getAccountBalance FAILED ===";
            @error_log("[TradingBotService::getAccountBalance] " . implode("\n", $debugLog));
            
            throw $lastError ?: new Exception('All balance retrieval attempts failed');
            
        } catch (Exception $e) {
            $debugLog[] = "EXCEPTION CAUGHT: " . $e->getMessage();
            $debugLog[] = "Exception type: " . get_class($e);
            $debugLog[] = "Stack trace: " . $e->getTraceAsString();
            $debugLog[] = "=== TradingBotService::getAccountBalance EXCEPTION ===";
            @error_log("[TradingBotService::getAccountBalance] " . implode("\n", $debugLog));
            
            // Clear cache on error
            unset($this->balanceCache[$userId]);
            
            return 0.0;
        }
    }
    
    /**
     * Get or create DerivAPI connection from pool
     */
    private function getOrCreateApiConnection(int $userId, string $apiToken): DerivAPI
    {
        $connectionKey = (string)$userId;
        
        // Check if connection exists and is still valid
        if (isset($this->apiConnections[$connectionKey])) {
            $connection = $this->apiConnections[$connectionKey];
            $age = time() - $connection['created_at'];
            
            // If connection is still within TTL and is connected, reuse it
            if ($age < self::CONNECTION_POOL_TTL && $connection['api']->isConnected()) {
                @error_log("[TradingBotService] Reusing existing connection for user {$userId} (age: {$age}s)");
                return $connection['api'];
            } else {
                @error_log("[TradingBotService] Connection expired or disconnected, closing (age: {$age}s)");
                try {
                    $connection['api']->close();
                } catch (Exception $e) {
                    // Ignore cleanup errors
                }
                unset($this->apiConnections[$connectionKey]);
            }
        }
        
        // Create new connection
        @error_log("[TradingBotService] Creating new DerivAPI connection for user {$userId}");
        $derivApi = new DerivAPI($apiToken, null, (string)$userId);
        
        // Store in connection pool
        $this->apiConnections[$connectionKey] = [
            'api' => $derivApi,
            'created_at' => time(),
            'user_id' => $userId,
        ];
        
        return $derivApi;
    }
    
    /**
     * Close API connection for a user
     */
    private function closeApiConnection(int $userId): void
    {
        $connectionKey = (string)$userId;
        
        if (isset($this->apiConnections[$connectionKey])) {
            @error_log("[TradingBotService] Closing connection for user {$userId}");
            try {
                $this->apiConnections[$connectionKey]['api']->close();
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
            unset($this->apiConnections[$connectionKey]);
        }
    }
    
    /**
     * Clear expired connections from pool
     */
    public function cleanupConnectionPool(): void
    {
        $now = time();
        foreach ($this->apiConnections as $key => $connection) {
            $age = $now - $connection['created_at'];
            if ($age >= self::CONNECTION_POOL_TTL) {
                @error_log("[TradingBotService] Cleaning up expired connection for user {$connection['user_id']}");
                try {
                    $connection['api']->close();
                } catch (Exception $e) {
                    // Ignore cleanup errors
                }
                unset($this->apiConnections[$key]);
            }
        }
    }
    
    /**
     * Clear balance cache for a user (call after balance-changing operations)
     */
    public function clearBalanceCache(int $userId): void
    {
        unset($this->balanceCache[$userId]);
        @error_log("[TradingBotService] Cleared balance cache for user {$userId}");
    }
    
    /**
     * Check if trading is active for a user
     */
    public function isTradingActive(int $userId): bool
    {
        $activeSession = $this->helper->getActiveTradingSession($userId);
        return $activeSession !== null && $activeSession['state'] === self::STATE_ACTIVE;
    }
    
    /**
     * Get session information for a user
     */
    public function getSessionInfo(int $userId): ?array
    {
        $activeSession = $this->helper->getActiveTradingSession($userId);
        
        if (!$activeSession) {
            return null;
        }
        
        // Get trade statistics
        $stats = $this->helper->getUserTradeStats($userId);
        
        return [
            'isActive' => $activeSession['state'] === self::STATE_ACTIVE,
            'state' => $activeSession['state'],
            'activeContracts' => $this->getActiveContractsCount($userId),
            'totalTrades' => (int)($activeSession['total_trades'] ?? 0),
            'successfulTrades' => (int)($activeSession['successful_trades'] ?? 0),
            'failedTrades' => (int)($activeSession['failed_trades'] ?? 0),
            'successRate' => $stats['total_trades'] > 0 
                ? round(($stats['won_trades'] / $stats['total_trades']) * 100, 2) 
                : 0,
        ];
    }
    
    /**
     * Cleanup stale sessions
     */
    public function cleanupStaleSessions(): void
    {
        try {
            // Find sessions that haven't been active for 30+ minutes
            $staleSessions = $this->db->query(
                "SELECT id, user_id FROM trading_sessions 
                 WHERE state IN ('active', 'initializing')
                 AND last_activity_time < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)",
                ['minutes' => self::MAX_INACTIVE_TIME / 60]
            );
            
            foreach ($staleSessions as $session) {
                $this->helper->updateTradingSessionState(
                    (int)$session['id'],
                    self::STATE_STOPPED,
                    'system',
                    'Session timeout'
                );
                // System stop: keep is_bot_active = 1 so trading can resume automatically
                $this->stopTrading((int)$session['user_id'], 'system', 'Session timeout', false);
            }
            
        } catch (Exception $e) {
            error_log("Cleanup stale sessions error: " . $e->getMessage());
        }
    }
    
    /**
     * Health check for active sessions
     */
    public function performHealthCheck(): void
    {
        try {
            $activeSessions = $this->db->query(
                "SELECT id, user_id, error_count, consecutive_errors 
                 FROM trading_sessions 
                 WHERE state = 'active'"
            );
            
            foreach ($activeSessions as $session) {
                // Check for excessive errors
                if ((int)$session['error_count'] >= self::MAX_ERROR_COUNT) {
                    $this->helper->updateTradingSessionState(
                        (int)$session['id'],
                        self::STATE_ERROR,
                        'system',
                        'Health check failed: too many errors'
                    );
                    // System stop: keep is_bot_active = 1 so trading can resume automatically
                    $this->stopTrading((int)$session['user_id'], 'system', 'Health check failed: too many errors', false);
                }
            }
            
        } catch (Exception $e) {
            error_log("Health check error: " . $e->getMessage());
        }
    }
}

