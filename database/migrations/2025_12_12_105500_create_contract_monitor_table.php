<?php

// Use the correct database class from your application
use App\Services\Database;

class CreateContractMonitorTable
{
    private $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    public function up()
    {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS contract_monitor (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                contract_id BIGINT NOT NULL,
                trade_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                retry_count INT NOT NULL DEFAULT 0,
                last_checked_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_contract (contract_id),
                KEY idx_user (user_id),
                KEY idx_status (status),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            
            $this->db->execute($sql);
            
            // Add any indexes that might be missing
            $this->db->execute("CREATE INDEX IF NOT EXISTS idx_contract_status ON contract_monitor(contract_id, status)");
            
            error_log("Contract monitor table created/verified");
            echo "Migration completed successfully.\n";
        } catch (Exception $e) {
            error_log("Migration failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function down()
    {
        try {
            $this->db->execute("DROP TABLE IF EXISTS contract_monitor");
            error_log("Dropped contract_monitor table");
            echo "Rollback completed successfully.\n";
        } catch (Exception $e) {
            error_log("Rollback failed: " . $e->getMessage());
            throw $e;
        }
    }
}

// Run migration
try {
    // Include your application's bootstrap file
    require_once __DIR__ . '/../../bootstrap/app.php'; // Adjust path as needed
    
    $migration = new CreateContractMonitorTable();
    $migration->up();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}