-- ============================================================================
-- VTM Option Database Migration
-- Migration: 002_fix_admin_authentication.sql
-- Description: Fix admin authentication system
-- ============================================================================

-- Add is_admin column to users table for simple admin flag
ALTER TABLE users 
ADD COLUMN IF NOT EXISTS is_admin BOOLEAN DEFAULT FALSE AFTER is_active,
ADD INDEX idx_is_admin (is_admin);

-- Add user_id to admin_users table to link admin accounts to regular users
ALTER TABLE admin_users 
ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED AFTER id,
ADD CONSTRAINT fk_admin_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Add index for faster lookups
ALTER TABLE admin_users
ADD INDEX IF NOT EXISTS idx_user_id (user_id);

-- Make username and email nullable since we'll use user_id as primary link
ALTER TABLE admin_users 
MODIFY COLUMN username VARCHAR(100) NULL,
MODIFY COLUMN email VARCHAR(255) NULL,
MODIFY COLUMN password VARCHAR(255) NULL;

-- ============================================================================
-- END OF MIGRATION
-- ============================================================================
