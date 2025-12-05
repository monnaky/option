-- ============================================================================
-- Migration: 002_add_admin_column.sql
-- Description: Add is_admin column to users table for role-based access control
-- ============================================================================

-- Add is_admin column to users table
ALTER TABLE users 
ADD COLUMN is_admin TINYINT(1) DEFAULT 0 NOT NULL AFTER is_active;

-- Add index for faster admin lookups
ALTER TABLE users 
ADD INDEX idx_is_admin (is_admin);

-- Update existing users to ensure they are not admins (safety measure)
UPDATE users SET is_admin = 0 WHERE is_admin IS NULL;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================

