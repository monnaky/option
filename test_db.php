<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Database Connection Test ===\n\n";

try {
    // Load configuration and bootstrap
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/app/bootstrap.php';
    
    // Get database instance
    $db = \App\Services\Database::getInstance();
    echo "✓ Database connection successful\n";
    
    // Test basic query
    $result = $db->queryValue("SELECT 'Database connection test successful' as message");
    echo "✓ Test query: " . $result . "\n";
    
    // Check pending trades
    $pendingCount = $db->queryValue("SELECT COUNT(*) as count FROM trades WHERE status = 'pending'");
    echo "✓ Pending trades: " . $pendingCount . "\n";
    
    // If there are pending trades, show some details
    if ($pendingCount > 0) {
        echo "\n=== Pending Trades (first 5) ===\n";
        $pendingTrades = $db->query("
            SELECT id, user_id, contract_id, status, timestamp, asset, direction, stake 
            FROM trades 
            WHERE status = 'pending' 
            ORDER BY timestamp ASC 
            LIMIT 5
        ");
        
        foreach ($pendingTrades as $trade) {
            echo "ID: " . $trade['id'] . 
                 " | User: " . $trade['user_id'] .
                 " | Contract: " . $trade['contract_id'] .
                 " | Asset: " . $trade['asset'] .
                 " | Direction: " . $trade['direction'] .
                 " | Stake: " . $trade['stake'] .
                 " | Time: " . $trade['timestamp'] . "\n";
        }
    }
    
    // Check contract_monitor table
    echo "\n=== Contract Monitor Status ===\n";
    $monitorCount = $db->queryValue("SELECT COUNT(*) FROM contract_monitor");
    echo "Total records in contract_monitor: " . $monitorCount . "\n";
    
    // Show recent monitor entries if any
    if ($monitorCount > 0) {
        $recentMonitors = $db->query("
            SELECT id, user_id, contract_id, status, retry_count, 
                   last_checked_at, created_at, updated_at
            FROM contract_monitor 
            ORDER BY updated_at DESC 
            LIMIT 3
        ");
        
        echo "\nRecent monitor entries:\n";
        foreach ($recentMonitors as $monitor) {
            echo "ID: " . $monitor['id'] . 
                 " | User: " . $monitor['user_id'] .
                 " | Contract: " . $monitor['contract_id'] .
                 " | Status: " . $monitor['status'] .
                 " | Retries: " . $monitor['retry_count'] .
                 " | Last Checked: " . ($monitor['last_checked_at'] ?: 'Never') . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if (strpos($e->getMessage(), 'Access denied') !== false) {
        echo "\nCheck your database credentials in config.php\n";
    }
    exit(1);
}

echo "\n=== Test completed successfully ===\n";