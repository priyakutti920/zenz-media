-- ============================================================================
-- STAFF MANAGEMENT TOOL — DATABASE ROLLBACK SCRIPT
-- Warning: Rollback removes RBAC and new auxiliary columns.
-- Existing attendance, employee core data, and shifts are safely preserved.
-- ============================================================================

-- 1. Drop RBAC tables
DROP TABLE IF EXISTS `admin_roles`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;

-- 2. Drop Daily Work Summaries table
DROP TABLE IF EXISTS `daily_work_summaries`;

-- 3. Remove Attendance extra columns (if needed)
ALTER TABLE `attendance`
DROP COLUMN IF EXISTS `check_in_dist`,
DROP COLUMN IF EXISTS `check_in_lng`,
DROP COLUMN IF EXISTS `check_in_lat`,
DROP COLUMN IF EXISTS `pending_tasks`,
DROP COLUMN IF EXISTS `completed_tasks`,
DROP COLUMN IF EXISTS `work_summary`;

-- 4. Remove Site Settings location columns (if needed)
ALTER TABLE `site_settings`
DROP COLUMN IF EXISTS `location_attendance_enabled`,
DROP COLUMN IF EXISTS `office_radius_meters`,
DROP COLUMN IF EXISTS `office_longitude`,
DROP COLUMN IF EXISTS `office_latitude`,
DROP COLUMN IF EXISTS `office_address`,
DROP COLUMN IF EXISTS `office_name`;

-- 5. Remove Employee Work Mode column (if needed)
ALTER TABLE `employees`
DROP COLUMN IF EXISTS `work_mode`;
