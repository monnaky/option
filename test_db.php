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
        echo "\n=== Pending Trades ===\n";
        $trades = $db->query("
            SELECT id, user_id, contract_id, status, asset, direction, stake, timestamp 
            FROM trades 
            WHERE status = 'pending' 
            ORDER BY timestamp ASC 
            LIMIT 5
        ");
        
        foreach ($trades as $trade) {
            echo "ID:{$trade['id']} | User:{$trade['user_id']} | Contract:{$trade['contract_id']} | " .
                 "Asset:{$trade['asset']} | Direction:{$trade['direction']} | " .
                 "Stake:\${$trade['stake']} | Time:{$trade['timestamp']}\n";
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