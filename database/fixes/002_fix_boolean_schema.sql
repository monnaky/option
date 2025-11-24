-- ============================================================================
-- Emergency Production Fix: Database Schema Corrections
-- Migration: 002_fix_boolean_schema.sql
-- Description: Fix is_bot_active column and existing data issues
-- ============================================================================

-- Fix 1: Update existing NULL or empty is_bot_active values
UPDATE settings 
SET is_bot_active = 0 
WHERE is_bot_active IS NULL OR is_bot_active = '';

-- Fix 2: Ensure column has proper definition and default
ALTER TABLE settings 
MODIFY COLUMN is_bot_active TINYINT(1) DEFAULT 0 NOT NULL;

-- Fix 3: Verify all boolean columns are properly defined
-- (This is a check - no changes if already correct)
-- Check with: DESCRIBE settings;

-- Fix 4: Update any invalid datetime values in reset_date
-- Set to tomorrow if NULL or invalid
UPDATE settings 
SET reset_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
WHERE reset_date IS NULL OR reset_date = '0000-00-00';

-- Fix 5: Ensure reset_date has proper default
ALTER TABLE settings 
MODIFY COLUMN reset_date DATE NOT NULL DEFAULT (DATE_ADD(CURDATE(), INTERVAL 1 DAY));

-- ============================================================================
-- Verification Queries (run these to verify fixes)
-- ============================================================================

-- Check for any remaining NULL or invalid values
SELECT 
    id, 
    user_id, 
    is_bot_active, 
    reset_date 
FROM settings 
WHERE is_bot_active IS NULL 
   OR is_bot_active = '' 
   OR reset_date IS NULL 
   OR reset_date = '0000-00-00';

-- Should return 0 rows

-- Check column definitions
DESCRIBE settings;

-- Verify is_bot_active is TINYINT(1) with DEFAULT 0 NOT NULL

-- ============================================================================
-- END OF FIX
-- ============================================================================

