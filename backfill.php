<?php
// Backfill contract_monitor for pending trades
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';

$db = \App\Config\Database::getInstance();

// Get all pending trades not in contract_monitor
$trades = $db->query("
    SELECT t.id, t.contract_id, t.user_id 
    FROM trades t
    LEFT JOIN contract_monitor cm ON t.contract_id = cm.contract_id
    WHERE t.status = 'pending' AND cm.id IS NULL
");

foreach ($trades as $trade) {
    echo "Adding contract {$trade['contract_id']} to monitor... ";
    $db->insert('contract_monitor', [
        'contract_id' => $trade['contract_id'],
        'user_id' => $trade['user_id'],
        'status' => 'pending',
        'retry_count' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    echo "Done\n";
}

echo "Backfill complete!\n";