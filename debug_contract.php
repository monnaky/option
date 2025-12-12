<?php

/**
 * Debug Contract API
 * 
 * This script helps diagnose why trades are being cancelled
 * instead of properly resolved as 'won' or 'lost'
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/services/DerivAPI.php';

use App\Config\Database;
use App\Services\TradingBotService;
use App\Services\DerivAPI;

echo "=== Contract Debug Tool ===\n";

$db = Database::getInstance();

// Get a recent pending trade to test
$trade = $db->queryOne(
    "SELECT t.*, u.encrypted_api_token 
     FROM trades t
     INNER JOIN users u ON t.user_id = u.id
     WHERE t.status = 'pending'
     ORDER BY t.timestamp DESC
     LIMIT 1"
);

if (!$trade) {
    echo "No pending trades found. Trying with a completed trade...\n";
    $trade = $db->queryOne(
        "SELECT t.*, u.encrypted_api_token 
         FROM trades t
         INNER JOIN users u ON t.user_id = u.id
         WHERE t.status = 'cancelled'
         ORDER BY t.timestamp DESC
         LIMIT 1"
    );
}

if (!$trade) {
    echo "No trades found to test.\n";
    exit(0);
}

echo "Testing trade:\n";
echo "  Trade ID: {$trade['id']}\n";
echo "  Status: {$trade['status']}\n";
echo "  Contract ID: {$trade['contract_id']}\n";
echo "  User ID: {$trade['user_id']}\n";
echo "  Asset: {$trade['asset']}\n";
echo "  Direction: {$trade['direction']}\n";
echo "  Timestamp: {$trade['timestamp']}\n";
echo "  Age: " . (time() - strtotime($trade['timestamp'])) . " seconds\n\n";

try {
    $tradingBot = TradingBotService::getInstance();
    
    echo "Attempting to decrypt API token...\n";
    $reflection = new ReflectionClass($tradingBot);
    $decryptMethod = $reflection->getMethod('decryptUserApiToken');
    $decryptMethod->setAccessible(true);
    
    $apiToken = $decryptMethod->invoke($tradingBot, $trade['user_id'], $trade['encrypted_api_token']);
    echo "✓ API token decrypted successfully\n";
    echo "  Token length: " . strlen($apiToken) . " characters\n";
    
    // Create DerivAPI instance with debug mode
    echo "\n=== Creating DerivAPI Instance ===\n";
    echo "  Initializing with user ID: " . $trade['user_id'] . "\n";
    
    // Check if DerivAPI class exists
    if (!class_exists('DerivAPI')) {
        throw new Exception("DerivAPI class not found. Check if the class is properly included.");
    }
    
    // Try to create the API instance
    try {
        $derivApi = new DerivAPI($apiToken, null, (string)$trade['user_id']);
        echo "✓ DerivAPI instance created successfully\n";
        
        // Check for debug method
        if (method_exists($derivApi, 'setDebug')) {
            $derivApi->setDebug(true);
            echo "✓ Debug mode enabled\n";
        }
        
        // Test API connectivity
        echo "\n=== Testing API Connectivity ===\n";
        try {
            $ping = $derivApi->ping();
            echo "✓ API Ping successful: " . json_encode($ping) . "\n";
        } catch (Exception $e) {
            echo "⚠️ API Ping failed: " . $e->getMessage() . "\n";
            echo "  This might indicate network or authentication issues.\n";
        }
        
        // Try to get contract info
        echo "\n=== Fetching Contract Info ===\n";
        echo "  Contract ID: " . $trade['contract_id'] . "\n";
        echo "  Contract ID type: " . gettype($trade['contract_id']) . "\n";
        
        // Ensure contract_id is treated as string
        $contractId = (string)$trade['contract_id'];
        
        try {
            $contractInfo = $derivApi->getContractInfo($contractId);
            
            if (empty($contractInfo)) {
                throw new Exception("Empty response from getContractInfo()");
            }
            
            echo "\n=== Contract Info ===\n";
            echo "Raw response: " . print_r($contractInfo, true) . "\n";
            
            if (isset($contractInfo['error'])) {
                throw new Exception("API Error: " . print_r($contractInfo['error'], true));
            }
            
            echo "  Status: " . ($contractInfo['status'] ?? 'N/A') . "\n";
            echo "  Profit: " . ($contractInfo['profit'] ?? 'N/A') . "\n";
            echo "  Sell Price: " . ($contractInfo['sell_price'] ?? 'N/A') . "\n";
            echo "  Buy Price: " . ($contractInfo['buy_price'] ?? 'N/A') . "\n";
            echo "  Entry Spot: " . ($contractInfo['entry_spot'] ?? 'N/A') . "\n";
            echo "  Exit Spot: " . ($contractInfo['exit_spot'] ?? 'N/A') . "\n";
            
            if (isset($contractInfo['profit'])) {
                $profit = (float)$contractInfo['profit'];
                $status = $profit > 0 ? 'won' : 'lost';
                echo "\n✓ RESULT: This trade should be marked as '{$status}' with profit: {$profit}\n";
            } else {
                echo "\n⚠️ WARNING: No profit data available - this is why trades are being cancelled!\n";
            }
            
        } catch (Exception $e) {
            echo "\n❌ ERROR in getContractInfo():\n";
            echo "  Message: " . $e->getMessage() . "\n";
            echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
            echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        $derivApi->close();
        
    } catch (Exception $e) {
        echo "\n❌ ERROR creating DerivAPI instance:\n";
        echo "  Message: " . $e->getMessage() . "\n";
        echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
        throw $e; // Re-throw to be caught by outer catch
    }
    
} catch (Exception $e) {
    echo "\n❌ FATAL ERROR:\n";
    echo "  Message: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "  Stack trace:\n" . $e->getTraceAsString() . "\n";
}

// Check contract_monitor status
$monitor = $db->queryOne(
    "SELECT * FROM contract_monitor 
     WHERE contract_id = :contract_id 
     ORDER BY id DESC LIMIT 1",
    ['contract_id' => $trade['contract_id']]
);

if ($monitor) {
    echo "\n=== Contract Monitor Status ===\n";
    echo "  Monitor ID: {$monitor['id']}\n";
    echo "  Status: {$monitor['status']}\n";
    echo "  Retry Count: {$monitor['retry_count']}\n";
    echo "  Last Checked: {$monitor['last_checked_at']}\n";
    echo "  Created At: {$monitor['created_at']}\n";
}

echo "\n=== Debug Complete ===\n";