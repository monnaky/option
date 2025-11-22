<?php

/**
 * Database Helper Class
 * 
 * Provides convenient methods for common database operations
 * specific to VTM Option models.
 */

namespace App\Utils;

use App\Config\Database;

class DatabaseHelper
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    // ============================================================================
    // USER OPERATIONS
    // ============================================================================
    
    /**
     * Create a new user
     */
    public function createUser(array $userData): int
    {
        $data = [
            'email' => $userData['email'],
            'password' => $userData['password'], // Should be hashed before calling
            'is_active' => $userData['is_active'] ?? true,
        ];
        
        return $this->db->insert('users', $data);
    }
    
    /**
     * Find user by email
     */
    public function findUserByEmail(string $email): ?array
    {
        $sql = "SELECT * FROM users WHERE email = :email LIMIT 1";
        return $this->db->queryOne($sql, ['email' => $email]);
    }
    
    /**
     * Update user API token
     */
    public function updateUserApiToken(int $userId, string $encryptedToken): bool
    {
        $data = [
            'encrypted_api_token' => $encryptedToken,
            'api_token_created_at' => date('Y-m-d H:i:s'),
        ];
        
        return $this->db->update('users', $data, ['id' => $userId]) > 0;
    }
    
    /**
     * Update user last API token usage
     */
    public function updateUserApiTokenLastUsed(int $userId): bool
    {
        $data = [
            'api_token_last_used' => date('Y-m-d H:i:s'),
        ];
        
        return $this->db->update('users', $data, ['id' => $userId]) > 0;
    }
    
    // ============================================================================
    // SETTINGS OPERATIONS
    // ============================================================================
    
    /**
     * Get or create user settings
     */
    public function getUserSettings(int $userId): ?array
    {
        $sql = "SELECT * FROM settings WHERE user_id = :user_id LIMIT 1";
        $settings = $this->db->queryOne($sql, ['user_id' => $userId]);
        
        if (!$settings) {
            // Create default settings
            $tomorrow = date('Y-m-d', strtotime('+1 day'));
            $data = [
                'user_id' => $userId,
                'stake' => 1.00,
                'target' => 100.00,
                'stop_limit' => 50.00,
                'is_bot_active' => false,
                'daily_profit' => 0.00,
                'daily_loss' => 0.00,
                'reset_date' => $tomorrow,
            ];
            
            $this->db->insert('settings', $data);
            return $this->getUserSettings($userId);
        }
        
        return $settings;
    }
    
    /**
     * Update user settings
     */
    public function updateUserSettings(int $userId, array $settingsData): bool
    {
        $allowedFields = ['stake', 'target', 'stop_limit', 'is_bot_active', 'last_active_at'];
        $data = array_intersect_key($settingsData, array_flip($allowedFields));
        
        if (empty($data)) {
            return false;
        }
        
        return $this->db->update('settings', $data, ['user_id' => $userId]) > 0;
    }
    
    /**
     * Reset daily stats for user
     */
    public function resetUserDailyStats(int $userId): bool
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $data = [
            'daily_profit' => 0.00,
            'daily_loss' => 0.00,
            'reset_date' => $tomorrow,
        ];
        
        return $this->db->update('settings', $data, ['user_id' => $userId]) > 0;
    }
    
    // ============================================================================
    // TRADE OPERATIONS
    // ============================================================================
    
    /**
     * Create a new trade
     */
    public function createTrade(array $tradeData): int
    {
        $data = [
            'user_id' => $tradeData['user_id'],
            'session_id' => $tradeData['session_id'] ?? null,
            'trade_id' => $tradeData['trade_id'],
            'contract_id' => $tradeData['contract_id'] ?? null,
            'asset' => $tradeData['asset'],
            'direction' => $tradeData['direction'],
            'stake' => $tradeData['stake'],
            'payout' => $tradeData['payout'] ?? null,
            'profit' => $tradeData['profit'] ?? 0.00,
            'status' => $tradeData['status'] ?? 'pending',
            'timestamp' => $tradeData['timestamp'] ?? date('Y-m-d H:i:s'),
        ];
        
        return $this->db->insert('trades', $data);
    }
    
    /**
     * Update trade status
     */
    public function updateTradeStatus(string $tradeId, string $status, ?float $profit = null): bool
    {
        $data = [
            'status' => $status,
            'closed_at' => date('Y-m-d H:i:s'),
        ];
        
        if ($profit !== null) {
            $data['profit'] = $profit;
        }
        
        return $this->db->update('trades', $data, ['trade_id' => $tradeId]) > 0;
    }
    
    /**
     * Get user trades
     */
    public function getUserTrades(int $userId, array $options = []): array
    {
        $sql = "SELECT * FROM trades WHERE user_id = :user_id";
        $params = ['user_id' => $userId];
        
        if (isset($options['status'])) {
            $sql .= " AND status = :status";
            $params['status'] = $options['status'];
        }
        
        if (isset($options['session_id'])) {
            $sql .= " AND session_id = :session_id";
            $params['session_id'] = $options['session_id'];
        }
        
        $sql .= " ORDER BY timestamp DESC";
        
        if (isset($options['limit'])) {
            $sql .= " LIMIT " . (int) $options['limit'];
            
            if (isset($options['offset'])) {
                $sql .= " OFFSET " . (int) $options['offset'];
            }
        }
        
        return $this->db->query($sql, $params);
    }
    
    /**
     * Get trade statistics for user
     */
    public function getUserTradeStats(int $userId): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_trades,
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as won_trades,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as lost_trades,
                SUM(CASE WHEN status = 'won' THEN profit ELSE 0 END) as total_profit,
                SUM(CASE WHEN status = 'lost' THEN ABS(profit) ELSE 0 END) as total_loss,
                SUM(profit) as net_profit
            FROM trades
            WHERE user_id = :user_id
        ";
        
        return $this->db->queryOne($sql, ['user_id' => $userId]) ?: [];
    }
    
    // ============================================================================
    // TRADING SESSION OPERATIONS
    // ============================================================================
    
    /**
     * Create a new trading session
     */
    public function createTradingSession(array $sessionData): int
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $data = [
            'user_id' => $sessionData['user_id'],
            'session_id' => $sessionData['session_id'],
            'state' => $sessionData['state'] ?? 'initializing',
            'stake' => $sessionData['stake'],
            'target' => $sessionData['target'],
            'stop_limit' => $sessionData['stop_limit'],
            'max_active_contracts' => $sessionData['max_active_contracts'] ?? 50,
            'max_daily_trades' => $sessionData['max_daily_trades'] ?? 0,
            'start_time' => date('Y-m-d H:i:s'),
            'last_activity_time' => date('Y-m-d H:i:s'),
            'reset_date' => $tomorrow,
            'started_by' => $sessionData['started_by'] ?? 'user',
        ];
        
        return $this->db->insert('trading_sessions', $data);
    }
    
    /**
     * Get active trading session for user
     */
    public function getActiveTradingSession(int $userId): ?array
    {
        $sql = "
            SELECT * FROM trading_sessions 
            WHERE user_id = :user_id 
            AND state IN ('initializing', 'active', 'recovering')
            ORDER BY start_time DESC
            LIMIT 1
        ";
        
        return $this->db->queryOne($sql, ['user_id' => $userId]);
    }
    
    /**
     * Update trading session state
     */
    public function updateTradingSessionState(int $sessionId, string $state, ?string $stoppedBy = null, ?string $stopReason = null): bool
    {
        $data = [
            'state' => $state,
            'last_activity_time' => date('Y-m-d H:i:s'),
        ];
        
        if ($state === 'stopped' || $state === 'expired') {
            $data['end_time'] = date('Y-m-d H:i:s');
            if ($stoppedBy) {
                $data['stopped_by'] = $stoppedBy;
            }
            if ($stopReason) {
                $data['stop_reason'] = $stopReason;
            }
        }
        
        return $this->db->update('trading_sessions', $data, ['id' => $sessionId], []) > 0;
    }
    
    /**
     * Update trading session activity
     */
    public function updateTradingSessionActivity(int $sessionId): bool
    {
        $data = [
            'last_activity_time' => date('Y-m-d H:i:s'),
        ];
        
        return $this->db->update('trading_sessions', $data, ['id' => $sessionId], []) > 0;
    }
    
    // ============================================================================
    // SIGNAL OPERATIONS
    // ============================================================================
    
    /**
     * Create a new signal
     */
    public function createSignal(array $signalData): int
    {
        $data = [
            'signal_type' => $signalData['signal_type'],
            'asset' => $signalData['asset'] ?? null,
            'raw_text' => $signalData['raw_text'],
            'source' => $signalData['source'] ?? 'api',
            'source_ip' => $signalData['source_ip'] ?? null,
            'processed' => false,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        return $this->db->insert('signals', $data);
    }
    
    /**
     * Get unprocessed signals
     */
    public function getUnprocessedSignals(int $limit = 10): array
    {
        $sql = "
            SELECT * FROM signals 
            WHERE processed = FALSE 
            ORDER BY timestamp ASC 
            LIMIT :limit
        ";
        
        return $this->db->query($sql, ['limit' => $limit]);
    }
    
    /**
     * Mark signal as processed
     */
    public function markSignalAsProcessed(int $signalId, int $totalUsers, int $successfulExecutions, int $failedExecutions, ?int $executionTime = null): bool
    {
        $data = [
            'processed' => true,
            'processed_at' => date('Y-m-d H:i:s'),
            'total_users' => $totalUsers,
            'successful_executions' => $successfulExecutions,
            'failed_executions' => $failedExecutions,
        ];
        
        if ($executionTime !== null) {
            $data['execution_time'] = $executionTime;
        }
        
        return $this->db->update('signals', $data, ['id' => $signalId], []) > 0;
    }
    
    // ============================================================================
    // SESSION (JWT) OPERATIONS
    // ============================================================================
    
    /**
     * Create a new JWT session
     */
    public function createSession(int $userId, string $token, string $expiresAt): int
    {
        $data = [
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
        
        return $this->db->insert('sessions', $data);
    }
    
    /**
     * Find session by token
     */
    public function findSessionByToken(string $token): ?array
    {
        $sql = "
            SELECT s.*, u.email, u.is_active 
            FROM sessions s
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.token = :token 
            AND s.expires_at > NOW()
            LIMIT 1
        ";
        
        return $this->db->queryOne($sql, ['token' => $token]);
    }
    
    /**
     * Delete expired sessions
     */
    public function deleteExpiredSessions(): int
    {
        $sql = "DELETE FROM sessions WHERE expires_at < NOW()";
        return $this->db->execute($sql);
    }
    
    /**
     * Delete session by token
     */
    public function deleteSession(string $token): bool
    {
        return $this->db->delete('sessions', ['token' => $token]) > 0;
    }
}

