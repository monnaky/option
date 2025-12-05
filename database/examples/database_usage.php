<?php

/**
 * Database Usage Examples
 * 
 * This file demonstrates how to use the Database class and DatabaseHelper
 * for common operations in the VTM Option application.
 */

require_once __DIR__ . '/../../app/autoload.php';

use App\Config\Database;
use App\Utils\DatabaseHelper;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

// ============================================================================
// BASIC DATABASE OPERATIONS
// ============================================================================

// Get database instance
$db = Database::getInstance();

// Example 1: Simple query
$users = $db->query("SELECT * FROM users WHERE is_active = :active", ['active' => true]);

// Example 2: Query single row
$user = $db->queryOne("SELECT * FROM users WHERE id = :id", ['id' => 1]);

// Example 3: Query single value
$userCount = $db->queryValue("SELECT COUNT(*) FROM users");

// Example 4: Insert record
$userId = $db->insert('users', [
    'email' => 'test@example.com',
    'password' => password_hash('password123', PASSWORD_DEFAULT),
    'is_active' => true,
]);

// Example 5: Update record
$affected = $db->update('users', 
    ['is_active' => false], 
    ['id' => $userId]
);

// Example 6: Delete record
$deleted = $db->delete('users', ['id' => $userId]);

// Example 7: Find by ID
$user = $db->findById('users', 1);

// Example 8: Find with conditions
$activeUsers = $db->find('users', 
    ['is_active' => true],
    ['orderBy' => 'created_at DESC', 'limit' => 10]
);

// Example 9: Count records
$totalUsers = $db->count('users', ['is_active' => true]);

// Example 10: Transactions
try {
    $db->beginTransaction();
    
    // Multiple operations
    $userId = $db->insert('users', [
        'email' => 'newuser@example.com',
        'password' => password_hash('password', PASSWORD_DEFAULT),
    ]);
    
    $db->insert('settings', [
        'user_id' => $userId,
        'stake' => 1.00,
        'target' => 100.00,
        'stop_limit' => 50.00,
        'reset_date' => date('Y-m-d', strtotime('+1 day')),
    ]);
    
    $db->commit();
    echo "Transaction successful\n";
} catch (Exception $e) {
    $db->rollback();
    echo "Transaction failed: " . $e->getMessage() . "\n";
}

// ============================================================================
// USING DATABASE HELPER
// ============================================================================

$helper = new DatabaseHelper();

// Example 11: Create user
$userId = $helper->createUser([
    'email' => 'user@example.com',
    'password' => password_hash('password123', PASSWORD_DEFAULT),
    'is_active' => true,
]);

// Example 12: Find user by email
$user = $helper->findUserByEmail('user@example.com');

// Example 13: Get or create user settings
$settings = $helper->getUserSettings($userId);

// Example 14: Update user settings
$helper->updateUserSettings($userId, [
    'stake' => 5.00,
    'target' => 200.00,
    'stop_limit' => 100.00,
    'is_bot_active' => true,
]);

// Example 15: Create trade
$tradeId = $helper->createTrade([
    'user_id' => $userId,
    'trade_id' => 'TRADE_' . uniqid(),
    'asset' => 'R_100',
    'direction' => 'RISE',
    'stake' => 1.00,
    'status' => 'pending',
]);

// Example 16: Update trade status
$helper->updateTradeStatus('TRADE_123', 'won', 1.80);

// Example 17: Get user trades
$trades = $helper->getUserTrades($userId, [
    'status' => 'won',
    'limit' => 10,
]);

// Example 18: Get trade statistics
$stats = $helper->getUserTradeStats($userId);
echo "Total Trades: " . $stats['total_trades'] . "\n";
echo "Won Trades: " . $stats['won_trades'] . "\n";
echo "Total Profit: " . $stats['total_profit'] . "\n";

// Example 19: Create trading session
$sessionId = $helper->createTradingSession([
    'user_id' => $userId,
    'session_id' => 'SESSION_' . uniqid(),
    'stake' => 1.00,
    'target' => 100.00,
    'stop_limit' => 50.00,
    'started_by' => 'user',
]);

// Example 20: Get active trading session
$activeSession = $helper->getActiveTradingSession($userId);

// Example 21: Update trading session state
$helper->updateTradingSessionState($sessionId, 'active');

// Example 22: Create signal
$signalId = $helper->createSignal([
    'signal_type' => 'RISE',
    'asset' => 'R_100',
    'raw_text' => 'RISE signal received',
    'source' => 'api',
]);

// Example 23: Get unprocessed signals
$signals = $helper->getUnprocessedSignals(10);

// Example 24: Mark signal as processed
$helper->markSignalAsProcessed($signalId, 10, 8, 2, 150);

// Example 25: Create JWT session
$expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
$sessionTokenId = $helper->createSession($userId, 'jwt_token_here', $expiresAt);

// Example 26: Find session by token
$session = $helper->findSessionByToken('jwt_token_here');

// Example 27: Cleanup expired sessions
$deletedCount = $helper->deleteExpiredSessions();
echo "Deleted $deletedCount expired sessions\n";

// ============================================================================
// COMPLEX QUERIES
// ============================================================================

// Example 28: Join query
$sql = "
    SELECT 
        u.email,
        COUNT(t.id) as total_trades,
        SUM(CASE WHEN t.status = 'won' THEN t.profit ELSE 0 END) as total_profit
    FROM users u
    LEFT JOIN trades t ON u.id = t.user_id
    WHERE u.is_active = :active
    GROUP BY u.id, u.email
    ORDER BY total_profit DESC
    LIMIT 10
";

$topTraders = $db->query($sql, ['active' => true]);

// Example 29: Using views
$activeSessions = $db->query("SELECT * FROM active_trading_sessions");

// Example 30: Using stored procedures
$db->execute("CALL ResetDailyStats()");
$db->execute("CALL CleanupExpiredSessions()");

// ============================================================================
// ERROR HANDLING
// ============================================================================

try {
    $user = $db->findById('users', 999);
    if ($user === null) {
        echo "User not found\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// ============================================================================
// BEST PRACTICES
// ============================================================================

// Always use prepared statements (automatic with Database class)
// Always validate input before database operations
// Use transactions for multiple related operations
// Handle exceptions properly
// Close connections when done (automatic with singleton pattern)

echo "\nAll examples completed successfully!\n";

