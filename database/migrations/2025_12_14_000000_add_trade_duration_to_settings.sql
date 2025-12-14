-- ============================================================================
-- Migration: 2025_12_14_000000_add_trade_duration_to_settings.sql
-- Description: Add configurable trade duration (ticks/seconds) to user settings
-- ============================================================================

ALTER TABLE settings
    ADD COLUMN trade_duration INT UNSIGNED NOT NULL DEFAULT 5 AFTER stop_limit,
    ADD COLUMN trade_duration_unit ENUM('t','s') NOT NULL DEFAULT 't' AFTER trade_duration;
