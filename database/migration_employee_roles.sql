-- ============================================================================
-- EMPLOYEE ROLE-BASED ACCESS CONTROL (RBAC) MIGRATION
-- Adds role_id foreign key column to employees table referencing roles(id)
-- ============================================================================

ALTER TABLE `employees`
ADD COLUMN IF NOT EXISTS `role_id` INT(11) NULL DEFAULT NULL AFTER `department`;

-- Add foreign key constraint to link with existing roles and RBAC system
ALTER TABLE `employees`
ADD CONSTRAINT `fk_employees_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
