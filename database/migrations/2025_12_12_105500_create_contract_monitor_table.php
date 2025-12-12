<?php

use App\Utils\DatabaseHelper;

class CreateContractMonitorTable
{
    public function up()
    {
        $db = Database::getInstance();
        
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
        
        $db->execute($sql);
        
        // Add any indexes that might be missing
        $db->execute("CREATE INDEX IF NOT EXISTS idx_contract_status ON contract_monitor(contract_id, status)");
        
        error_log("Contract monitor table created/verified");
    }
    
    public function down()
    {
        $db = Database::getInstance();
        $db->execute("DROP TABLE IF EXISTS contract_monitor");
    }
}

// Run migration
$migration = new CreateContractMonitorTable();
$migration->up();
