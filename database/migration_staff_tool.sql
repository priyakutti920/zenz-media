-- ============================================================================
-- STAFF MANAGEMENT TOOL — DATABASE MIGRATION SCRIPT
-- Modules: Work Mode, Geofenced Location Attendance, Daily Work Summaries, RBAC
-- Database: qaschyan_po
-- ============================================================================

-- 1. Employee Work Mode (On-site vs Work From Home)
ALTER TABLE `employees` 
ADD COLUMN IF NOT EXISTS `work_mode` ENUM('on_site', 'wfh') NOT NULL DEFAULT 'on_site' AFTER `status`;

-- 2. Attendance Table: Daily Work Summaries & Geolocation Coordinates
ALTER TABLE `attendance`
ADD COLUMN IF NOT EXISTS `work_summary` TEXT NULL AFTER `remarks`,
ADD COLUMN IF NOT EXISTS `completed_tasks` TEXT NULL AFTER `work_summary`,
ADD COLUMN IF NOT EXISTS `pending_tasks` TEXT NULL AFTER `completed_tasks`,
ADD COLUMN IF NOT EXISTS `check_in_lat` DECIMAL(10, 8) NULL AFTER `pending_tasks`,
ADD COLUMN IF NOT EXISTS `check_in_lng` DECIMAL(11, 8) NULL AFTER `check_in_lat`,
ADD COLUMN IF NOT EXISTS `check_in_dist` INT NULL AFTER `check_in_lng`;

-- 3. Daily Work Summaries Relational Table
CREATE TABLE IF NOT EXISTS `daily_work_summaries` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `attendance_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `work_summary` TEXT NOT NULL,
    `completed_tasks` TEXT NOT NULL,
    `pending_tasks` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_attendance` (`attendance_id`),
    KEY `idx_employee_date` (`employee_id`, `date`),
    FOREIGN KEY (`attendance_id`) REFERENCES `attendance`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Site Settings: Office Location & Geofence Configuration
ALTER TABLE `site_settings`
ADD COLUMN IF NOT EXISTS `office_name` VARCHAR(150) NOT NULL DEFAULT 'Cloud Aspire Headquarters',
ADD COLUMN IF NOT EXISTS `office_address` VARCHAR(255) NULL,
ADD COLUMN IF NOT EXISTS `office_latitude` DECIMAL(10, 8) NULL DEFAULT 11.94160000,
ADD COLUMN IF NOT EXISTS `office_longitude` DECIMAL(11, 8) NULL DEFAULT 79.80830000,
ADD COLUMN IF NOT EXISTS `office_radius_meters` INT NOT NULL DEFAULT 100,
ADD COLUMN IF NOT EXISTS `location_attendance_enabled` TINYINT(1) NOT NULL DEFAULT 1;

-- 5. Role-Based Access Control (RBAC) Architecture
CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `is_system` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `slug` VARCHAR(100) NOT NULL UNIQUE,
    `module` VARCHAR(50) NOT NULL,
    `description` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id` INT NOT NULL,
    `permission_id` INT NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_roles` (
    `admin_id` INT NOT NULL,
    `role_id` INT NOT NULL,
    PRIMARY KEY (`admin_id`, `role_id`),
    FOREIGN KEY (`admin_id`) REFERENCES `admin`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
