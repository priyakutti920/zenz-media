-- ============================================================================
-- GPS ATTENDANCE & SUMMARY REFINEMENT MIGRATION
-- Database: qaschyan_po
-- Adds check-out geolocation fields, indexes, and summary uniqueness
-- ============================================================================

-- 1. Add Check-Out Geolocation Columns to Attendance Table
ALTER TABLE `attendance`
ADD COLUMN IF NOT EXISTS `check_out_lat` DECIMAL(10, 8) NULL AFTER `check_in_dist`,
ADD COLUMN IF NOT EXISTS `check_out_lng` DECIMAL(11, 8) NULL AFTER `check_out_lat`,
ADD COLUMN IF NOT EXISTS `check_out_dist` INT NULL AFTER `check_out_lng`;

-- 2. Ensure Single Daily Work Summary Per Attendance Session
-- Adding unique constraint ensures ON DUPLICATE KEY UPDATE functions correctly
ALTER TABLE `daily_work_summaries`
ADD UNIQUE KEY IF NOT EXISTS `unique_attendance_summary` (`attendance_id`);

-- 3. Add Index for Fast Employee Attendance Session Lookups
ALTER TABLE `attendance`
ADD KEY IF NOT EXISTS `idx_emp_checkout` (`employee_id`, `check_out`);
