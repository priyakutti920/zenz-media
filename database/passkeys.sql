-- ============================================================================
-- Passkey / WebAuthn Credentials Table Schema
-- Supports Biometrics (Fingerprint, Face ID, Windows Hello, PIN, Security Keys)
-- Compatible with employees and admin accounts
-- ============================================================================

CREATE TABLE IF NOT EXISTS `passkey_credentials` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_type` ENUM('employee', 'admin') NOT NULL DEFAULT 'employee',
    `user_id` INT NOT NULL,
    `credential_id` VARCHAR(255) NOT NULL,
    `public_key` TEXT NOT NULL,
    `user_handle` VARCHAR(64) NULL,
    `sign_count` BIGINT UNSIGNED DEFAULT 0,
    `transports` VARCHAR(255) NULL,
    `device_name` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` TIMESTAMP NULL,
    UNIQUE KEY `unique_credential_id` (`credential_id`),
    INDEX `idx_user_type_id` (`user_type`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
