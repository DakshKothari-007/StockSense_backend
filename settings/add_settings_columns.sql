-- Migration: Add inventory, reorder, calculation and security columns to user_settings
-- Idempotent: Safe to execute multiple times

ALTER TABLE `user_settings`
  ADD COLUMN IF NOT EXISTS `lead_time_days` int(10) UNSIGNED NOT NULL DEFAULT 2,
  ADD COLUMN IF NOT EXISTS `safety_days` int(10) UNSIGNED NOT NULL DEFAULT 3,
  ADD COLUMN IF NOT EXISTS `cover_days` int(10) UNSIGNED NOT NULL DEFAULT 7,
  ADD COLUMN IF NOT EXISTS `min_history_days` int(10) UNSIGNED NOT NULL DEFAULT 30,
  ADD COLUMN IF NOT EXISTS `slow_moving_threshold_days` int(10) UNSIGNED NOT NULL DEFAULT 60,
  ADD COLUMN IF NOT EXISTS `import_mode` enum('ADD','REPLACE') NOT NULL DEFAULT 'ADD',
  ADD COLUMN IF NOT EXISTS `timezone` varchar(50) NOT NULL DEFAULT 'Asia/Kolkata',
  ADD COLUMN IF NOT EXISTS `failed_password_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `password_locked_until` datetime DEFAULT NULL;
