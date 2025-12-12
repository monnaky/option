<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/bootstrap.php';

use App\Config\Database;

echo "Starting contract_monitor table migration...\n";

try {
    $db = Database::getInstance();
    
    // Read the SQL file
    $sqlFile = __DIR__ . '/migrations/2025_12_12_105500_create_contract_monitor_table.sql';
    if (!file_exists($sqlFile)) {
        die("Error: Migration file not found at $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Execute the migration
    $db->execute($sql);
    
    echo "Migration successful! 'contract_monitor' table created/verified.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}