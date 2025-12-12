<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Backfill contract_monitor for pending trades
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';

set_time_limit(300); // 5 minutes max execution time

$db = \App\Config\Database::getInstance();

try {
    // Start transaction
    $db->beginTransaction();
    
    echo "=== Starting Contract Monitor Backfill ===\n";
    echo "Fetching pending trades not in contract_monitor...\n";
    
    // Get all pending trades not in contract_monitor
    $trades = $db->query("
        SELECT t.id, t.contract_id, t.user_id, t.timestamp, t.asset, t.direction
        FROM trades t
        LEFT JOIN contract_monitor cm ON t.contract_id = cm.contract_id
        WHERE t.status = 'pending' 
        AND cm.id IS NULL
        ORDER BY t.timestamp ASC
    ");
    
    $total = count($trades);
    
    if ($total === 0) {
        echo "No pending trades found that need to be added to the monitor.\n";
        exit(0);
    }
    
    echo "Found {$total} pending trades to process.\n\n";
    
    $processed = 0;
    $success = 0;
    $errors = 0;
    
    foreach ($trades as $index => $trade) {
        $processed++;
        $progress = round(($processed / $total) * 100, 1);
        
        echo "[{$processed}/{$total}, {$progress}%] Adding contract {$trade['contract_id']} (Trade ID: {$trade['id']})... ";
        
        try {
            $result = $db->insert('contract_monitor', [
                'contract_id' => $trade['contract_id'],
                'trade_id' => $trade['id'],
                'user_id' => $trade['user_id'],
                'status' => 'pending',
                'retry_count' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            if ($result) {
                echo "✓ Added\n";
                $success++;
            } else {
                echo "✗ Failed (Unknown error)\n";
                $errors++;
            }
            
            // Commit every 100 records to avoid large transactions
            if ($processed % 100 === 0) {
                $db->commit();
                $db->beginTransaction();
            }
            
        } catch (Exception $e) {
            // If it's a duplicate entry error, just log and continue
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                echo "⚠ Already exists\n";
                $success++; // Count as success since it's already there
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }
    
    // Final commit
    $db->commit();
    
    echo "\n=== Backfill Complete ===\n";
    echo "Total processed: {$processed}\n";
    echo "Successfully added: {$success}\n";
    echo "Errors: {$errors}\n";
    
} catch (Exception $e) {
    // Rollback on any error
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "\n=== Error ===\n";
    echo "A fatal error occurred: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}