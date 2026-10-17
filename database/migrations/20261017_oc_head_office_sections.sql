-- Ocampo's Head Office directory section: Ocampos Production. Idempotent.
-- A section is scoped by (company_group, site_type): it only applies to Company Group OC at a HEAD_OFFICE site,
-- so it never appears for Sw / Urban Farm or for an Ocampo's retail branch.
-- Inventory Management is a shared department (Sw Head Office uses it too) and Goldsmith is a position under Ocampos Production,
-- so neither is a section. Departments are NOT changed; the only position change is Goldsmith's department (step 5).
-- employees.directory_section_id is the only new employee column; nothing is assigned automatically.

-- 1. Section master.
CREATE TABLE IF NOT EXISTS directory_sections (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(40) NOT NULL,
  name varchar(120) NOT NULL,
  company_group varchar(8) NOT NULL,
  site_type varchar(30) NOT NULL DEFAULT 'HEAD_OFFICE',
  active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 100,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  updated_at datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_directory_sections_code (code),
  KEY idx_directory_sections_scope (company_group, site_type, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Seed (re-running updates the same row, never duplicates it).
INSERT INTO directory_sections (code, name, company_group, site_type, active, sort_order) VALUES
  ('OC_HO_OCAMPOS_PRODUCTION', 'Ocampos Production', 'OC', 'HEAD_OFFICE', 1, 10)
ON DUPLICATE KEY UPDATE name = VALUES(name), company_group = VALUES(company_group), site_type = VALUES(site_type), active = 1, sort_order = VALUES(sort_order);

-- 3. employees.directory_section_id (nullable).
SET @has := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'directory_section_id');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD COLUMN directory_section_id BIGINT UNSIGNED NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'employees' AND index_name = 'idx_employees_directory_section');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD KEY idx_employees_directory_section (directory_section_id)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'employees' AND constraint_name = 'fk_employees_directory_section');
SET @ddl := IF(@has = 0, 'ALTER TABLE employees ADD CONSTRAINT fk_employees_directory_section FOREIGN KEY (directory_section_id) REFERENCES directory_sections (id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Retire the two sections an earlier version of this migration seeded. Unused ones are removed; one that an employee
--    already holds is only deactivated (kept for that employee's history).
UPDATE directory_sections SET active = 0 WHERE code IN ('OC_HO_INVENTORY_MANAGEMENT', 'OC_HO_GOLDSMITH');
DELETE FROM directory_sections
 WHERE code IN ('OC_HO_INVENTORY_MANAGEMENT', 'OC_HO_GOLDSMITH')
   AND id NOT IN (SELECT directory_section_id FROM employees WHERE directory_section_id IS NOT NULL);

-- 5. The Goldsmith position belongs under Production (was Inventory Management). Only moves it while it is still under
--    Inventory Management, so a later manual choice by HR is never overwritten. Employees are not touched.
UPDATE positions
   SET department_id = (SELECT id FROM departments WHERE code = 'PRODUCTION')
 WHERE name = 'Goldsmith'
   AND department_id = (SELECT id FROM departments WHERE code = 'INVENTORY_MANAGEMENT')
   AND EXISTS (SELECT 1 FROM departments WHERE code = 'PRODUCTION');

-- Verification
SELECT code, name, company_group, site_type, active FROM directory_sections ORDER BY sort_order;
SELECT p.name position_name, d.name department_name FROM positions p LEFT JOIN departments d ON d.id = p.department_id WHERE p.name = 'Goldsmith';
