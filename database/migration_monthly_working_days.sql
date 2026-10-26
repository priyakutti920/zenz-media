-- ============================================================================
-- Migration: Monthly Working Days & Company Calendar
-- ============================================================================

CREATE TABLE IF NOT EXISTS `monthly_working_days` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `date` DATE NOT NULL UNIQUE,
    `year` INT NOT NULL,
    `month` INT NOT NULL,
    `day_type` ENUM('working_day', 'weekly_off', 'holiday') NOT NULL DEFAULT 'working_day',
    `title` VARCHAR(100) NULL,
    `notes` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_year_month` (`year`, `month`),
    UNIQUE KEY `uniq_working_day_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
