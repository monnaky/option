-- ============================================================================
-- VTM Option MySQL Database Schema
-- Migration: 001_initial_schema.sql
-- Description: Complete database schema for VTM Option trading bot
-- ============================================================================

-- Create database (uncomment if needed)
-- CREATE DATABASE IF NOT EXISTS vtmoption CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE vtmoption;

-- ============================================================================
-- USERS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    encrypted_api_token TEXT,
    api_token_created_at DATETIME,
    api_token_last_used DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SETTINGS TABLE (User Trading Settings)
-- ============================================================================
CREATE TABLE IF NOT EXISTS settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    stake DECIMAL(10, 2) NOT NULL DEFAULT 1.00,
    target DECIMAL(10, 2) NOT NULL DEFAULT 100.00,
    stop_limit DECIMAL(10, 2) NOT NULL DEFAULT 50.00,
    is_bot_active BOOLEAN DEFAULT FALSE,
    last_active_at DATETIME,
    daily_profit DECIMAL(10, 2) DEFAULT 0.00,
    daily_loss DECIMAL(10, 2) DEFAULT 0.00,
    reset_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_is_bot_active (is_bot_active),
    INDEX idx_reset_date (reset_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TRADING SESSIONS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS trading_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_id VARCHAR(255) NOT NULL UNIQUE,
    state ENUM('initializing', 'active', 'paused', 'stopping', 'stopped', 'error', 'recovering', 'expired') 
        NOT NULL DEFAULT 'initializing',
    
    -- Risk Parameters
    stake DECIMAL(10, 2) NOT NULL,
    target DECIMAL(10, 2) NOT NULL,
    stop_limit DECIMAL(10, 2) NOT NULL,
    max_active_contracts INT UNSIGNED DEFAULT 50,
    max_daily_trades INT UNSIGNED DEFAULT 0,
    
    -- Session Statistics
    total_trades INT UNSIGNED DEFAULT 0,
    successful_trades INT UNSIGNED DEFAULT 0,
    failed_trades INT UNSIGNED DEFAULT 0,
    total_profit DECIMAL(10, 2) DEFAULT 0.00,
    total_loss DECIMAL(10, 2) DEFAULT 0.00,
    daily_profit DECIMAL(10, 2) DEFAULT 0.00,
    daily_loss DECIMAL(10, 2) DEFAULT 0.00,
    daily_trade_count INT UNSIGNED DEFAULT 0,
    
    -- Session Activity Tracking
    start_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    end_time DATETIME,
    duration INT UNSIGNED,
    
    -- API Token Information
    api_token_hash VARCHAR(255),
    api_token_created_at DATETIME,
    
    -- Error Tracking
    error_count INT UNSIGNED DEFAULT 0,
    consecutive_errors INT UNSIGNED DEFAULT 0,
    last_error TEXT,
    last_error_time DATETIME,
    
    -- Session Limits
    max_error_count INT UNSIGNED DEFAULT 5,
    max_inactive_time INT UNSIGNED DEFAULT 1800000, -- 30 minutes in milliseconds
    reset_date DATE NOT NULL,
    
    -- Session Metadata
    started_by VARCHAR(255) NOT NULL,
    stopped_by VARCHAR(255),
    stop_reason TEXT,
    metadata JSON,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_state (state),
    INDEX idx_start_time (start_time),
    INDEX idx_last_activity_time (last_activity_time),
    INDEX idx_reset_date (reset_date),
    INDEX idx_user_state (user_id, state),
    INDEX idx_user_start_time (user_id, start_time),
    INDEX idx_state_activity (state, last_activity_time),
    INDEX idx_user_state_activity (user_id, state, last_activity_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TRADES TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS trades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_id INT UNSIGNED,
    trade_id VARCHAR(255) NOT NULL UNIQUE,
    contract_id VARCHAR(255),
    asset VARCHAR(50) NOT NULL,
    direction ENUM('RISE', 'FALL') NOT NULL,
    stake DECIMAL(10, 2) NOT NULL,
    payout DECIMAL(10, 2),
    profit DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    status ENUM('pending', 'won', 'lost', 'cancelled') NOT NULL DEFAULT 'pending',
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME,
    duration INT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES trading_sessions(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_session_id (session_id),
    INDEX idx_trade_id (trade_id),
    INDEX idx_status (status),
    INDEX idx_timestamp (timestamp),
    INDEX idx_asset (asset),
    INDEX idx_direction (direction),
    INDEX idx_user_timestamp (user_id, timestamp),
    INDEX idx_session_timestamp (session_id, timestamp),
    INDEX idx_user_session_timestamp (user_id, session_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SIGNALS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS signals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    signal_type ENUM('RISE', 'FALL') NOT NULL,
    asset VARCHAR(50),
    raw_text TEXT NOT NULL,
    source ENUM('api', 'file', 'manual') NOT NULL DEFAULT 'api',
    source_ip VARCHAR(45),
    processed BOOLEAN NOT NULL DEFAULT FALSE,
    total_users INT UNSIGNED DEFAULT 0,
    successful_executions INT UNSIGNED DEFAULT 0,
    failed_executions INT UNSIGNED DEFAULT 0,
    execution_time INT UNSIGNED,
    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_signal_type (signal_type),
    INDEX idx_asset (asset),
    INDEX idx_source (source),
    INDEX idx_processed (processed),
    INDEX idx_timestamp (timestamp),
    INDEX idx_processed_timestamp (processed, timestamp),
    INDEX idx_signal_type_timestamp (signal_type, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SESSIONS TABLE (JWT Token Sessions)
-- ============================================================================
CREATE TABLE IF NOT EXISTS sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token VARCHAR(500) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_token (token(255)),
    INDEX idx_expires_at (expires_at),
    INDEX idx_user_expires (user_id, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ADMIN USERS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- ADMIN ACTIVITY LOGS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    INDEX idx_admin_user_id (admin_user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_admin_created (admin_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- API CALL LOGS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS api_call_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    status_code INT,
    response_time INT,
    request_data JSON,
    response_data JSON,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_endpoint (endpoint),
    INDEX idx_method (method),
    INDEX idx_status_code (status_code),
    INDEX idx_created_at (created_at),
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SYSTEM SETTINGS TABLE
-- ============================================================================
CREATE TABLE IF NOT EXISTS system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INITIAL DATA / DEFAULTS
-- ============================================================================

-- Insert default system settings
INSERT INTO system_settings (setting_key, setting_value, setting_type, description) VALUES
('app_name', 'VTM Option', 'string', 'Application name'),
('app_version', '1.0.0', 'string', 'Application version'),
('trading_enabled', 'true', 'boolean', 'Global trading enable/disable'),
('max_stake', '1000', 'number', 'Maximum stake amount allowed'),
('min_stake', '1', 'number', 'Minimum stake amount allowed'),
('default_stake', '1', 'number', 'Default stake amount'),
('default_target', '100', 'number', 'Default profit target'),
('default_stop_limit', '50', 'number', 'Default stop loss limit')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- ============================================================================
-- VIEWS (Optional - for easier queries)
-- ============================================================================

-- View for user trading statistics
CREATE OR REPLACE VIEW user_trading_stats AS
SELECT 
    u.id AS user_id,
    u.email,
    COUNT(DISTINCT t.id) AS total_trades,
    COUNT(DISTINCT CASE WHEN t.status = 'won' THEN t.id END) AS won_trades,
    COUNT(DISTINCT CASE WHEN t.status = 'lost' THEN t.id END) AS lost_trades,
    SUM(CASE WHEN t.status = 'won' THEN t.profit ELSE 0 END) AS total_profit,
    SUM(CASE WHEN t.status = 'lost' THEN ABS(t.profit) ELSE 0 END) AS total_loss,
    SUM(t.profit) AS net_profit,
    s.is_bot_active,
    s.daily_profit,
    s.daily_loss
FROM users u
LEFT JOIN trades t ON u.id = t.user_id
LEFT JOIN settings s ON u.id = s.user_id
GROUP BY u.id, u.email, s.is_bot_active, s.daily_profit, s.daily_loss;

-- View for active trading sessions
CREATE OR REPLACE VIEW active_trading_sessions AS
SELECT 
    ts.id,
    ts.user_id,
    u.email,
    ts.session_id,
    ts.state,
    ts.stake,
    ts.target,
    ts.stop_limit,
    ts.total_trades,
    ts.successful_trades,
    ts.failed_trades,
    ts.daily_profit,
    ts.daily_loss,
    ts.start_time,
    ts.last_activity_time,
    TIMESTAMPDIFF(SECOND, ts.start_time, COALESCE(ts.end_time, NOW())) AS duration_seconds
FROM trading_sessions ts
INNER JOIN users u ON ts.user_id = u.id
WHERE ts.state IN ('initializing', 'active', 'recovering');

-- ============================================================================
-- STORED PROCEDURES (Optional - for complex operations)
-- ============================================================================

DELIMITER //

-- Procedure to reset daily stats for all users
CREATE PROCEDURE IF NOT EXISTS ResetDailyStats()
BEGIN
    UPDATE settings 
    SET daily_profit = 0, 
        daily_loss = 0,
        reset_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    WHERE reset_date <= CURDATE();
    
    UPDATE trading_sessions
    SET daily_profit = 0,
        daily_loss = 0,
        daily_trade_count = 0,
        reset_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    WHERE reset_date <= CURDATE();
END //

-- Procedure to cleanup expired sessions
CREATE PROCEDURE IF NOT EXISTS CleanupExpiredSessions()
BEGIN
    DELETE FROM sessions WHERE expires_at < NOW();
    
    UPDATE trading_sessions 
    SET state = 'expired' 
    WHERE state IN ('active', 'paused') 
    AND last_activity_time < DATE_SUB(NOW(), INTERVAL 30 MINUTE);
END //

DELIMITER ;

-- ============================================================================
-- TRIGGERS (Optional - for automatic operations)
-- ============================================================================

DELIMITER //

-- Trigger to update trade statistics in trading session
CREATE TRIGGER IF NOT EXISTS update_session_stats_after_trade
AFTER UPDATE ON trades
FOR EACH ROW
BEGIN
    IF NEW.status != OLD.status AND NEW.session_id IS NOT NULL THEN
        IF NEW.status = 'won' THEN
            UPDATE trading_sessions
            SET successful_trades = successful_trades + 1,
                total_profit = total_profit + NEW.profit,
                daily_profit = daily_profit + NEW.profit,
                total_trades = total_trades + 1,
                daily_trade_count = daily_trade_count + 1
            WHERE id = NEW.session_id;
        ELSEIF NEW.status = 'lost' THEN
            UPDATE trading_sessions
            SET failed_trades = failed_trades + 1,
                total_loss = total_loss + ABS(NEW.profit),
                daily_loss = daily_loss + ABS(NEW.profit),
                total_trades = total_trades + 1,
                daily_trade_count = daily_trade_count + 1
            WHERE id = NEW.session_id;
        END IF;
    END IF;
END //

DELIMITER ;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================

