<?php
/**
 * Transaction Stream Monitor
 * 
 * Monitors Deriv transaction stream in real-time to update trade statuses
 * This eliminates the need for polling and prevents premature cancellations
 * 
 * Usage: php transaction_stream_monitor.php [--user-id=N] [--daemon]
 *   --user-id=N  Monitor specific user (optional, defaults to all active users)
 *   --daemon     Run as daemon process
 */

// Disable session for CLI
if (php_sapi_name() === 'cli') {
    define('DISABLE_SESSION', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/autoload.php';

use App\Config\Database;
use App\Services\TradingBotService;

// Parse command line arguments
$options = getopt('', ['user-id::', 'daemon']);
$userId = isset($options['user-id']) ? (int)$options['user-id'] : null;
$daemon = isset($options['daemon']);

echo "=== Transaction Stream Monitor ===\n";
echo "Mode: " . ($daemon ? "DAEMON (continuous)" : "ONCE (single run)") . "\n";
echo "User ID: " . ($userId ? $userId : "All active users") . "\n\n";

$db = Database::getInstance();
$tradingBot = TradingBotService::getInstance();

// Get reflection for private methods
$reflection = new ReflectionClass($tradingBot);
$decryptMethod = $reflection->getMethod('decryptUserApiToken');
$decryptMethod->setAccessible(true);

/**
 * Get active users with API tokens
 */
function getActiveUsers($db, $userId = null) {
    $sql = "
        SELECT u.id, u.encrypted_api_token, u.deriv_login_id
        FROM users u
        INNER JOIN settings s ON u.id = s.user_id
        WHERE u.is_active = 1
        AND u.encrypted_api_token IS NOT NULL
        AND u.encrypted_api_token != ''
        AND s.is_bot_active = 1
    ";
    
    $params = [];
    if ($userId) {
        $sql .= " AND u.id = :user_id";
        $params['user_id'] = $userId;
    }
    
    return $db->query($sql, $params);
}

/**
 * Process transaction and update corresponding trade
 */
function processTransaction($db, $transaction, $userId) {
    // Only process sell transactions
    if ($transaction['action'] !== 'sell') {
        return;
    }
    
    $contractId = $transaction['contract_id'];
    $amount = (float)$transaction['amount'];
    $balance = (float)$transaction['balance'];
    
    echo "Processing sell transaction: Contract {$contractId}, Amount {$amount}\n";
    
    // Find corresponding trade
    $trade = $db->queryOne(
        "SELECT * FROM trades 
         WHERE contract_id = :contract_id 
         AND user_id = :user_id
         AND status = 'pending'",
        [
            'contract_id' => $contractId,
            'user_id' => $userId
        ]
    );
    
    if (!$trade) {
        echo "  No pending trade found for contract {$contractId}\n";
        return;
    }
    
    // Determine outcome based on amount
    $profit = $amount - (float)$trade['stake'];
    $status = $profit > 0 ? 'won' : 'lost';
    
    echo "  Updating trade #{$trade['id']}: {$status} (Profit: {$profit})\n";
    
    // Update trade
    $db->execute("
        UPDATE trades 
        SET status = :status,
            profit = :profit,
            payout = :amount,
            closed_at = FROM_UNIXTIME(:transaction_time)
        WHERE id = :id
    ", [
        'status' => $status,
        'profit' => $profit,
        'amount' => $amount,
        'transaction_time' => $transaction['transaction_time'],
        'id' => $trade['id']
    ]);
    
    // Update contract monitor
    $db->execute("
        UPDATE contract_monitor 
        SET status = :status,
            last_checked_at = NOW(),
            updated_at = NOW()
        WHERE contract_id = :contract_id
    ", [
        'status' => $status,
        'contract_id' => $contractId
    ]);
    
    // Update user statistics
    updateUserStats($db, $userId, $profit, $status);
}

/**
 * Update user statistics
 */
function updateUserStats($db, $userId, $profit, $status) {
    // Get active session
    $session = $db->queryOne(
        "SELECT id FROM trading_sessions 
         WHERE user_id = :user_id 
         AND state = 'active'
         ORDER BY start_time DESC LIMIT 1",
        ['user_id' => $userId]
    );
    
    if ($session) {
        if ($profit > 0) {
            $db->execute("
                UPDATE trading_sessions 
                SET successful_trades = successful_trades + 1,
                    total_profit = total_profit + :profit,
                    daily_profit = daily_profit + :profit,
                    last_activity_time = NOW()
                WHERE id = :id
            ", [
                'profit' => $profit,
                'id' => $session['id']
            ]);
        } else {
            $loss = abs($profit);
            $db->execute("
                UPDATE trading_sessions 
                SET failed_trades = failed_trades + 1,
                    total_loss = total_loss + :loss,
                    daily_loss = daily_loss + :loss,
                    last_activity_time = NOW()
                WHERE id = :id
            ", [
                'loss' => $loss,
                'id' => $session['id']
            ]);
        }
    }
    
    // Update settings
    if ($profit > 0) {
        $db->execute("
            UPDATE settings 
            SET daily_profit = daily_profit + :profit
            WHERE user_id = :user_id
        ", [
            'profit' => $profit,
            'user_id' => $userId
        ]);
    }
}

/**
 * Monitor transactions for a user
 */
function monitorUserTransactions($db, $tradingBot, $decryptMethod, $user) {
    try {
        // Decrypt API token
        $apiToken = $decryptMethod->invoke($tradingBot, $user['id'], $user['encrypted_api_token']);
        
        // Create Deriv API instance
        $derivApi = new App\Services\DerivAPI($apiToken, null, (string)$user['id']);
        
        echo "Monitoring transactions for user {$user['id']}...\n";
        
        // Subscribe to transaction stream
        $response = $derivApi->sendRequest('transaction', [
            'subscribe' => 1
        ]);
        
        if (!isset($response['subscription'])) {
            throw new Exception("Failed to subscribe to transaction stream");
        }
        
        echo "  Subscribed to transaction stream for user {$user['id']}\n";
        
        // Listen for transactions
        while (true) {
            try {
                $message = $derivApi->waitForMessage();
                
                if (isset($message['transaction'])) {
                    processTransaction($db, $message['transaction'], $user['id']);
                }
                
            } catch (Exception $e) {
                echo "  Error processing message: " . $e->getMessage() . "\n";
                sleep(5); // Wait before retrying
            }
        }
        
        $derivApi->close();
        
    } catch (Exception $e) {
        echo "Error monitoring user {$user['id']}: " . $e->getMessage() . "\n";
    }
}

// Main monitoring loop
if ($daemon) {
    echo "Starting daemon mode...\n";
    
    while (true) {
        $users = getActiveUsers($db, $userId);
        
        if (empty($users)) {
            echo "No active users found. Waiting...\n";
            sleep(30);
            continue;
        }
        
        foreach ($users as $user) {
            monitorUserTransactions($db, $tradingBot, $decryptMethod, $user);
        }
        
        echo "Cycle completed. Waiting 60 seconds...\n";
        sleep(60);
    }
} else {
    // Single run mode
    $users = getActiveUsers($db, $userId);
    
    if (empty($users)) {
        echo "No active users found.\n";
        exit(0);
    }
    
    foreach ($users as $user) {
        monitorUserTransactions($db, $tradingBot, $decryptMethod, $user);
    }
    
    echo "Monitoring complete.\n";
}
