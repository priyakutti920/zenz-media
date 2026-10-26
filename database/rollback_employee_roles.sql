-- ============================================================================
-- ROLLBACK EMPLOYEE ROLE-BASED ACCESS CONTROL (RBAC) MIGRATION
-- Removes fk_employees_role constraint and role_id column from employees table
-- ============================================================================

ALTER TABLE `employees` DROP FOREIGN KEY IF EXISTS `fk_employees_role`;
ALTER TABLE `employees` DROP COLUMN IF EXISTS `role_id`;
