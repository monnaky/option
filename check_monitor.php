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
$contractId = 301670261928;

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