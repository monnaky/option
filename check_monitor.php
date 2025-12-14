<?php
require __DIR__ . '/bootstrap.php';

use App\Services\Database;

// Change this to the contract you want to check
$contractId = 301669547808;

$db = Database::getInstance();

$row = $db->queryOne(
    "SELECT * FROM contract_monitor WHERE contract_id = :cid",
    ['cid' => $contractId]
);

if ($row) {
    echo "Found contract_monitor row:\n";
    print_r($row);
} else {
    echo "No contract_monitor row for contract_id = {$contractId}\n";
}