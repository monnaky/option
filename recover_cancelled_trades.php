<?php
/**
 * Recover Cancelled Trades
 * 
 * This script checks cancelled trades against the Deriv API
 * and updates their status if they were actually executed.
 * 
 * Usage: php recover_cancelled_trades.php [--dry-run] [--limit=N]
 *   --dry-run  Show what would be updated without making changes
 *   --limit=N  Process only N trades (default: 50)
 */

// Disable session for CLI
if (php_sapi_name() === 'cli') {
    define('DISABLE_SESSION', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';

use App\Config\Database;
use App\Services\TradingBotService;

// Parse command line arguments
$options = getopt('', ['dry-run', 'limit::']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 50;

$db = Database::getInstance();
$tradingBot = TradingBotService::getInstance();

// Get reflection for private methods
$reflection = new ReflectionClass($tradingBot);
$decryptMethod = $reflection->getMethod('decryptUserApiToken');
$decryptMethod->setAccessible(true);

echo "=== Recover Cancelled Trades ===\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE (database will be updated)") . "\n";
echo "Max trades to process: " . $limit . "\n\n";

// Get cancelled trades with valid contract IDs
$cancelledTrades = $db->query("
    SELECT t.*, u.encrypted_api_token 
    FROM trades t
    INNER JOIN users u ON t.user_id = u.id
    WHERE t.status = 'cancelled'
    AND t.contract_id IS NOT NULL
    AND t.contract_id != ''
    AND u.encrypted_api_token IS NOT NULL
    AND u.encrypted_api_token != ''
    ORDER BY t.id DESC
    LIMIT " . (int)$limit
);

if (empty($cancelledTrades)) {
    echo "No cancelled trades found to recover.\n";
    exit(0);
}

echo "Found " . count($cancelledTrades) . " cancelled trades to check...\n\n";

$recovered = 0;
$errors = 0;

foreach ($cancelledTrades as $trade) {
    echo "Processing trade #{$trade['id']} (Contract: {$trade['contract_id']})... ";
    
    try {
        // Decrypt API token
        $apiToken = $decryptMethod->invoke($tradingBot, $trade['user_id'], $trade['encrypted_api_token']);
        
        // Create Deriv API instance
        $derivApi = new DerivAPI($apiToken, null, (string)$trade['user_id']);
        
        try {
            // Get contract info
            $contractInfo = $derivApi->getContractInfo((int)$trade['contract_id']);
            
            if (empty($contractInfo) || !isset($contractInfo['status'])) {
                throw new Exception("Invalid contract info received");
            }
            
            // Check if contract was actually executed
            if (in_array($contractInfo['status'], ['sold', 'won', 'lost'])) {
                $profit = (float)($contractInfo['profit'] ?? 0);
                $newStatus = $profit > 0 ? 'won' : 'lost';
                $payout = $contractInfo['sell_price'] ?? 0;
                
                echo "✅ Recovering as '{$newStatus}' (Profit: {$profit})\n";
                
                if (!$dryRun) {
                    // Update trade status
                    $db->execute("
                        UPDATE trades 
                        SET status = :status,
                            profit = :profit,
                            payout = :payout,
                            closed_at = IFNULL(closed_at, NOW())
                        WHERE id = :id
                    ", [
                        'status' => $newStatus,
                        'profit' => $profit,
                        'payout' => $payout,
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
                        'status' => $newStatus,
                        'contract_id' => $trade['contract_id']
                    ]);
                }
                
                $recovered++;
            } else {
                echo "⏩ Still {$contractInfo['status']}, skipping\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Error checking contract: " . $e->getMessage() . "\n";
            $errors++;
        }
        
        $derivApi->close();
        
    } catch (Exception $e) {
        echo "❌ Error processing trade: " . $e->getMessage() . "\n";
        $errors++;
    }
    
    // Add a small delay to avoid rate limiting
    usleep(200000); // 200ms
}

echo "\n=== Recovery Complete ===\n";
echo "Trades processed: " . count($cancelledTrades) . "\n";
echo "Successfully recovered: {$recovered}\n";
echo "Errors: {$errors}\n";
echo "Dry run: " . ($dryRun ? "Yes" : "No") . "\n\n";

if ($dryRun && $recovered > 0) {
    echo "Note: This was a dry run. Run without --dry-run to apply changes.\n";
}