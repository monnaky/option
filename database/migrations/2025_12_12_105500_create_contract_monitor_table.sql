-- ============================================================================
-- Contract Monitor Table
-- Migration: 2025_12_12_105500_create_contract_monitor_table.sql
-- Description: Creates a table for monitoring contract statuses
-- ============================================================================

CREATE TABLE IF NOT EXISTS contract_monitor (
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
    KEY idx_created_at (created_at),
    KEY idx_contract_status (contract_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add any additional indexes or constraints if needed
-- CREATE INDEX IF NOT EXISTS idx_contract_status ON contract_monitor(contract_id, status);

-- Add foreign key constraint (uncomment and modify if needed)
-- ALTER TABLE contract_monitor
-- ADD CONSTRAINT fk_contract_monitor_user
-- FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- ============================================================================
-- Migration complete
-- ============================================================================