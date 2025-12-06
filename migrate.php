<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';

use App\Config\Database;

echo "Starting database migration...\n";

try {
    $db = Database::getInstance();
    
    // Read the SQL file
    $sqlFile = __DIR__ . '/database/migrations/create_jobs_table.sql';
    if (!file_exists($sqlFile)) {
        die("Error: Migration file not found at $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Execute
    // Note: Database::execute might not handle multiple statements if PDO is not configured for it,
    // but our SQL file is a single CREATE TABLE statement.
    $db->execute($sql);
    
    echo "Migration successful! 'jobs' table created/verified.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
