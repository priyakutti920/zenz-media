-- ============================================================================
-- DATABASE MIGRATION: ATTENDANCE PORTAL AUTO-CLOSURE & SHIFT TIMING ENFORCEMENT
-- Target Database: qaschyan_staging (and production when approved)
-- Execution Mode: Idempotent (Safe to run multiple times)
-- Timezone Standard: Asia/Kolkata (IST, UTC+5:30)
-- ============================================================================

-- 1. Add HR Review & Auto-Closure Tracking Columns to attendance Table
ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `is_flagged_for_review` TINYINT(1) NOT NULL DEFAULT 0 AFTER `check_out_dist`;
ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `flag_reason` VARCHAR(255) DEFAULT NULL AFTER `is_flagged_for_review`;
ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `closed_by_system` TINYINT(1) NOT NULL DEFAULT 0 AFTER `flag_reason`;
ALTER TABLE `attendance` ADD INDEX IF NOT EXISTS `idx_attendance_flagged` (`is_flagged_for_review`, `date`);

-- 2. Add Employee Assigned Shift Column to employees Table
ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `shift_id` INT(11) DEFAULT NULL AFTER `work_mode`;
ALTER TABLE `employees` ADD INDEX IF NOT EXISTS `idx_employees_shift` (`shift_id`);

-- 3. Add Custom / Extended Approved Checkout Time to attendance_requests Table
ALTER TABLE `attendance_requests` ADD COLUMN IF NOT EXISTS `approved_check_out_time` TIME DEFAULT NULL AFTER `approved_check_in_time`;
