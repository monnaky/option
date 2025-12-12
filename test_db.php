<?php
// Disable session for CLI
if (php_sapi_name() === 'cli') {
    define('DISABLE_SESSION', true);
}

// Start output buffering
ob_start();

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) return false;
    throw new ErrorException($errstr, $errno, 0, $errfile, $errline);
});

set_exception_handler(function($e) {
    while (ob_get_level() > 0) ob_end_clean();
    echo "\nUncaught Exception: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
    exit(1);
});

try {
    echo "=== Starting Database Connection Test ===\n\n";
    
    // Load configuration
    echo "1. Loading configuration...\n";
    if (!@include(__DIR__ . '/config.php')) {
        throw new Exception("Failed to load config.php");
    }
    
    // Load bootstrap
    echo "2. Loading bootstrap...\n";
    if (!@include(__DIR__ . '/app/bootstrap.php')) {
        throw new Exception("Failed to load bootstrap.php");
    }
    
    // Test database connection
    echo "3. Testing database connection...\n";
    $db = \App\Config\Database::getInstance();
    $db->query("SELECT 1");
    echo "✓ Database connection successful\n";
    
    // Test query
    echo "4. Running test query...\n";
    $result = $db->queryValue("SELECT 'Test successful' as message");
    echo "✓ Test query: " . $result . "\n";
    
    // Check pending trades
    echo "5. Checking pending trades...\n";
    $pendingCount = $db->queryValue("SELECT COUNT(*) as count FROM trades WHERE status = 'pending'");
    echo "✓ Pending trades: " . $pendingCount . "\n";
    
    if ($pendingCount > 0) {
        echo "\n=== Pending Trades (First 5) ===\n";
        $trades = $db->query("
            SELECT t.id, t.user_id, t.contract_id, t.status, t.asset, t.direction, 
                   t.stake, t.timestamp, cm.status as monitor_status,
                   cm.last_checked_at, cm.retry_count
            FROM trades t
            LEFT JOIN contract_monitor cm ON t.contract_id = cm.contract_id
            WHERE t.status = 'pending' 
            ORDER BY t.timestamp ASC 
            LIMIT 5
        ");
        
        foreach ($trades as $trade) {
            echo "Trade ID: {$trade['id']} | ";
            echo "User: {$trade['user_id']} | ";
            echo "Contract: {$trade['contract_id']} | ";
            echo "Asset: {$trade['asset']} | ";
            echo "Direction: {$trade['direction']} | ";
            echo "Stake: \${$trade['stake']} | ";
            echo "Time: {$trade['timestamp']} | ";
            echo "Monitor Status: " . ($trade['monitor_status'] ?? 'Not tracked') . " | ";
            echo "Last Checked: " . ($trade['last_checked_at'] ?? 'Never') . " | ";
            echo "Retry Count: " . ($trade['retry_count'] ?? '0') . "\n";
        }
    }
    
    // Check contract_monitor table status
    echo "\n6. Checking contract_monitor table...\n";
    $monitorCount = $db->queryValue("SELECT COUNT(*) FROM contract_monitor");
    echo "✓ Total records in contract_monitor: " . $monitorCount . "\n";
    
    if ($monitorCount > 0) {
        $recentMonitors = $db->query("
            SELECT cm.id, cm.contract_id, cm.status, cm.retry_count, 
                   cm.last_checked_at, cm.created_at, cm.updated_at,
                   t.status as trade_status, t.timestamp as trade_timestamp
            FROM contract_monitor cm
            LEFT JOIN trades t ON cm.contract_id = t.contract_id
            ORDER BY cm.updated_at DESC 
            LIMIT 3
        ");
        
        echo "\n=== Recent Monitor Entries ===\n";
        foreach ($recentMonitors as $monitor) {
            echo "Monitor ID: {$monitor['id']} | ";
            echo "Contract: {$monitor['contract_id']} | ";
            echo "Status: {$monitor['status']} | ";
            echo "Retries: {$monitor['retry_count']} | ";
            echo "Last Checked: " . ($monitor['last_checked_at'] ?: 'Never') . " | ";
            echo "Trade Status: " . ($monitor['trade_status'] ?? 'N/A') . " | ";
            echo "Trade Time: " . ($monitor['trade_timestamp'] ?? 'N/A') . "\n";
        }
    }
    
    echo "\n=== Test completed successfully ===\n";
    
} catch (Throwable $e) {
    throw $e; // Let the exception handler deal with it
} finally {
    $output = ob_get_clean();
    echo $output;
}

exit(0);