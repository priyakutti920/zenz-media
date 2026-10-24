-- ============================================================================
-- GPS ATTENDANCE ROLLBACK SCRIPT
-- Database: qaschyan_po
-- Reverts check-out geolocation fields and unique constraint
-- ============================================================================

-- 1. Remove Check-Out Geolocation Columns from Attendance Table
ALTER TABLE `attendance`
DROP COLUMN IF EXISTS `check_out_dist`,
DROP COLUMN IF EXISTS `check_out_lng`,
DROP COLUMN IF EXISTS `check_out_lat`;

-- 2. Drop Index from Attendance Table
ALTER TABLE `attendance`
DROP KEY IF EXISTS `idx_emp_checkout`;

-- 3. Drop Unique Constraint from Daily Work Summaries
ALTER TABLE `daily_work_summaries`
DROP KEY IF EXISTS `unique_attendance_summary`;
