-- ============================================================================
-- ROLLBACK SCRIPT: ATTENDANCE REQUESTS & WFH APPROVAL
-- Database: qaschyan_po
-- Reverts attendance requests table, site settings columns, and RBAC permissions
-- ============================================================================

-- 1. Remove Role Permission Assignments
DELETE FROM `role_permissions` 
WHERE `permission_id` IN (SELECT `id` FROM `permissions` WHERE `slug` = 'attendance.approve_requests');

-- 2. Remove Permission Slug
DELETE FROM `permissions` WHERE `slug` = 'attendance.approve_requests';

-- 3. Remove Site Settings Columns
ALTER TABLE `site_settings`
DROP COLUMN IF EXISTS `wfh_auto_reschedule_allowed`,
DROP COLUMN IF EXISTS `wfh_late_threshold_minutes`,
DROP COLUMN IF EXISTS `wfh_grace_period_minutes`,
DROP COLUMN IF EXISTS `wfh_early_window_minutes`;

-- 4. Drop Attendance Requests Table
DROP TABLE IF EXISTS `attendance_requests`;
