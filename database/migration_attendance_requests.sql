-- ============================================================================
-- ATTENDANCE REQUESTS, WFH APPROVAL & SCHEDULED TIME CHECK-IN MIGRATION
-- Database: qaschyan_po
-- Modules: Location Mismatch Handling, WFH Approval, Scheduled Check-in, RBAC
-- ============================================================================

-- 1. Create Attendance Requests Table
CREATE TABLE IF NOT EXISTS `attendance_requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `attendance_date` DATE NOT NULL,
    `requested_work_mode` ENUM('wfh', 'on_site_exception') NOT NULL DEFAULT 'wfh',
    `reason` TEXT NOT NULL,
    `supporting_details` TEXT NULL,
    `requested_check_in_time` TIME NOT NULL,
    `approved_check_in_time` TIME NULL,
    `status` ENUM('pending', 'approved', 'rejected', 'cancelled', 'checked_in', 'expired') NOT NULL DEFAULT 'pending',
    `approver_id` INT NULL,
    `decision_timestamp` DATETIME NULL,
    `rejection_reason` TEXT NULL,
    `actual_check_in` TIME NULL,
    `submitted_lat` DECIMAL(10, 8) NULL,
    `submitted_lng` DECIMAL(11, 8) NULL,
    `submitted_dist` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_emp_date` (`employee_id`, `attendance_date`),
    KEY `idx_status` (`status`),
    KEY `idx_date_status` (`attendance_date`, `status`),
    CONSTRAINT `fk_att_req_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_att_req_approver` FOREIGN KEY (`approver_id`) REFERENCES `admin` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Add WFH Scheduled Window Parameters to Site Settings
ALTER TABLE `site_settings`
ADD COLUMN IF NOT EXISTS `wfh_early_window_minutes` INT NOT NULL DEFAULT 15 AFTER `location_attendance_enabled`,
ADD COLUMN IF NOT EXISTS `wfh_grace_period_minutes` INT NOT NULL DEFAULT 15 AFTER `wfh_early_window_minutes`,
ADD COLUMN IF NOT EXISTS `wfh_late_threshold_minutes` INT NOT NULL DEFAULT 30 AFTER `wfh_grace_period_minutes`,
ADD COLUMN IF NOT EXISTS `wfh_auto_reschedule_allowed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `wfh_late_threshold_minutes`;

-- 3. Register Granular RBAC Permission for WFH & Exception Approvals
INSERT IGNORE INTO `permissions` (`name`, `slug`, `module`, `description`) 
VALUES ('Attendance: Approve / Reject WFH & Exceptions', 'attendance.approve_requests', 'Attendance', 'Authorize or reject employee work from home and on-site location exception requests');

-- Assign permission to HR Operations Manager (role_id 2)
INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 2, id FROM `permissions` WHERE `slug` = 'attendance.approve_requests';
