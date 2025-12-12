<?php

/**
 * Debug Contract API
 * 
 * This script helps diagnose why trades are being cancelled
 * instead of properly resolved as 'won' or 'lost'
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';

use App\Config\Database;
use App\Services\TradingBotService;

echo "=== Contract Debug Tool ===\n";

$db = Database::getInstance();

// Get a recent pending trade to test
$trade = $db->queryOne(
    "SELECT t.*, u.encrypted_api_token 
     FROM trades t
     INNER JOIN users u ON t.user_id = u.id
     WHERE t.status = 'pending'
     AND t.timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
     ORDER BY t.timestamp DESC
     LIMIT 1"
);

if (!$trade) {
    echo "No recent pending trades found. Testing with an older trade...\n";
    
    $trade = $db->queryOne(
        "SELECT t.*, u.encrypted_api_token 
         FROM trades t
         INNER JOIN users u ON t.user_id = u.id
         WHERE t.status = 'pending'
         ORDER BY t.timestamp DESC
         LIMIT 1"
    );
}

if (!$trade) {
    echo "No pending trades found at all.\n";
    exit(0);
}

echo "Testing trade:\n";
echo "  Trade ID: {$trade['id']}\n";
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
    echo "API token decrypted successfully\n";
    
    // Create DerivAPI instance
    $derivApi = new DerivAPI($apiToken, null, (string)$trade['user_id']);
    
    echo "Getting contract info for contract {$trade['contract_id']}...\n";
    $contractInfo = $derivApi->getContractInfo($trade['contract_id']);
    
    echo "Contract info received:\n";
    echo "  Status: " . ($contractInfo['status'] ?? 'N/A') . "\n";
    echo "  Profit: " . ($contractInfo['profit'] ?? 'N/A') . "\n";
    echo "  Sell Price: " . ($contractInfo['sell_price'] ?? 'N/A') . "\n";
    echo "  Buy Price: " . ($contractInfo['buy_price'] ?? 'N/A') . "\n";
    echo "  Entry Spot: " . ($contractInfo['entry_spot'] ?? 'N/A') . "\n";
    echo "  Exit Spot: " . ($contractInfo['exit_spot'] ?? 'N/A') . "\n";
    
    if (isset($contractInfo['profit'])) {
        $profit = (float)$contractInfo['profit'];
        $status = $profit > 0 ? 'won' : 'lost';
        echo "\nRESULT: This trade should be marked as '{$status}' with profit: {$profit}\n";
    } else {
        echo "\nRESULT: No profit data available - this is why trades are being cancelled!\n";
    }
    
    $derivApi->close();
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "This exception is causing trades to be marked as 'cancelled'!\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Debug Complete ===\n";