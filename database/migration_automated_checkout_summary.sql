-- ============================================================================
-- DATABASE MIGRATION: AUTOMATED SHIFT CHECKOUT WITH EMAIL-BASED DAILY WORK SUMMARY
-- Target Database: qaschyan_staging (and production when approved)
-- Execution Mode: Idempotent (Safe to run multiple times)
-- Timezone Standard: Asia/Kolkata (IST, UTC+5:30)
-- ============================================================================

-- 1. Modify attendance table to support summary_pending and scheduled auto checkout
ALTER TABLE `attendance` MODIFY COLUMN `attendance_status` VARCHAR(50) DEFAULT 'present';
ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `checkout_type` VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER `closed_by_system`;
ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `scheduled_checkout` TIME DEFAULT NULL AFTER `checkout_type`;
ALTER TABLE `attendance` ADD COLUMN IF NOT EXISTS `summary_submitted_at` DATETIME DEFAULT NULL AFTER `scheduled_checkout`;
ALTER TABLE `attendance` ADD INDEX IF NOT EXISTS `idx_attendance_checkout_type` (`checkout_type`);
ALTER TABLE `attendance` ADD INDEX IF NOT EXISTS `idx_attendance_status` (`attendance_status`);

-- 2. Add additional notes and submission source to daily_work_summaries
ALTER TABLE `daily_work_summaries` ADD COLUMN IF NOT EXISTS `additional_notes` TEXT DEFAULT NULL AFTER `pending_tasks`;
ALTER TABLE `daily_work_summaries` ADD COLUMN IF NOT EXISTS `submission_source` VARCHAR(30) NOT NULL DEFAULT 'modal' AFTER `additional_notes`;
ALTER TABLE `daily_work_summaries` ADD COLUMN IF NOT EXISTS `submitted_at` DATETIME DEFAULT NULL AFTER `submission_source`;

-- 3. Create attendance_summary_tokens Table for Secure Email Summary Workflow
CREATE TABLE IF NOT EXISTS `attendance_summary_tokens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `attendance_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `shift_date` DATE NOT NULL,
    `scheduled_checkout_time` TIME NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL,
    `status` ENUM('pending', 'completed', 'expired', 'cancelled') NOT NULL DEFAULT 'pending',
    `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
    `email_sent_at` DATETIME DEFAULT NULL,
    `email_delivery_status` ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `email_error_message` VARCHAR(255) DEFAULT NULL,
    `email_attempts` INT NOT NULL DEFAULT 0,
    `last_reminder_sent_at` DATETIME DEFAULT NULL,
    `reminder_count` INT NOT NULL DEFAULT 0,
    `expires_at` DATETIME NOT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_attendance_token` (`attendance_id`),
    INDEX `idx_token_hash` (`token_hash`),
    INDEX `idx_employee_date` (`employee_id`, `shift_date`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
