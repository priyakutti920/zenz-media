-- ============================================================================
-- DATABASE ROLLBACK: AUTOMATED SHIFT CHECKOUT WITH EMAIL-BASED DAILY WORK SUMMARY
-- Target Database: qaschyan_staging (and production when approved)
-- Execution Mode: Idempotent (Safe to run multiple times)
-- ============================================================================

DROP TABLE IF EXISTS `attendance_summary_tokens`;

ALTER TABLE `daily_work_summaries` DROP COLUMN IF EXISTS `submitted_at`;
ALTER TABLE `daily_work_summaries` DROP COLUMN IF EXISTS `submission_source`;
ALTER TABLE `daily_work_summaries` DROP COLUMN IF EXISTS `additional_notes`;

ALTER TABLE `attendance` DROP INDEX IF EXISTS `idx_attendance_status`;
ALTER TABLE `attendance` DROP INDEX IF EXISTS `idx_attendance_checkout_type`;
ALTER TABLE `attendance` DROP COLUMN IF EXISTS `summary_submitted_at`;
ALTER TABLE `attendance` DROP COLUMN IF EXISTS `scheduled_checkout`;
ALTER TABLE `attendance` DROP COLUMN IF EXISTS `checkout_type`;
