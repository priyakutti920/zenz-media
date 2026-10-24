-- ============================================================================
-- DATABASE ROLLBACK: ATTENDANCE PORTAL AUTO-CLOSURE & SHIFT TIMING ENFORCEMENT
-- Target Database: qaschyan_staging (and production when approved)
-- Execution Mode: Idempotent (Safe to run multiple times)
-- ============================================================================

-- 1. Drop index and columns from attendance table
ALTER TABLE `attendance` DROP INDEX IF EXISTS `idx_attendance_flagged`;
ALTER TABLE `attendance` DROP COLUMN IF EXISTS `closed_by_system`;
ALTER TABLE `attendance` DROP COLUMN IF EXISTS `flag_reason`;
ALTER TABLE `attendance` DROP COLUMN IF EXISTS `is_flagged_for_review`;

-- 2. Drop index and column from employees table
ALTER TABLE `employees` DROP INDEX IF EXISTS `idx_employees_shift`;
ALTER TABLE `employees` DROP COLUMN IF EXISTS `shift_id`;

-- 3. Drop column from attendance_requests table
ALTER TABLE `attendance_requests` DROP COLUMN IF EXISTS `approved_check_out_time`;
