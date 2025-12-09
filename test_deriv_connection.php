<?php
/**
 * Test Deriv API Connection
 * 
 * Tests WebSocket connectivity to Deriv API
 */

require_once __DIR__ . '/app/autoload.php';

use App\Config\Database;
use App\Services\DerivAPI;
use App\Services\EncryptionService;

echo "=== Deriv API Connection Test ===\n\n";

// Get User 7's API token
$db = Database::getInstance();
$user = $db->queryOne(
    "SELECT id, email, encrypted_api_token FROM users WHERE id = 7"
);

if (!$user || empty($user['encrypted_api_token'])) {
    echo "✗ User 7 not found or has no API token\n";
    exit(1);
}

echo "Testing connection for User {$user['id']} ({$user['email']})\n\n";

try {
    // Decrypt token
    echo "1. Decrypting API token...\n";
    list($apiToken, $usedLegacy) = EncryptionService::decryptWithLegacySupport($user['encrypted_api_token']);
    echo "   ✓ Token decrypted (length: " . strlen($apiToken) . ")\n";
    if ($usedLegacy) {
        echo "   ⚠️  Using legacy encryption key\n";
    }
    echo "\n";
    
    // Create DerivAPI instance
    echo "2. Creating DerivAPI instance...\n";
    $derivApi = new DerivAPI($apiToken, null, '7');
    echo "   ✓ DerivAPI instance created\n\n";
    
    // Test authorization
    echo "3. Testing authorization...\n";
    $startTime = microtime(true);
    $authData = $derivApi->authorize();
    $authTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "   ✓ Authorization successful ({$authTime}ms)\n";
    echo "   - LoginID: {$authData['loginid']}\n";
    echo "   - Balance: {$authData['balance']} {$authData['currency']}\n";
    echo "   - Email: {$authData['email']}\n\n";
    
    // Test balance
    echo "4. Testing balance fetch...\n";
    $startTime = microtime(true);
    $balance = $derivApi->getBalance();
    $balanceTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "   ✓ Balance retrieved ({$balanceTime}ms): {$balance}\n\n";
    
    // Test available assets
    echo "5. Testing available assets...\n";
    $startTime = microtime(true);
    $assets = $derivApi->getAvailableAssets();
    $assetsTime = round((microtime(true) - $startTime) * 1000, 2);
    echo "   ✓ Assets retrieved ({$assetsTime}ms)\n";
    echo "   - Available assets: " . implode(', ', array_slice($assets, 0, 10)) . "\n";
    echo "   - Total assets: " . count($assets) . "\n\n";
    
    // Test contracts_for (this is where it's failing)
    echo "6. Testing contracts_for (R_75)...\n";
    $startTime = microtime(true);
    try {
        $contracts = $derivApi->getContractsFor('R_75');
        $contractsTime = round((microtime(true) - $startTime) * 1000, 2);
        echo "   ✓ Contracts retrieved ({$contractsTime}ms)\n";
        echo "   - Available contracts: " . count($contracts) . "\n";
        
        if (!empty($contracts)) {
            $firstContract = $contracts[0];
            echo "   - First contract type: " . ($firstContract['contract_type'] ?? 'N/A') . "\n";
            echo "   - Barrier: " . ($firstContract['barrier'] ?? 'N/A') . "\n";
        }
    } catch (Exception $e) {
        $contractsTime = round((microtime(true) - $startTime) * 1000, 2);
        echo "   ✗ FAILED ({$contractsTime}ms)\n";
        echo "   - Error: " . $e->getMessage() . "\n";
        echo "   - This is the same error preventing trades!\n";
        
        // Try with a different symbol
        echo "\n7. Trying with different symbol (R_10)...\n";
        $startTime = microtime(true);
        try {
            $contracts = $derivApi->getContractsFor('R_10');
            $contractsTime = round((microtime(true) - $startTime) * 1000, 2);
            echo "   ✓ Contracts retrieved ({$contractsTime}ms)\n";
            echo "   - R_10 works, but R_75 doesn't\n";
        } catch (Exception $e2) {
            $contractsTime = round((microtime(true) - $startTime) * 1000, 2);
            echo "   ✗ FAILED ({$contractsTime}ms)\n";
            echo "   - Error: " . $e2->getMessage() . "\n";
            echo "   - All symbols failing - WebSocket connection issue\n";
        }
    }
    
    echo "\n";
    
    // Close connection
    echo "8. Closing connection...\n";
    $derivApi->close();
    echo "   ✓ Connection closed\n\n";
    
    echo "=== Test Complete ===\n";
    echo "\nDiagnosis:\n";
    echo "- Authorization: ✓ Working\n";
    echo "- Balance: ✓ Working\n";
    echo "- Assets: ✓ Working\n";
    echo "- Contracts: Check output above\n";
    
} catch (Exception $e) {
    echo "\n✗ Test failed!\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Type: " . get_class($e) . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}
