<?php
if (php_sapi_name() === 'cli') {
    define('DISABLE_SESSION', true);
}

if (!@include(__DIR__ . '/config.php')) {
    throw new Exception('Failed to load config.php');
}

if (!@include(__DIR__ . '/app/bootstrap.php')) {
    throw new Exception('Failed to load app/bootstrap.php');
}

use App\Config\Database;

// Change this to the contract you want to check
$contractId = 301669547808;

$db = Database::getInstance();

$tradeRow = $db->queryOne(
    "SELECT id, user_id, status, trade_id, contract_id, timestamp, closed_at, profit, payout FROM trades WHERE contract_id = :cid",
    ['cid' => $contractId]
);

$monitorRow = $db->queryOne(
    "SELECT * FROM contract_monitor WHERE contract_id = :cid",
    ['cid' => $contractId]
);

$tradeCount = $db->queryValue(
    "SELECT COUNT(*) FROM trades WHERE contract_id = :cid",
    ['cid' => $contractId]
);

$monitorCount = $db->queryValue(
    "SELECT COUNT(*) FROM contract_monitor WHERE contract_id = :cid",
    ['cid' => $contractId]
);

echo "Contract ID: {$contractId}\n";
echo "Trades rows found: {$tradeCount}\n";
echo "Monitor rows found: {$monitorCount}\n\n";

if ($tradeRow) {
    echo "Trade row:\n";
    print_r($tradeRow);
} else {
    echo "No trades row for contract_id = {$contractId}\n";
}

echo "\n";

if ($monitorRow) {
    echo "Found contract_monitor row:\n";
    print_r($monitorRow);
} else {
    echo "No contract_monitor row for contract_id = {$contractId}\n";
}